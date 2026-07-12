<?php

declare(strict_types=1);

namespace App\UsbSetup;

use App\Process\ProcessRunner;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class IsoDownloader
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    public function __invoke(string $variant, OutputInterface $output, SymfonyStyle $io, string $cacheDir): string
    {
        if (!self::ensureDirectory($cacheDir, 0755)) {
            throw new RuntimeException("Cannot create ISO cache directory: $cacheDir");
        }

        $baseUrl = 'https://cdimage.debian.org/debian-cd/current-live/amd64/iso-hybrid/';

        $io->text("Fetching ISO listing from $baseUrl ...");
        [$exit, $html] = $this->runCmd('curl -fsSL '.escapeshellarg($baseUrl), false, $output);
        if ($exit !== 0 || trim($html) === '') {
            throw new RuntimeException('Failed to fetch Debian ISO listing.');
        }

        $pattern = '/href="(debian-live-[\d.]+-amd64-'.preg_quote($variant, '/').'\.iso)"/';
        if (!preg_match($pattern, $html, $m)) {
            throw new RuntimeException("No Debian live ISO found for variant '$variant'.");
        }

        $filename = $m[1];
        $url = $baseUrl.$filename;
        $dest = rtrim($cacheDir, '/').'/'.$filename;
        $checksumUrl = $baseUrl.'SHA256SUMS';

        $io->text('Fetching checksums...');
        [$exit, $sums] = $this->runCmd('curl -fsSL '.escapeshellarg($checksumUrl), false, $output);
        if ($exit !== 0 || trim($sums) === '') {
            throw new RuntimeException('Failed to fetch SHA256SUMS.');
        }
        $expected = self::parseChecksum($sums, $filename);
        if ($expected === null) {
            throw new RuntimeException("No checksum found for $filename in SHA256SUMS.");
        }

        if (is_file($dest)) {
            $io->text('Verifying cached ISO...');
            if ($this->sha256($dest, $output) === $expected) {
                $io->text("Cached ISO verified: $dest");

                return $dest;
            }
            $io->warning('Cached ISO is corrupt or incomplete — re-downloading.');
            @unlink($dest);
        }

        $io->text("Downloading $filename (this may take a while)...");
        [$exit] = $this->runCmd(
            'curl -fL -# -o '.escapeshellarg($dest).' '.escapeshellarg($url),
            true,
            $output
        );
        if ($exit !== 0) {
            @unlink($dest);
            throw new RuntimeException("Failed to download $url");
        }

        $io->text('Verifying download...');
        if ($this->sha256($dest, $output) !== $expected) {
            @unlink($dest);
            throw new RuntimeException('Checksum mismatch after download — file deleted.');
        }

        $io->text("Download verified. Saved to $dest");

        return $dest;
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

    /**
     * Parses a `sha256sum`-format SHA256SUMS listing for one filename's checksum.
     * Pure — unit-testable.
     */
    public static function parseChecksum(string $sumsContent, string $filename): ?string
    {
        foreach (explode("\n", $sumsContent) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            if (isset($parts[1]) && trim($parts[1], '* ') === $filename) {
                return strtolower($parts[0]);
            }
        }

        return null;
    }

    private function sha256(string $path, OutputInterface $output): string
    {
        [$exit, $out] = $this->runCmd('sha256sum '.escapeshellarg($path), false, $output);
        if ($exit !== 0) {
            throw new RuntimeException("sha256sum failed on $path");
        }

        return strtolower(explode(' ', trim($out))[0]);
    }
}
