<?php

declare(strict_types=1);

namespace App\UsbSetup;

use App\Process\ProcessRunner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SoftwareDownloadsManager
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    /**
     * @return array<array{source: string, relative: string}>
     */
    public function __invoke(
        string $downloadsFile,
        OutputInterface $output,
        SymfonyStyle $io,
        string $cacheDir,
    ): array {
        $classified = $this->parseDownloadsFile($downloadsFile);

        foreach ($classified['torrent'] as $line) {
            $io->note("Torrent link recognized but not yet supported (planned for a future release): $line");
        }
        foreach ($classified['invalid'] as $line) {
            $io->warning(
                'Skipping invalid downloads-file line (expected http(s)://, magnet:, urn:btmh:, '.
                "an existing local file/directory path, or a 'dir/**' recursive directory path): $line"
            );
        }

        $files = $this->collectLocalFiles($classified['local']);
        if ($files !== []) {
            $io->text(sprintf('Found %d locally-provided file(s).', count($files)));
        }

        if ($classified['http'] === [] && $files === []) {
            $io->text('No software downloads queued.');

            return [];
        }

        foreach ($classified['http'] as $url) {
            try {
                $dest = $this->downloadSoftwareFile($url, $output, $io, $cacheDir);
                $files[] = ['source' => $dest, 'relative' => basename($dest)];
            } catch (RuntimeException $e) {
                $io->warning("Skipping $url — ".$e->getMessage());
            }
        }

        return $files;
    }

    /**
     * @return array{
     *     http: string[],
     *     torrent: string[],
     *     local: array<array{path: string, recursive: bool}>,
     *     invalid: string[]
     * }
     */
    public function parseDownloadsFile(string $path): array
    {
        $lines = array_values(
            array_filter(
                array_map('trim', file($path)),
                static fn($l) => $l !== '' && $l[0] !== '#'
            )
        );

        $result = ['http' => [], 'torrent' => [], 'local' => [], 'invalid' => []];
        foreach ($lines as $line) {
            $c = self::classifyDownloadLine($line, 'is_file', 'is_dir');
            if ($c['type'] === 'local') {
                $result['local'][] = ['path' => $c['path'], 'recursive' => $c['recursive']];
            } else {
                $result[$c['type']][] = $line;
            }
        }

        return $result;
    }

    /**
     * Classify one downloads-file line. Pure except for the injected $isFile/$isDir probes,
     * so the URL/torrent/recursive-suffix logic is unit-testable with stub callables.
     *
     * @param callable(string): bool $isFile
     * @param callable(string): bool $isDir
     * @return array{type: 'http'|'torrent'|'local'|'invalid', path: string, recursive: bool}
     */
    public static function classifyDownloadLine(string $line, callable $isFile, callable $isDir): array
    {
        if (preg_match('#^https?://#i', $line)) {
            return ['type' => 'http', 'path' => $line, 'recursive' => false];
        }
        if (preg_match('#^(magnet:|urn:btmh:)#i', $line)) {
            return ['type' => 'torrent', 'path' => $line, 'recursive' => false];
        }
        if (str_ends_with($line, '/**') && $isDir(substr($line, 0, -3))) {
            return ['type' => 'local', 'path' => substr($line, 0, -3), 'recursive' => true];
        }
        if ($isFile($line) || $isDir($line)) {
            return ['type' => 'local', 'path' => $line, 'recursive' => false];
        }

        return ['type' => 'invalid', 'path' => $line, 'recursive' => false];
    }

    /**
     * @param array<array{path: string, recursive: bool}> $entries
     *
     * @return array<array{source: string, relative: string}>
     */
    public function collectLocalFiles(array $entries): array
    {
        $files = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            if (!is_dir($path)) {
                $files[] = ['source' => $path, 'relative' => basename($path)];
                continue;
            }

            if (!$entry['recursive']) {
                foreach (glob(rtrim($path, '/').'/*') ?: [] as $child) {
                    if (is_file($child)) {
                        $files[] = ['source' => $child, 'relative' => basename($child)];
                    }
                }
                continue;
            }

            $root = rtrim($path, '/');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $relative = ltrim(substr($fileInfo->getPathname(), strlen($root)), '/');
                $files[] = ['source' => $fileInfo->getPathname(), 'relative' => $relative];
            }
        }

        return $files;
    }

    public function downloadSoftwareFile(
        string $url,
        OutputInterface $output,
        SymfonyStyle $io,
        string $cacheDir
    ): string {
        if (!self::ensureDirectory($cacheDir, 0755)) {
            throw new RuntimeException("Cannot create download cache directory: $cacheDir");
        }

        $filename = basename((string)parse_url($url, PHP_URL_PATH));
        if ($filename === '' || $filename === '/') {
            throw new RuntimeException("Cannot determine filename from URL: $url");
        }
        $dest = rtrim($cacheDir, '/').'/'.$filename;

        if (is_file($dest) && filesize($dest) > 0) {
            $io->text("Already cached (no checksum available to verify) — skipping: $filename");

            return $dest;
        }

        $headerFile = $dest.'.headers';
        $io->text("Downloading $filename ...");
        [$exit] = $this->runCmd(
            'curl -fL -# -D '.escapeshellarg($headerFile).' -o '.escapeshellarg($dest).' '.escapeshellarg($url),
            true,
            $output
        );
        $contentType = $this->lastContentType($headerFile);
        @unlink($headerFile);
        if ($exit !== 0) {
            @unlink($dest);
            throw new RuntimeException("Failed to download $url");
        }
        if (self::shouldRejectAsHtml($contentType)) {
            @unlink($dest);
            throw new RuntimeException(
                "Refusing $url — server returned Content-Type \"$contentType\" instead of a binary file ".
                '(likely a login/session-gated page, not a direct download link).'
            );
        }

        $io->text("Downloaded: $dest");

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

    public function lastContentType(string $headerFile): ?string
    {
        if (!is_file($headerFile)) {
            return null;
        }

        return self::parseLastContentType(file($headerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    /**
     * Return the LAST Content-Type value across header lines (curl -D accumulates headers over
     * redirects, so the final response's type is the one that matters). Pure — unit-testable.
     *
     * @param string[] $headerLines
     */
    public static function parseLastContentType(array $headerLines): ?string
    {
        $type = null;
        foreach ($headerLines as $line) {
            if (preg_match('/^content-type:\s*(.+)$/i', trim($line), $m)) {
                $type = trim($m[1]);
            }
        }

        return $type;
    }

    /**
     * A binary download must not have a text/* Content-Type — that signals a login/session-gated
     * HTML page rather than the installer. Pure — unit-testable.
     */
    public static function shouldRejectAsHtml(?string $contentType): bool
    {
        return $contentType !== null && preg_match('#^text/#i', $contentType) === 1;
    }

    /**
     * @param array<array{source: string, relative: string}> $files
     */
    public function copySoftwareFiles(
        array $files,
        string $mountPoint,
        OutputInterface $output,
        SymfonyStyle $io,
        bool $isUpdate,
    ): void {
        $dir = rtrim($mountPoint, '/').'/software';
        if (!self::ensureDirectory($dir, 0755)) {
            throw new RuntimeException('Cannot create /software directory on USB.');
        }

        foreach ($files as $file) {
            $source = $file['source'];
            $relative = $file['relative'];
            $destDir = dirname($dir.'/'.$relative);
            if (!self::ensureDirectory($destDir, 0755)) {
                $io->warning("Cannot create destination directory for $relative on USB — skipping.");
                continue;
            }
            if ($isUpdate && self::fileMatchesOnStick($dir.'/'.$relative, $source)) {
                $io->text("$relative already on stick — skipping copy.");
                continue;
            }
            $sizeMb = filesize($source) / 1048576;
            if ($sizeMb > 4090) {
                $io->warning(
                    "Skipping $relative — exceeds ~4 GiB FAT32 single-file limit ({$sizeMb} MiB)."
                );
                continue;
            }
            $io->text(sprintf('Copying %s to /software/ (%d MiB)...', $relative, (int)round($sizeMb)));
            $dest = $dir.'/'.$relative;
            [$exit] = $this->runCmd(
                'cp --no-preserve=all '.escapeshellarg($source).' '.escapeshellarg($dest).' 2>&1',
                true,
                $output
            );
            if ($exit !== 0) {
                $io->warning("Failed to copy $relative to USB — skipping.");
            }
        }
    }

    private static function fileMatchesOnStick(string $dest, string $localPath): bool
    {
        return is_file($dest) && is_file($localPath) && filesize($dest) === filesize($localPath);
    }
}
