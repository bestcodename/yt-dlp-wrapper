<?php

declare(strict_types=1);

namespace App\UsbSetup;

use App\Process\ProcessRunner;
use Closure;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class VentoyInstaller
{
    private const WELL_KNOWN_PATHS = [
        '/opt/ventoy/Ventoy2Disk.sh',
        '/usr/share/ventoy/Ventoy2Disk.sh',
        '/usr/lib/ventoy/Ventoy2Disk.sh',
    ];

    private readonly Closure $waitForPartition;

    /**
     * @param ?Closure(string, int=): void $waitForPartition polls for a partition device node to
     *     appear; defaults to a real file_exists() poll, overridable in tests
     * @param list<string> $wellKnownPaths overridable in tests so host machines that happen to
     *     have Ventoy installed at one of these paths don't leak into assertions
     */
    public function __construct(
        private readonly ProcessRunner $runner,
        ?Closure $waitForPartition = null,
        private readonly array $wellKnownPaths = self::WELL_KNOWN_PATHS,
    ) {
        $this->waitForPartition = $waitForPartition ?? self::defaultWaitForPartition(...);
    }

    private static function defaultWaitForPartition(string $part, int $timeoutSec = 10): void
    {
        $deadline = time() + $timeoutSec;
        while (!file_exists($part) && time() < $deadline) {
            usleep(300000);
        }
        if (!file_exists($part)) {
            throw new RuntimeException("Partition {$part} did not appear within {$timeoutSec}s.");
        }
    }

    public function __invoke(
        string $ventoyBin,
        string $device,
        OutputInterface $output,
        SymfonyStyle $io,
        bool $update = false,
    ): void {
        $flag = $update ? '-u' : '-I';
        $io->text($update ? "Updating Ventoy on $device..." : "Installing Ventoy onto $device (MBR mode)...");
        // Ventoy2Disk.sh builds its tool PATH from the caller's CWD, so it must run from its own
        // directory. Its own y/n prompts always get "yes" — this command already double-confirmed,
        // and runCmd closes stdin, which would otherwise EOF-abort the install.
        $ventoyDir = dirname($ventoyBin);
        $cmd = 'cd '.escapeshellarg($ventoyDir).' && yes 2>/dev/null | bash ./'.basename($ventoyBin)
            ." $flag ".escapeshellarg($device);
        [$exit, $out] = $this->runCmd($cmd, true, $output);
        if (self::runFailed($exit, $out)) {
            throw new RuntimeException(
                "Ventoy installation failed (exit {$exit}) — see {$ventoyDir}/log.txt for details."
            );
        }
        $this->runCmd('udevadm settle 2>/dev/null');
        sleep(2);
        foreach ([1, 2] as $n) {
            $part = escapeshellarg(self::partitionPath($device, $n));
            // Unmount from the host mount namespace (pid:host lets nsenter target host PID 1)
            $this->runCmd("nsenter -t 1 --mount -- umount -f $part 2>/dev/null");
            // Also unmount from within the container namespace and kill any holder processes
            $this->runCmd('fuser -km '.$part.' 2>/dev/null');
            $this->runCmd('umount -f '.$part.' 2>/dev/null');
        }
        $this->runCmd('partprobe '.escapeshellarg($device).' 2>/dev/null');
        $this->runCmd('udevadm settle 2>/dev/null');

        // A real Ventoy install always leaves the 32 MiB VTOYEFI partition 2 behind.
        $vtoyefiPartition = self::partitionPath($device, 2);
        try {
            ($this->waitForPartition)($vtoyefiPartition);
        } catch (RuntimeException) {
            throw new RuntimeException(
                "Ventoy reported success but $vtoyefiPartition (VTOYEFI) never appeared — ".
                "installation did not happen. See {$ventoyDir}/log.txt for details."
            );
        }
    }

    private function runCmd(string $cmd, bool $passthru = false, ?OutputInterface $output = null): array
    {
        return $this->runner->run(
            $cmd,
            ($passthru && $output !== null) ? static fn(string $chunk) => $output->write($chunk) : null,
            $output !== null ? static fn(string $chunk) => $output->getErrorOutput()->write($chunk) : null,
        );
    }

    /**
     * Ventoy2Disk.sh swallows the worker's exit code, so exit 0 is not proof of success —
     * known failure messages in the output count as failure too. Pure — unit-testable.
     */
    public static function runFailed(int $exit, string $out): bool
    {
        return $exit !== 0
            || str_contains($out, 'Some tools can not run')
            || str_contains($out, 'does not contain Ventoy');
    }

    /**
     * Kernel partition naming: devices whose name ends in a digit (nvme0n1, mmcblk0, loop0)
     * get a 'p' separator before the partition number; others (sdb) do not. Pure — unit-testable.
     */
    public static function partitionPath(string $device, int $number): string
    {
        return $device.(preg_match('/\d$/', $device) === 1 ? 'p' : '').$number;
    }

    public function findBin(?string $hint): string
    {
        $candidates = array_filter([
            $hint,
            getenv('VENTOY_BIN') ?: null,
            ...$this->wellKnownPaths,
        ]);
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        [$exit, $out] = $this->runCmd('which ventoy 2>/dev/null');
        if ($exit === 0 && trim($out) !== '') {
            return trim($out);
        }
        throw new RuntimeException(
            "Ventoy not found. Install it or pass --ventoy-bin /path/to/Ventoy2Disk.sh.\n".
            '  Download: https://github.com/ventoy/Ventoy/releases'
        );
    }
}
