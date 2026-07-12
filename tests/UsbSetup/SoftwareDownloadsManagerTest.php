<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\Tests\Support\FakeProcessRunner;
use App\UsbSetup\SoftwareDownloadsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SoftwareDownloadsManagerTest extends TestCase
{
    private const TMP_PREFIX = 'software_downloads_test_';

    /**
     * @return array<string, array{string, array{type: string, path: string, recursive: bool}}>
     */
    public static function classifyDownloadLineProvider(): array
    {
        return [
            'http' => [
                'http://example.com/a.exe',
                ['type' => 'http', 'path' => 'http://example.com/a.exe', 'recursive' => false],
            ],
            'https case-insensitive' => [
                'HTTPS://example.com/a.exe',
                ['type' => 'http', 'path' => 'HTTPS://example.com/a.exe', 'recursive' => false],
            ],
            'magnet' => [
                'magnet:?xt=urn:btih:abc',
                ['type' => 'torrent', 'path' => 'magnet:?xt=urn:btih:abc', 'recursive' => false],
            ],
            'urn:btmh' => [
                'urn:btmh:1220abc',
                ['type' => 'torrent', 'path' => 'urn:btmh:1220abc', 'recursive' => false],
            ],
            'recursive local dir' => [
                'config/soft/**',
                ['type' => 'local', 'path' => 'config/soft', 'recursive' => true],
            ],
            'plain local' => [
                'config/file.exe',
                ['type' => 'local', 'path' => 'config/file.exe', 'recursive' => false],
            ],
            'invalid' => ['not-a-thing', ['type' => 'invalid', 'path' => 'not-a-thing', 'recursive' => false]],
        ];
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function shouldRejectAsHtmlProvider(): array
    {
        return [
            'text/html rejected' => ['text/html; charset=utf-8', true],
            'text/plain rejected' => ['text/plain', true],
            'octet-stream kept' => ['application/octet-stream', false],
            'null kept' => [null, false],
        ];
    }

    /**
     * @param array{type: string, path: string, recursive: bool} $expected
     */
    #[DataProvider('classifyDownloadLineProvider')]
    public function testClassifyDownloadLine(string $line, array $expected): void
    {
        // Stub filesystem probes: only "config/soft" is a dir, only "config/file.exe" is a file.
        $isDir = static fn(string $p): bool => $p === 'config/soft';
        $isFile = static fn(string $p): bool => $p === 'config/file.exe';

        self::assertSame($expected, SoftwareDownloadsManager::classifyDownloadLine($line, $isFile, $isDir));
    }

    public function testClassifyDownloadLineRecursiveSuffixButNotADirIsInvalid(): void
    {
        $false = static fn(string $p): bool => false;

        $result = SoftwareDownloadsManager::classifyDownloadLine('missing/**', $false, $false);

        self::assertSame('invalid', $result['type']);
    }

    public function testCollectLocalFilesNonRecursiveDirOnlyListsTopLevelFiles(): void
    {
        $dir = self::tmpDir();
        touch($dir.'/a.exe');
        touch($dir.'/b.zip');
        mkdir($dir.'/sub');
        touch($dir.'/sub/c.exe');
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            $files = $manager->collectLocalFiles([['path' => $dir, 'recursive' => false]]);

            $relatives = array_column($files, 'relative');
            sort($relatives);
            self::assertSame(['a.exe', 'b.zip'], $relatives);
        } finally {
            self::rmrf($dir);
        }
    }

    private static function tmpDir(): string
    {
        $dir = sys_get_temp_dir().'/'.self::TMP_PREFIX.uniqid('', true);
        mkdir($dir, 0755, true);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? self::rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testCollectLocalFilesRecursiveDirPreservesSubpaths(): void
    {
        $dir = self::tmpDir();
        mkdir($dir.'/sub');
        touch($dir.'/top.exe');
        touch($dir.'/sub/nested.zip');
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            $files = $manager->collectLocalFiles([['path' => $dir, 'recursive' => true]]);

            $relatives = array_column($files, 'relative');
            sort($relatives);
            self::assertSame(['sub/nested.zip', 'top.exe'], $relatives);
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCollectLocalFilesSingleFileEntry(): void
    {
        $dir = self::tmpDir();
        $file = $dir.'/a.exe';
        touch($file);
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            $files = $manager->collectLocalFiles([['path' => $file, 'recursive' => false]]);

            self::assertSame([['source' => $file, 'relative' => 'a.exe']], $files);
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCopySoftwareFilesCpFailureWarnsAndContinues(): void
    {
        $dir = self::tmpDir();
        $source = $dir.'/a.exe';
        touch($source);
        $fake = new FakeProcessRunner();
        $fake->on('cp --no-preserve=all', 1, '');
        $manager = new SoftwareDownloadsManager($fake);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $manager->copySoftwareFiles([['source' => $source, 'relative' => 'a.exe']], $dir, $output, $io, false);

            self::assertStringContainsString('Failed to copy a.exe', $output->fetch());
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCopySoftwareFilesDestinationDirUncreatableWarnsAndSkips(): void
    {
        $dir = self::tmpDir();
        mkdir($dir.'/software');
        touch($dir.'/software/sub'); // occupies the nested-dir path with a regular file
        $source = $dir.'/file.exe';
        touch($source);
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $manager->copySoftwareFiles(
                [['source' => $source, 'relative' => 'sub/file.exe']],
                $dir,
                $output,
                $io,
                false
            );

            self::assertStringContainsString('Cannot create destination directory', $output->fetch());
            self::assertFalse($fake->ran('cp '));
        } finally {
            self::rmrf($dir);
        }
    }

    // --- parseDownloadsFile ---

    public function testCopySoftwareFilesExceedsFat32LimitSkips(): void
    {
        $dir = self::tmpDir();
        $source = $dir.'/big.exe';
        self::sparseFile($source, 4091 * 1048576);
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $manager->copySoftwareFiles([['source' => $source, 'relative' => 'big.exe']], $dir, $output, $io, false);

            self::assertStringContainsString('exceeds ~4 GiB FAT32 single-file limit', $output->fetch());
            self::assertFalse($fake->ran('cp '));
        } finally {
            self::rmrf($dir);
        }
    }

    // --- collectLocalFiles ---

    private static function sparseFile(string $path, int $bytes): void
    {
        $fh = fopen($path, 'w');
        ftruncate($fh, $bytes);
        fclose($fh);
    }

    public function testCopySoftwareFilesSoftwareDirUncreatableThrows(): void
    {
        $dir = self::tmpDir();
        touch($dir.'/software'); // occupies the path with a regular file
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Cannot create \/software directory/');
            $manager->copySoftwareFiles([], $dir, new BufferedOutput(), self::io(), false);
        } finally {
            self::rmrf($dir);
        }
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    // --- downloadSoftwareFile ---

    public function testCopySoftwareFilesSucceeds(): void
    {
        $dir = self::tmpDir();
        $source = $dir.'/a.exe';
        file_put_contents($source, 'bytes');
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);

        try {
            $manager->copySoftwareFiles(
                [['source' => $source, 'relative' => 'a.exe']],
                $dir,
                new BufferedOutput(),
                self::io(),
                false
            );

            self::assertTrue($fake->ran('cp --no-preserve=all '.escapeshellarg($source)));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCopySoftwareFilesUpdateSkipsMatchingExistingFile(): void
    {
        $dir = self::tmpDir();
        mkdir($dir.'/software');
        $source = $dir.'/a.exe';
        self::sparseFile($source, 500);
        self::sparseFile($dir.'/software/a.exe', 500);
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $manager->copySoftwareFiles([['source' => $source, 'relative' => 'a.exe']], $dir, $output, $io, true);

            self::assertStringContainsString('already on stick', $output->fetch());
            self::assertFalse($fake->ran('cp '));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testDownloadSoftwareFileAlreadyCachedSkipsCurl(): void
    {
        $dir = self::tmpDir();
        file_put_contents($dir.'/a.exe', 'cached-bytes');
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);

        try {
            $dest = $manager->downloadSoftwareFile('http://example.com/a.exe', new BufferedOutput(), self::io(), $dir);

            self::assertSame($dir.'/a.exe', $dest);
            self::assertFalse($fake->ran('curl'));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testDownloadSoftwareFileCacheDirUncreatableThrows(): void
    {
        $dir = self::tmpDir();
        $badCacheDir = $dir.'/not-a-dir';
        touch($badCacheDir);
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Cannot create download cache directory/');
            $manager->downloadSoftwareFile('http://example.com/a.exe', new BufferedOutput(), self::io(), $badCacheDir);
        } finally {
            self::rmrf($dir);
        }
    }

    public function testDownloadSoftwareFileCurlFailureThrows(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $fake->on('curl -fL', 1, '');
        $manager = new SoftwareDownloadsManager($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to download/');
            $manager->downloadSoftwareFile('http://example.com/a.exe', new BufferedOutput(), self::io(), $dir);
            self::assertFileDoesNotExist($dir.'/a.exe');
        } finally {
            self::rmrf($dir);
        }
    }

    public function testDownloadSoftwareFileHtmlContentTypeRejectedAndCleansUp(): void
    {
        $dir = self::tmpDir();
        // Simulate what curl -D would have written before our faked curl call "succeeds".
        touch($dir.'/a.exe');
        file_put_contents($dir.'/a.exe.headers', "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n");
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Refusing .* Content-Type "text\/html/');
            $manager->downloadSoftwareFile('http://example.com/a.exe', new BufferedOutput(), self::io(), $dir);
        } finally {
            self::assertFileDoesNotExist($dir.'/a.exe');
            self::rmrf($dir);
        }
    }

    // --- lastContentType ---

    public function testDownloadSoftwareFileNoFilenameInUrlThrows(): void
    {
        $dir = self::tmpDir();
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Cannot determine filename from URL/');
            $manager->downloadSoftwareFile('http://example.com/', new BufferedOutput(), self::io(), $dir);
        } finally {
            self::rmrf($dir);
        }
    }

    public function testDownloadSoftwareFileSucceeds(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);

        try {
            $dest = $manager->downloadSoftwareFile('http://example.com/a.exe', new BufferedOutput(), self::io(), $dir);

            self::assertSame($dir.'/a.exe', $dest);
            self::assertTrue($fake->ran('curl -fL'));
        } finally {
            self::rmrf($dir);
        }
    }

    // --- copySoftwareFiles ---

    public function testInvokeCollectsLocalAndDownloadsHttp(): void
    {
        $dir = self::tmpDir();
        $localFile = $dir.'/local.exe';
        touch($localFile);
        $downloadsFile = $dir.'/downloads.txt';
        file_put_contents(
            $downloadsFile,
            "http://example.com/a.exe\nmagnet:?xt=urn:btih:abc\nnot-a-real-thing\n$localFile\n"
        );
        $cacheDir = $dir.'/cache';
        $fake = new FakeProcessRunner();
        $manager = new SoftwareDownloadsManager($fake);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $files = $manager($downloadsFile, $output, $io, $cacheDir);

            $relatives = array_column($files, 'relative');
            sort($relatives);
            self::assertSame(['a.exe', 'local.exe'], $relatives);
            $text = $output->fetch();
            self::assertStringContainsString('Torrent link recognized', $text);
            self::assertStringContainsString('Skipping invalid downloads-file line', $text);
        } finally {
            self::rmrf($dir);
        }
    }

    public function testInvokeHttpFailureIsSkippedNotFatal(): void
    {
        $dir = self::tmpDir();
        $downloadsFile = $dir.'/downloads.txt';
        file_put_contents($downloadsFile, "http://example.com/a.exe\n");
        $cacheDir = $dir.'/cache';
        $fake = new FakeProcessRunner();
        $fake->on('curl -fL', 1, '');
        $manager = new SoftwareDownloadsManager($fake);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $files = $manager($downloadsFile, $output, $io, $cacheDir);

            self::assertSame([], $files);
            self::assertStringContainsString('Skipping http://example.com/a.exe', $output->fetch());
        } finally {
            self::rmrf($dir);
        }
    }

    public function testInvokeNoDownloadsQueued(): void
    {
        $dir = self::tmpDir();
        $downloadsFile = $dir.'/downloads.txt';
        file_put_contents($downloadsFile, "# nothing here\n");
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $files = $manager($downloadsFile, $output, $io, $dir.'/cache');

            self::assertSame([], $files);
            self::assertStringContainsString('No software downloads queued.', $output->fetch());
        } finally {
            self::rmrf($dir);
        }
    }

    public function testLastContentTypeMissingFileIsNull(): void
    {
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        self::assertNull($manager->lastContentType(sys_get_temp_dir().'/does-not-exist-'.uniqid('', true)));
    }

    public function testLastContentTypeReadsRealFile(): void
    {
        $dir = self::tmpDir();
        $headerFile = $dir.'/a.exe.headers';
        file_put_contents($headerFile, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\n");
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            self::assertSame('application/octet-stream', $manager->lastContentType($headerFile));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testParseDownloadsFileClassifiesLines(): void
    {
        $dir = self::tmpDir();
        $localFile = $dir.'/local.exe';
        touch($localFile);
        $localDir = $dir.'/soft';
        mkdir($localDir);
        touch($localDir.'/x.zip');
        $downloadsFile = $dir.'/downloads.txt';
        file_put_contents(
            $downloadsFile,
            "# comment\n\nhttp://example.com/a.exe\nmagnet:?xt=urn:btih:abc\n$localFile\n$localDir/**\nnot-a-real-thing\n"
        );
        $manager = new SoftwareDownloadsManager(new FakeProcessRunner());

        try {
            $result = $manager->parseDownloadsFile($downloadsFile);

            self::assertSame(['http://example.com/a.exe'], $result['http']);
            self::assertSame(['magnet:?xt=urn:btih:abc'], $result['torrent']);
            self::assertSame(
                [['path' => $localFile, 'recursive' => false], ['path' => $localDir, 'recursive' => true]],
                $result['local']
            );
            self::assertSame(['not-a-real-thing'], $result['invalid']);
        } finally {
            self::rmrf($dir);
        }
    }

    // --- __invoke ---

    public function testParseLastContentTypeNoneReturnsNull(): void
    {
        self::assertNull(
            SoftwareDownloadsManager::parseLastContentType(['HTTP/1.1 200 OK', 'Content-Length: 10'])
        );
    }

    public function testParseLastContentTypeReturnsFinalMatch(): void
    {
        // Simulates curl -D across a redirect: first response text/html, final application/octet-stream.
        $headers = [
            'HTTP/1.1 302 Found',
            'Content-Type: text/html; charset=utf-8',
            'Location: https://cdn.example.com/a.exe',
            'HTTP/1.1 200 OK',
            'Content-Type: application/octet-stream',
            'Content-Length: 12345',
        ];

        self::assertSame('application/octet-stream', SoftwareDownloadsManager::parseLastContentType($headers));
    }

    #[DataProvider('shouldRejectAsHtmlProvider')]
    public function testShouldRejectAsHtml(?string $contentType, bool $expected): void
    {
        self::assertSame($expected, SoftwareDownloadsManager::shouldRejectAsHtml($contentType));
    }
}
