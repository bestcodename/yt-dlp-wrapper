<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\Tests\Support\FakeProcessRunner;
use App\UsbSetup\VentoyInstaller;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class VentoyInstallerTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function partitionPathProvider(): array
    {
        return [
            'sd device' => ['/dev/sdb', 1, '/dev/sdb1'],
            'nvme device' => ['/dev/nvme0n1', 2, '/dev/nvme0n1p2'],
            'mmcblk device' => ['/dev/mmcblk0', 1, '/dev/mmcblk0p1'],
        ];
    }

    /**
     * @return array<string, array{int, string, bool}>
     */
    public static function runFailedProvider(): array
    {
        return [
            'clean success' => [0, 'Ventoy install finished', false],
            'nonzero exit' => [1, 'anything', true],
            'tools cannot run message' => [0, "Some tools can not run\n", true],
            'not ventoy message' => [0, "does not contain Ventoy\n", true],
        ];
    }

    public function testDefaultWaitForPartitionPolling(): void
    {
        $method = new ReflectionMethod(VentoyInstaller::class, 'defaultWaitForPartition');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not appear within/');
        $method->invoke(null, sys_get_temp_dir().'/does-not-exist-'.uniqid('', true), 0);
    }

    public function testDerivesNvmePartitionNames(): void
    {
        // NVMe regression: partition paths must be nvme0n1p1/p2, never nvme0n11/nvme0n12.
        $fake = new FakeProcessRunner();
        $fake->on('bash ./', 0, "Ventoy install finished\n");
        $installer = new VentoyInstaller($fake, self::waitForPartitionStub(['/dev/nvme0n1p2']));

        $installer('/opt/fake/Ventoy2Disk.sh', '/dev/nvme0n1', new BufferedOutput(), self::io(), false);

        self::assertTrue($fake->ran("umount -f '/dev/nvme0n1p1'"));
        self::assertTrue($fake->ran("umount -f '/dev/nvme0n1p2'"));
        self::assertTrue($fake->ran("fuser -km '/dev/nvme0n1p1'"));
        self::assertFalse($fake->ran('nvme0n11'));
        self::assertFalse($fake->ran('nvme0n12'));
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

    public function testFindBinFallsBackToWhich(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('which ventoy', 0, "/usr/bin/ventoy\n");
        $installer = new VentoyInstaller($fake, wellKnownPaths: []);

        self::assertSame('/usr/bin/ventoy', $installer->findBin(null));
    }

    public function testFindBinThrowsWhenNotFound(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('which ventoy', 1, '');
        $installer = new VentoyInstaller($fake, wellKnownPaths: []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ventoy not found/');
        $installer->findBin(null);
    }

    public function testFindBinUsesHintFirst(): void
    {
        $hint = sys_get_temp_dir().'/ventoy_hint_'.uniqid('', true).'.sh';
        touch($hint);
        chmod($hint, 0755);
        $fake = new FakeProcessRunner();
        $installer = new VentoyInstaller($fake, wellKnownPaths: []);

        try {
            self::assertSame($hint, $installer->findBin($hint));
        } finally {
            @unlink($hint);
        }
    }

    public function testFullInstallUsesInstallFlag(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('bash ./', 0, "Ventoy install finished\n");
        $installer = new VentoyInstaller($fake, self::waitForPartitionStub(['/dev/null2']));

        $installer('/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), self::io(), false);

        $ventoyCmd = $fake->commands[0];
        self::assertStringContainsString(' -I ', $ventoyCmd);
        self::assertStringNotContainsString(' -u ', $ventoyCmd);
        self::assertStringContainsString("'/dev/null'", $ventoyCmd);
        self::assertTrue($fake->ran('nsenter -t 1 --mount -- umount -f'));
        self::assertTrue($fake->ran('fuser -km'));
        self::assertTrue($fake->ran('partprobe'));
    }

    public function testMissingVtoyefiPartitionFails(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('bash ./', 0, "Ventoy install finished\n");
        $installer = new VentoyInstaller($fake, self::waitForPartitionStub([])); // partition 2 never appears

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never appeared/');
        $installer('/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), self::io(), false);
    }

    #[DataProvider('partitionPathProvider')]
    public function testPartitionPath(string $device, int $number, string $expected): void
    {
        self::assertSame($expected, VentoyInstaller::partitionPath($device, $number));
    }

    #[DataProvider('runFailedProvider')]
    public function testRunFailed(int $exit, string $out, bool $expected): void
    {
        self::assertSame($expected, VentoyInstaller::runFailed($exit, $out));
    }

    public function testToolFailureMessageFails(): void
    {
        $fake = new FakeProcessRunner();
        // Ventoy2Disk.sh swallows the worker's exit code — exit 0 with this message is a failure
        $fake->on('bash ./', 0, "Some tools can not run\n");
        $installer = new VentoyInstaller($fake, self::waitForPartitionStub([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/log\.txt/');
        $installer('/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), self::io(), false);
    }

    public function testUpdateUsesUpdateFlag(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('bash ./', 0, "Ventoy update finished\n");
        $installer = new VentoyInstaller($fake, self::waitForPartitionStub(['/dev/null2']));

        $installer('/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), self::io(), true);

        self::assertStringContainsString(' -u ', $fake->commands[0]);
        self::assertStringNotContainsString(' -I ', $fake->commands[0]);
    }
}
