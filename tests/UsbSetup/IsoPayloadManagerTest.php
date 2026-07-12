<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\Tests\Support\FakeProcessRunner;
use App\UsbSetup\IsoPayloadManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class IsoPayloadManagerTest extends TestCase
{
    private const TMP_PREFIX = 'iso_payload_test_';

    /**
     * @return array<string, array{?string, int, array<array{source: string, relative: string}>,
     *     array<string, int>, int}>
     */
    public static function estimateConfigurationPayloadBytesProvider(): array
    {
        $sizes = ['/iso/debian.iso' => 3000000000, '/cache/a.exe' => 500000000, '/local/b.zip' => 250000000];
        $software = [
            ['source' => '/cache/a.exe', 'relative' => 'a.exe'],
            ['source' => '/local/b.zip', 'relative' => 'sub/b.zip'],
        ];

        return [
            'iso + persistence + software' => [
                '/iso/debian.iso',
                2048,
                $software,
                $sizes,
                3000000000 + 2048 * 1048576 + 750000000,
            ],
            'no iso: persistence not counted' => [
                null,
                2048,
                $software,
                $sizes,
                750000000,
            ],
            'iso only' => [
                '/iso/debian.iso',
                4090,
                [],
                $sizes,
                3000000000 + 4090 * 1048576,
            ],
            'nothing queued' => [null, 2048, [], $sizes, 0],
        ];
    }

    public function testCopyIsoExceedsFat32LimitThrows(): void
    {
        $dir = self::tmpDir();
        $iso = $dir.'/big.iso';
        self::sparseFile($iso, 4091 * 1048576);
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/exceeds ~4 GiB FAT32 single-file limit/');
            $manager->copyIso($iso, $dir, new BufferedOutput(), self::io());
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

    /** Creates a sparse file of the given logical size without consuming real disk blocks. */
    private static function sparseFile(string $path, int $bytes): void
    {
        $fh = fopen($path, 'w');
        ftruncate($fh, $bytes);
        fclose($fh);
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
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

    // --- copyIso ---

    public function testCopyIsoFailureThrows(): void
    {
        $dir = self::tmpDir();
        $iso = $dir.'/debian.iso';
        self::sparseFile($iso, 1024);
        $fake = new FakeProcessRunner();
        $fake->on('cp --no-preserve=all', 1, '');
        $manager = new IsoPayloadManager($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to copy ISO/');
            $manager->copyIso($iso, $dir, new BufferedOutput(), self::io());
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCopyIsoSucceeds(): void
    {
        $dir = self::tmpDir();
        $iso = $dir.'/debian.iso';
        self::sparseFile($iso, 1024);
        $fake = new FakeProcessRunner();
        $manager = new IsoPayloadManager($fake);

        try {
            $result = $manager->copyIso($iso, $dir, new BufferedOutput(), self::io());

            self::assertSame('debian.iso', $result);
            self::assertTrue($fake->ran('cp --no-preserve=all '.escapeshellarg($iso)));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCreatePersistenceFileCapsSizeAboveFat32Limit(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $manager = new IsoPayloadManager($fake);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        try {
            $manager->createPersistenceFile($dir, 5000, $output, $io);

            self::assertStringContainsString('Capping at 4090 MiB', $output->fetch());
            self::assertTrue($fake->ran("fallocate -l '4090M'"));
        } finally {
            self::rmrf($dir);
            self::rmrf(sys_get_temp_dir().'/persist_'.getmypid());
        }
    }

    // --- createPersistenceFile ---

    public function testCreatePersistenceFileFallocateAndDdBothFailThrows(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $fake->on('fallocate', 1, '');
        $fake->on('dd if=/dev/zero', 1, '');
        $manager = new IsoPayloadManager($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to create persistence file/');
            $manager->createPersistenceFile($dir, 2048, new BufferedOutput(), self::io());
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCreatePersistenceFileFallocateFailsFallsBackToDd(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $fake->on('fallocate', 1, '');
        $manager = new IsoPayloadManager($fake);

        try {
            $result = $manager->createPersistenceFile($dir, 2048, new BufferedOutput(), self::io());

            self::assertSame('persistence.dat', $result);
            self::assertTrue($fake->ran('dd if=/dev/zero'));
        } finally {
            self::rmrf($dir);
            self::rmrf(sys_get_temp_dir().'/persist_'.getmypid());
        }
    }

    public function testCreatePersistenceFileMkfsFailsThrows(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $fake->on('mkfs.ext4', 1, '');
        $manager = new IsoPayloadManager($fake);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/mkfs\.ext4 failed on persistence file/');
            $manager->createPersistenceFile($dir, 2048, new BufferedOutput(), self::io());
        } finally {
            self::rmrf($dir);
        }
    }

    public function testCreatePersistenceFileMountFailsThrowsAndCleansUp(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $fake->on('mount -o loop', 1, '');
        $manager = new IsoPayloadManager($fake);
        $tmpMount = sys_get_temp_dir().'/persist_'.getmypid();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to loop-mount persistence file/');
            $manager->createPersistenceFile($dir, 2048, new BufferedOutput(), self::io());
        } finally {
            self::assertDirectoryDoesNotExist($tmpMount);
            self::rmrf($dir);
        }
    }

    public function testCreatePersistenceFileSucceeds(): void
    {
        $dir = self::tmpDir();
        $fake = new FakeProcessRunner();
        $manager = new IsoPayloadManager($fake);

        try {
            $result = $manager->createPersistenceFile($dir, 2048, new BufferedOutput(), self::io());

            self::assertSame('persistence.dat', $result);
            self::assertTrue($fake->ran('fallocate'));
            self::assertTrue($fake->ran('mkfs.ext4'));
            self::assertTrue($fake->ran('mount -o loop'));
            self::assertTrue($fake->ran('umount'));
        } finally {
            self::rmrf($dir);
            self::rmrf(sys_get_temp_dir().'/persist_'.getmypid());
        }
    }

    public function testCreatePersistenceFileTmpMountUncreatableThrows(): void
    {
        $dir = self::tmpDir();
        $tmpMount = sys_get_temp_dir().'/persist_'.getmypid();
        self::rmrf($tmpMount);
        touch($tmpMount); // occupies the path with a regular file, so mkdir($tmpMount) fails
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Cannot create temp mount/');
            $manager->createPersistenceFile($dir, 2048, new BufferedOutput(), self::io());
        } finally {
            @unlink($tmpMount);
            self::rmrf($dir);
        }
    }

    /**
     * @param array<array{source: string, relative: string}> $softwareFiles
     * @param array<string, int> $sizes
     */
    #[DataProvider('estimateConfigurationPayloadBytesProvider')]
    public function testEstimateConfigurationPayloadBytes(
        ?string $debianIso,
        int $persistenceMib,
        array $softwareFiles,
        array $sizes,
        int $expected
    ): void {
        $fileSize = static fn(string $path): int => $sizes[$path];

        self::assertSame(
            $expected,
            IsoPayloadManager::estimateConfigurationPayloadBytes($debianIso, $persistenceMib, $softwareFiles, $fileSize)
        );
    }

    // --- isPersistenceValid ---

    public function testIsPersistenceValidLabelMismatchIsFalse(): void
    {
        $dir = self::tmpDir();
        self::sparseFile($dir.'/persistence.dat', 2048 * 1048576);
        $fake = new FakeProcessRunner();
        $fake->on('blkid -o value -s TYPE', 0, "ext4\n");
        $fake->on('blkid -o value -s LABEL', 0, "other\n");
        $manager = new IsoPayloadManager($fake);

        try {
            self::assertFalse($manager->isPersistenceValid($dir, 2048));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testIsPersistenceValidMissingFileIsFalse(): void
    {
        $dir = self::tmpDir();
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertFalse($manager->isPersistenceValid($dir, 2048));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testIsPersistenceValidSizeMismatchIsFalse(): void
    {
        $dir = self::tmpDir();
        self::sparseFile($dir.'/persistence.dat', 1024 * 1048576);
        $fake = new FakeProcessRunner();
        $fake->on('blkid -o value -s TYPE', 0, "ext4\n");
        $fake->on('blkid -o value -s LABEL', 0, "persistence\n");
        $manager = new IsoPayloadManager($fake);

        try {
            self::assertFalse($manager->isPersistenceValid($dir, 2048));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testIsPersistenceValidTrue(): void
    {
        $dir = self::tmpDir();
        self::sparseFile($dir.'/persistence.dat', 2048 * 1048576);
        $fake = new FakeProcessRunner();
        $fake->on('blkid -o value -s TYPE', 0, "ext4\n");
        $fake->on('blkid -o value -s LABEL', 0, "persistence\n");
        $manager = new IsoPayloadManager($fake);

        try {
            self::assertTrue($manager->isPersistenceValid($dir, 2048));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testIsPersistenceValidTypeMismatchIsFalse(): void
    {
        $dir = self::tmpDir();
        self::sparseFile($dir.'/persistence.dat', 2048 * 1048576);
        $fake = new FakeProcessRunner();
        $fake->on('blkid -o value -s TYPE', 0, "vfat\n");
        $manager = new IsoPayloadManager($fake);

        try {
            self::assertFalse($manager->isPersistenceValid($dir, 2048));
        } finally {
            self::rmrf($dir);
        }
    }

    // --- isoMatchesOnStick ---

    public function testIsoMatchesOnStickLocalMissingIsFalse(): void
    {
        $dir = self::tmpDir();
        touch($dir.'/debian.iso');

        try {
            $manager = new IsoPayloadManager(new FakeProcessRunner());
            self::assertFalse($manager->isoMatchesOnStick($dir, 'debian.iso', $dir.'/missing.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testIsoMatchesOnStickMissingIsFalse(): void
    {
        $dir = self::tmpDir();
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertFalse($manager->isoMatchesOnStick($dir, 'debian.iso', $dir.'/local.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testIsoMatchesOnStickSizeMismatchIsFalse(): void
    {
        $dir = self::tmpDir();
        self::sparseFile($dir.'/debian.iso', 1000);
        self::sparseFile($dir.'/local.iso', 2000);
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertFalse($manager->isoMatchesOnStick($dir, 'debian.iso', $dir.'/local.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testIsoMatchesOnStickTrue(): void
    {
        $dir = self::tmpDir();
        self::sparseFile($dir.'/debian.iso', 12345);
        self::sparseFile($dir.'/local.iso', 12345);
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertTrue($manager->isoMatchesOnStick($dir, 'debian.iso', $dir.'/local.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    // --- ventoyJsonHasEntry ---

    public function testVentoyJsonHasEntryEntryAbsentIsFalse(): void
    {
        $dir = self::tmpDir();
        mkdir($dir.'/ventoy');
        file_put_contents(
            $dir.'/ventoy/ventoy.json',
            json_encode(['persistence' => [['image' => '/other.iso', 'backend' => '/persistence.dat']]])
        );
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertFalse($manager->ventoyJsonHasEntry($dir, 'debian.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testVentoyJsonHasEntryEntryPresentIsTrue(): void
    {
        $dir = self::tmpDir();
        mkdir($dir.'/ventoy');
        file_put_contents(
            $dir.'/ventoy/ventoy.json',
            json_encode(['persistence' => [['image' => '/debian.iso', 'backend' => '/persistence.dat']]])
        );
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertTrue($manager->ventoyJsonHasEntry($dir, 'debian.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testVentoyJsonHasEntryInvalidJsonIsFalse(): void
    {
        $dir = self::tmpDir();
        mkdir($dir.'/ventoy');
        file_put_contents($dir.'/ventoy/ventoy.json', 'not json');
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertFalse($manager->ventoyJsonHasEntry($dir, 'debian.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testVentoyJsonHasEntryMissingFileIsFalse(): void
    {
        $dir = self::tmpDir();
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            self::assertFalse($manager->ventoyJsonHasEntry($dir, 'debian.iso'));
        } finally {
            self::rmrf($dir);
        }
    }

    // --- writeVentoyJson ---

    public function testWriteVentoyJsonAppendsToExisting(): void
    {
        $dir = self::tmpDir();
        mkdir($dir.'/ventoy');
        file_put_contents(
            $dir.'/ventoy/ventoy.json',
            json_encode(['persistence' => [['image' => '/other.iso', 'backend' => '/persistence.dat']]])
        );
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            $manager->writeVentoyJson($dir, 'debian.iso', 'persistence.dat', self::io());

            $written = json_decode((string)file_get_contents($dir.'/ventoy/ventoy.json'), true);
            self::assertCount(2, $written['persistence']);
            self::assertSame('/other.iso', $written['persistence'][0]['image']);
            self::assertSame('/debian.iso', $written['persistence'][1]['image']);
        } finally {
            self::rmrf($dir);
        }
    }

    public function testWriteVentoyJsonCreatesNew(): void
    {
        $dir = self::tmpDir();
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            $manager->writeVentoyJson($dir, 'debian.iso', 'persistence.dat', self::io());

            $written = json_decode((string)file_get_contents($dir.'/ventoy/ventoy.json'), true);
            self::assertSame(
                [['image' => '/debian.iso', 'backend' => '/persistence.dat']],
                $written['persistence']
            );
        } finally {
            self::rmrf($dir);
        }
    }

    public function testWriteVentoyJsonDirectoryUncreatableThrows(): void
    {
        $dir = self::tmpDir();
        touch($dir.'/ventoy'); // occupies the path with a regular file, so mkdir($dir/ventoy) fails
        $manager = new IsoPayloadManager(new FakeProcessRunner());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Cannot create \/ventoy directory/');
            $manager->writeVentoyJson($dir, 'debian.iso', 'persistence.dat', self::io());
        } finally {
            self::rmrf($dir);
        }
    }
}
