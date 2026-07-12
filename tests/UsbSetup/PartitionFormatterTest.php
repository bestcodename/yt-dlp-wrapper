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
}
