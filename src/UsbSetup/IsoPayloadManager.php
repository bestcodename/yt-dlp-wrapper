<?php

declare(strict_types=1);

namespace App\UsbSetup;

use App\Process\ProcessRunner;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class IsoPayloadManager
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    /**
     * Estimates the bytes the configuration-path payload will occupy on the stick: ISO plus its
     * persistence image (persistence.dat is only created alongside an ISO), plus all prepared
     * software files. $fileSize is injected (filesize) so the math is pure — unit-testable.
     *
     * @param list<array{source: string, relative: string}> $softwareFiles
     */
    public static function estimateConfigurationPayloadBytes(
        ?string $debianIso,
        int $persistenceMib,
        array $softwareFiles,
        callable $fileSize,
    ): int {
        $total = 0;
        if ($debianIso !== null) {
            $total += (int)$fileSize($debianIso) + $persistenceMib * 1048576;
        }
        foreach ($softwareFiles as $file) {
            $total += (int)$fileSize($file['source']);
        }

        return $total;
    }

    public function copyIso(string $isoPath, string $mountPoint, OutputInterface $output, SymfonyStyle $io): string
    {
        $dest = rtrim($mountPoint, '/').'/'.basename($isoPath);
        $isoSizeMb = filesize($isoPath) / 1048576;
        if ($isoSizeMb > 4090) {
            throw new RuntimeException(
                "ISO exceeds ~4 GiB FAT32 single-file limit ({$isoSizeMb} MiB) — refusing to copy."
            );
        }
        $io->text(sprintf('Copying %s to USB (%d MiB)...', basename($isoPath), (int)round($isoSizeMb)));
        [$exit] = $this->runCmd(
            'cp --no-preserve=all '.escapeshellarg($isoPath).' '.escapeshellarg($dest).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            throw new RuntimeException('Failed to copy ISO to USB.');
        }

        return basename($isoPath);
    }

    private function runCmd(string $cmd, bool $passthru = false, ?OutputInterface $output = null): array
    {
        return $this->runner->run(
            $cmd,
            ($passthru && $output !== null) ? static fn(string $chunk) => $output->write($chunk) : null,
            $output !== null ? static fn(string $chunk) => $output->getErrorOutput()->write($chunk) : null,
        );
    }

    public function createPersistenceFile(
        string $mountPoint,
        int $sizeMib,
        OutputInterface $output,
        SymfonyStyle $io,
    ): string {
        $datFile = rtrim($mountPoint, '/').'/persistence.dat';
        if ($sizeMib > 4090) {
            $io->warning("Persistence size {$sizeMib} MiB exceeds FAT32 limit. Capping at 4090 MiB.");
            $sizeMib = 4090;
        }
        $io->text("Creating persistence file ({$sizeMib} MiB)...");
        [$exit] = $this->runCmd(
            'fallocate -l '.escapeshellarg("{$sizeMib}M").' '.escapeshellarg($datFile).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            $io->warning('fallocate failed, falling back to dd (slower)...');
            [$exit] = $this->runCmd(
                'dd if=/dev/zero of='.escapeshellarg($datFile).' bs=1M count='.$sizeMib.' 2>&1',
                true,
                $output
            );
            if ($exit !== 0) {
                throw new RuntimeException('Failed to create persistence file.');
            }
        }
        $io->text('Formatting persistence file as ext4...');
        [$exit] = $this->runCmd(
            'mkfs.ext4 -L persistence -F '.escapeshellarg($datFile).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            throw new RuntimeException('mkfs.ext4 failed on persistence file.');
        }
        $tmpMount = sys_get_temp_dir().'/persist_'.getmypid();
        if (!self::ensureDirectory($tmpMount, 0700)) {
            throw new RuntimeException("Cannot create temp mount {$tmpMount}.");
        }
        [$exit] = $this->runCmd(
            'mount -o loop '.escapeshellarg($datFile).' '.escapeshellarg($tmpMount).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            @rmdir($tmpMount);
            throw new RuntimeException('Failed to loop-mount persistence file.');
        }
        file_put_contents($tmpMount.'/persistence.conf', "/ union\n");
        $io->text('Written /persistence.conf inside persistence file.');
        $this->runCmd('umount '.escapeshellarg($tmpMount).' 2>/dev/null');
        @rmdir($tmpMount);

        return 'persistence.dat';
    }

    private static function ensureDirectory(string $dir, int $mode = 0755): bool
    {
        return is_dir($dir) || (@mkdir($dir, $mode, true) && is_dir($dir)) || is_dir($dir);
    }

    public function isPersistenceValid(string $mountPoint, int $expectedMib): bool
    {
        $datFile = rtrim($mountPoint, '/').'/persistence.dat';
        if (!is_file($datFile)) {
            return false;
        }
        [$exit, $type] = $this->runCmd('blkid -o value -s TYPE '.escapeshellarg($datFile).' 2>/dev/null');
        if ($exit !== 0 || trim($type) !== 'ext4') {
            return false;
        }
        [$exit, $label] = $this->runCmd('blkid -o value -s LABEL '.escapeshellarg($datFile).' 2>/dev/null');
        if ($exit !== 0 || trim($label) !== 'persistence') {
            return false;
        }

        // Allow ±10 MiB tolerance for filesystem overhead
        return abs((int)(filesize($datFile) / 1048576) - $expectedMib) <= 10;
    }

    public function isoMatchesOnStick(string $mountPoint, string $isoName, string $localIsoPath): bool
    {
        $dest = rtrim($mountPoint, '/').'/'.$isoName;

        return is_file($dest) && is_file($localIsoPath) && filesize($dest) === filesize($localIsoPath);
    }

    public function ventoyJsonHasEntry(string $mountPoint, string $isoName): bool
    {
        $jsonPath = rtrim($mountPoint, '/').'/ventoy/ventoy.json';
        if (!is_file($jsonPath)) {
            return false;
        }
        $data = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            return false;
        }
        foreach ($data['persistence'] ?? [] as $entry) {
            if (($entry['image'] ?? '') === '/'.$isoName) {
                return true;
            }
        }

        return false;
    }

    public function writeVentoyJson(string $mountPoint, string $isoName, string $persistenceDat, SymfonyStyle $io): void
    {
        $ventoyDir = rtrim($mountPoint, '/').'/ventoy';
        if (!self::ensureDirectory($ventoyDir, 0755)) {
            throw new RuntimeException('Cannot create /ventoy directory on USB.');
        }
        $jsonPath = $ventoyDir.'/ventoy.json';
        $existing = [];
        if (is_file($jsonPath)) {
            $existing = json_decode((string)file_get_contents($jsonPath), true) ?? [];
        }
        $existing['persistence'][] = [
            'image' => '/'.$isoName,
            'backend' => '/'.$persistenceDat,
        ];
        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($jsonPath, $json);
        $io->text(['Written /ventoy/ventoy.json:', $json]);
    }
}
