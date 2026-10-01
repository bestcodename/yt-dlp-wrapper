<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\Tests\Support\FakeProcessRunner;
use App\UsbSetup\PartitionFormatter;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PartitionFormatterTest extends TestCase
{
    public function testChildPartitionsDropsDeviceAndBlankLines(): void
    {
        self::assertSame(
            ['/dev/sdb1', '/dev/sdb2'],
            PartitionFormatter::childPartitions("/dev/sdb\n/dev/sdb1\n/dev/sdb2\n\n", '/dev/sdb')
        );
        self::assertSame([], PartitionFormatter::childPartitions("/dev/sdb\n", '/dev/sdb'));
        self::assertSame([], PartitionFormatter::childPartitions('', '/dev/sdb'));
    }

    public function testMountFailureThrows(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('mount ', 1, '');
        $formatter = new PartitionFormatter($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to mount/');
        $formatter->mount('/dev/sdb1', new BufferedOutput());
    }

    public function testMountReadOnlyAddsFlag(): void
    {
        $fake = new FakeProcessRunner();
        $formatter = new PartitionFormatter($fake);

        $mount = $formatter->mount('/dev/sdb1', new BufferedOutput(), 'src', true);

        self::assertTrue($fake->ran('mount -o ro'));
        @rmdir($mount);
    }

    public function testMountSucceeds(): void
    {
        $fake = new FakeProcessRunner();
        $formatter = new PartitionFormatter($fake);

        $mount = $formatter->mount('/dev/sdb1', new BufferedOutput());

        self::assertStringContainsString('usb_setup_', $mount);
        self::assertTrue($fake->ran("mount '/dev/sdb1'"));
        @rmdir($mount);
    }

    public function testReformatFat32FailsAfterRetries(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('mkfs.fat', 1, '');
        $formatter = new PartitionFormatter($fake, self::waitForPartitionStub(['/dev/sdb1']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mkfs\.fat failed on \/dev\/sdb1 after retries/');
        $formatter->reformatFat32('/dev/sdb1', new BufferedOutput(), self::io());
    }

    /**
     * @param list<string> $existingPartitions
     */
    private static function waitForPartitionStub(array $existingPartitions): Closure
    {
        return static function (string $part, int $timeoutSec = 10) use ($existingPartitions): void {
            if (!in_array($part, $existingPartitions, true)) {
                throw new RuntimeException("Partition {$part} did not appear within {$timeoutSec}s.");
            }
        };
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    public function testReformatFat32RetriesOnFailureThenSucceeds(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('mkfs.fat', 1, '');
        $fake->on('mkfs.fat', 1, '');
        $fake->on('mkfs.fat', 0, '');
        $formatter = new PartitionFormatter($fake, self::waitForPartitionStub(['/dev/sdb1']));

        $formatter->reformatFat32('/dev/sdb1', new BufferedOutput(), self::io());

        self::assertSame(3, substr_count(implode("\n", $fake->commands), 'mkfs.fat'));
    }

    public function testReformatFat32Succeeds(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('mkfs.fat', 0, '');
        $formatter = new PartitionFormatter($fake, self::waitForPartitionStub(['/dev/sdb1']));

        $formatter->reformatFat32('/dev/sdb1', new BufferedOutput(), self::io(), 'MYLABEL');

        self::assertTrue($fake->ran("mkfs.fat -F 32 -n 'MYLABEL' '/dev/sdb1'"));
    }

    public function testReformatFat32WaitsForPartitionFirst(): void
    {
        $fake = new FakeProcessRunner();
        $formatter = new PartitionFormatter($fake, self::waitForPartitionStub([])); // never appears

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not appear within/');
        $formatter->reformatFat32('/dev/sdb1', new BufferedOutput(), self::io());
    }

    public function testUnmountRunsUmountAndRemovesDir(): void
    {
        $fake = new FakeProcessRunner();
        $formatter = new PartitionFormatter($fake);
        $dir = sys_get_temp_dir().'/pf_test_'.uniqid('', true);
        mkdir($dir);

        $formatter->unmount($dir);

        self::assertTrue($fake->ran("umount '$dir'"));
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testWriteFat32PartitionTablePartedFailureThrows(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('parted ', 1, "Error: Partition(s) on /dev/sdb are being used.\n");
        $formatter = new PartitionFormatter($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parted failed to write the partition table on /dev/sdb.');
        $formatter->writeFat32PartitionTable('/dev/sdb', new BufferedOutput(), self::io());
    }

    public function testWriteFat32PartitionTableUnmountsEveryPartitionThenWipesThenPartitions(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -lnpo NAME', 0, "/dev/sdb\n/dev/sdb1\n/dev/sdb2\n");
        $formatter = new PartitionFormatter($fake);

        $formatter->writeFat32PartitionTable('/dev/sdb', new BufferedOutput(), self::io());

        foreach (['/dev/sdb1', '/dev/sdb2'] as $partition) {
            self::assertTrue($fake->ran("nsenter -t 1 --mount -- umount -f '$partition'"));
            self::assertTrue($fake->ran("umount -f '$partition'"));
        }
        self::assertFalse($fake->ran("umount -f '/dev/sdb' "));
        $expected = [
            "wipefs -a '/dev/sdb' 2>&1",
            "parted -s '/dev/sdb' mklabel msdos mkpart primary fat32 1MiB 100% set 1 lba on 2>&1",
            "partprobe '/dev/sdb' 2>/dev/null",
        ];
        $actual = array_values(array_filter(
            $fake->commands,
            static fn(string $cmd): bool => in_array($cmd, $expected, true)
        ));
        self::assertSame($expected, $actual);
    }

    public function testWriteFat32PartitionTableWipefsFailureThrowsBeforeParted(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('wipefs ', 1, "wipefs: error: /dev/sdb: probing initialization failed: Device or resource busy\n");
        $formatter = new PartitionFormatter($fake);

        try {
            $formatter->writeFat32PartitionTable('/dev/sdb', new BufferedOutput(), self::io());
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('wipefs failed on /dev/sdb', $e->getMessage());
        }
        self::assertFalse($fake->ran('parted '));
    }
}
