<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\Tests\Support\FakeProcessRunner;
use App\UsbSetup\IsoDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class IsoDownloaderTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function parseChecksumProvider(): array
    {
        $sums = "abc123  debian-live-12-amd64-standard.iso\n"
            ."DEF456 *debian-live-12-amd64-cinnamon.iso\n"
            ."\n"
            ."# comment line\n";

        return [
            'space separator' => [$sums, 'debian-live-12-amd64-standard.iso', 'abc123'],
            'binary star marker + lowercased' => [$sums, 'debian-live-12-amd64-cinnamon.iso', 'def456'],
            'no match returns null' => [$sums, 'nonexistent.iso', null],
            'empty content returns null' => ['', 'anything.iso', null],
        ];
    }

    public function testCorruptCacheRedownloads(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        mkdir($cacheDir, 0755, true);
        touch("$cacheDir/$iso");
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        // first sha256sum call sees the corrupt cache, the second verifies the re-download
        $fake->on('sha256sum', 0, "badbad  $cacheDir/$iso\n");
        $fake->on('sha256sum', 0, "abc123  $cacheDir/$iso\n");
        $downloader = new IsoDownloader($fake);

        try {
            $dest = $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);

            self::assertSame("$cacheDir/$iso", $dest);
            self::assertTrue($fake->ran('curl -fL -# -o'));
        } finally {
            @unlink("$cacheDir/$iso");
            @rmdir($cacheDir);
        }
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    public function testDownloadFailureThrows(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        $fake->on('curl -fL -# -o', 1, '');
        $downloader = new IsoDownloader($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to download/');
            $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);
        } finally {
            @unlink("$cacheDir/$iso");
            @rmdir($cacheDir);
        }
    }

    public function testDownloadedChecksumMismatchThrows(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        $fake->on('sha256sum', 0, "badbad  $cacheDir/$iso\n");
        $downloader = new IsoDownloader($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Checksum mismatch after download/');
            $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);
        } finally {
            @unlink("$cacheDir/$iso");
            @rmdir($cacheDir);
        }
    }

    public function testFetchChecksumsFailureThrows(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 22, '');
        $downloader = new IsoDownloader($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to fetch SHA256SUMS/');
            $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);
        } finally {
            @rmdir($cacheDir);
        }
    }

    public function testFreshDownload(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        $fake->on('sha256sum', 0, "abc123  $cacheDir/$iso\n");
        $downloader = new IsoDownloader($fake);

        try {
            $dest = $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);

            self::assertSame("$cacheDir/$iso", $dest);
            self::assertTrue($fake->ran('curl -fL -# -o'));
        } finally {
            @rmdir($cacheDir);
        }
    }

    public function testListingFailureThrows(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 22, '');
        $downloader = new IsoDownloader($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to fetch Debian ISO listing/');
            $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);
        } finally {
            @rmdir($cacheDir);
        }
    }

    public function testMissingChecksumThrows(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  some-other-file.iso\n");
        $downloader = new IsoDownloader($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/No checksum found/');
            $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);
        } finally {
            @rmdir($cacheDir);
        }
    }

    #[DataProvider('parseChecksumProvider')]
    public function testParseChecksum(string $sums, string $filename, ?string $expected): void
    {
        self::assertSame($expected, IsoDownloader::parseChecksum($sums, $filename));
    }

    public function testUsesVerifiedCache(): void
    {
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        mkdir($cacheDir, 0755, true);
        touch("$cacheDir/$iso");
        $fake = new FakeProcessRunner();
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        $fake->on('sha256sum', 0, "abc123  $cacheDir/$iso\n");
        $downloader = new IsoDownloader($fake);

        try {
            $dest = $downloader('standard', new BufferedOutput(), self::io(), $cacheDir);

            self::assertSame("$cacheDir/$iso", $dest);
            self::assertFalse($fake->ran('curl -fL -# -o'));
        } finally {
            @unlink("$cacheDir/$iso");
            @rmdir($cacheDir);
        }
    }
}
