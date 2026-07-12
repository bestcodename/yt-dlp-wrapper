<?php

declare(strict_types=1);

namespace App\UsbSetup;

use App\Process\ProcessRunner;
use Closure;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PartitionFormatter
{
    private readonly Closure $waitForPartition;

    /**
     * @param ?Closure(string, int=): void $waitForPartition polls for a partition device node to
     *     appear; defaults to a real file_exists() poll, overridable in tests
     */
    public function __construct(private readonly ProcessRunner $runner, ?Closure $waitForPartition = null)
    {
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

    public function mount(
        string $partition,
        OutputInterface $output,
        string $suffix = '',
        bool $readOnly = false,
    ): string {
        $mount = sys_get_temp_dir().'/usb_setup_'.getmypid().($suffix !== '' ? '_'.$suffix : '');
        if (!self::ensureDirectory($mount, 0700)) {
            throw new RuntimeException("Cannot create mount point {$mount}.");
        }
        [$exit] = $this->runCmd(
            'mount '.($readOnly ? '-o ro ' : '').escapeshellarg($partition).' '.escapeshellarg($mount).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            throw new RuntimeException("Failed to mount {$partition} at {$mount}.");
        }

        return $mount;
    }

    private static function ensureDirectory(string $dir, int $mode = 0755): bool
    {
        return is_dir($dir) || (@mkdir($dir, $mode, true) && is_dir($dir)) || is_dir($dir);
    }

    private function runCmd(string $cmd, bool $passthru = false, ?OutputInterface $output = null): array
    {
        return $this->runner->run(
            $cmd,
            ($passthru && $output !== null) ? static fn(string $chunk) => $output->write($chunk) : null,
            $output !== null ? static fn(string $chunk) => $output->getErrorOutput()->write($chunk) : null,
        );
    }

    public function reformatFat32(
        string $partition,
        OutputInterface $output,
        SymfonyStyle $io,
        string $label = 'VENTOY',
    ): void {
        $io->text("Reformatting $partition as FAT32...");
        ($this->waitForPartition)($partition);

        $exit = 1;
        $partArg = escapeshellarg($partition);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->runCmd("nsenter -t 1 --mount -- umount -f $partArg 2>/dev/null");
            $this->runCmd('fuser -km '.$partArg.' 2>/dev/null');
            $this->runCmd('umount -f '.$partArg.' 2>/dev/null');
            $this->runCmd('udevadm settle 2>/dev/null');
            [$exit] = $this->runCmd(
                'mkfs.fat -F 32 -n '.escapeshellarg($label).' '.escapeshellarg($partition).' 2>&1',
                true,
                $output
            );
            if ($exit === 0) {
                break;
            }
            $io->text("Partition busy, retrying in {$attempt}s...");
            sleep($attempt);
        }

        if ($exit !== 0) {
            throw new RuntimeException(
                "mkfs.fat failed on $partition after retries — the partition is likely still held by the host OS.\n".
                "Unmount it on the HOST (not inside ddev) and try again:\n".
                "  sudo umount $partition"
            );
        }
    }

    public function unmount(string $mount): void
    {
        $this->runCmd('umount '.escapeshellarg($mount).' 2>/dev/null');
        @rmdir($mount);
    }
}
