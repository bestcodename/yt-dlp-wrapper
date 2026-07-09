<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\UsbSetupCommand;
use App\Tests\Support\FakeProcessRunner;
use App\Tests\Support\TestableUsbSetupCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Unit tests for the process-dependent UsbSetupCommand methods, driven through the
 * FakeProcessRunner seam instead of real external binaries.
 */
final class UsbSetupCommandProcessTest extends TestCase
{
    private const LSBLK_NO_VENTOY = '{"blockdevices":[{"name":"sdb","size":"14.9G","type":"disk","tran":"usb",'
    .'"vendor":"FakeVend","model":"FakeModel","children":[{"name":"sdb1","type":"part"}]}]}';
    private const LSBLK_VENTOY = '{"blockdevices":[{"name":"sdb","size":"14.9G","type":"disk","tran":"usb",'
    .'"vendor":"FakeVend","model":"FakeModel","children":[{"name":"sdb1","type":"part"},'
    .'{"name":"sdb2","type":"part"}]}]}';
    private string $configPath;

    public function testDownloadDebianIsoCorruptCacheRedownloads(): void
    {
        [$command, $fake] = $this->makeCommand();
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        mkdir($cacheDir, 0755, true);
        touch("$cacheDir/$iso");
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        // first sha256sum call sees the corrupt cache, the second verifies the re-download
        $fake->on('sha256sum', 0, "badbad  $cacheDir/$iso\n");
        $fake->on('sha256sum', 0, "abc123  $cacheDir/$iso\n");

        $dest = self::invoke($command, 'downloadDebianIso', 'standard', new BufferedOutput(), $cacheDir);

        self::assertSame("$cacheDir/$iso", $dest);
        self::assertTrue($fake->ran('curl -fL -# -o'));
        @unlink("$cacheDir/$iso");
        @rmdir($cacheDir);
    }

    /**
     * @return array{TestableUsbSetupCommand, FakeProcessRunner}
     */
    private function makeCommand(): array
    {
        $fake = new FakeProcessRunner();
        $command = new TestableUsbSetupCommand($this->configPath, $fake);
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        (new ReflectionProperty(UsbSetupCommand::class, 'io'))->setValue($command, $io);

        return [$command, $fake];
    }

    private static function invoke(object $command, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(UsbSetupCommand::class, $method))->invoke($command, ...$args);
    }

    public function testDownloadDebianIsoFreshDownload(): void
    {
        [$command, $fake] = $this->makeCommand();
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        $fake->on('sha256sum', 0, "abc123  $cacheDir/$iso\n");

        $dest = self::invoke($command, 'downloadDebianIso', 'standard', new BufferedOutput(), $cacheDir);

        self::assertSame("$cacheDir/$iso", $dest);
        self::assertTrue($fake->ran('curl -fL -# -o'));
        @rmdir($cacheDir);
    }

    public function testDownloadDebianIsoListingFailureThrows(): void
    {
        [$command, $fake] = $this->makeCommand();
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $fake->on('curl -fsSL', 22, '');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to fetch Debian ISO listing/');
            self::invoke($command, 'downloadDebianIso', 'standard', new BufferedOutput(), $cacheDir);
        } finally {
            @rmdir($cacheDir);
        }
    }

    public function testDownloadDebianIsoMissingChecksumThrows(): void
    {
        [$command, $fake] = $this->makeCommand();
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  some-other-file.iso\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/No checksum found/');
            self::invoke($command, 'downloadDebianIso', 'standard', new BufferedOutput(), $cacheDir);
        } finally {
            @rmdir($cacheDir);
        }
    }

    public function testDownloadDebianIsoUsesVerifiedCache(): void
    {
        [$command, $fake] = $this->makeCommand();
        $cacheDir = sys_get_temp_dir().'/usb_setup_iso_test_'.uniqid('', true);
        $iso = 'debian-live-12.5.0-amd64-standard.iso';
        mkdir($cacheDir, 0755, true);
        touch("$cacheDir/$iso");
        $fake->on('curl -fsSL', 0, '<a href="'.$iso.'">'.$iso.'</a>');
        $fake->on('curl -fsSL', 0, "abc123  $iso\n");
        $fake->on('sha256sum', 0, "abc123  $cacheDir/$iso\n");

        $dest = self::invoke($command, 'downloadDebianIso', 'standard', new BufferedOutput(), $cacheDir);

        self::assertSame("$cacheDir/$iso", $dest);
        self::assertFalse($fake->ran('curl -fL -# -o'));
        @unlink("$cacheDir/$iso");
        @rmdir($cacheDir);
    }

    public function testHasVentoyPartitionLsblkEmptyFallsBackToDevNode(): void
    {
        [$command] = $this->makeCommand();

        // lsblk yields nothing → falls back to file_exists('/dev/null2'), which never exists
        self::assertFalse(self::invoke($command, 'hasVentoyPartition', '/dev/null'));
    }

    public function testHasVentoyPartitionOnePartition(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, self::LSBLK_NO_VENTOY);

        self::assertFalse(self::invoke($command, 'hasVentoyPartition', '/dev/sdb'));
    }

    public function testHasVentoyPartitionTwoPartitions(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);

        self::assertTrue(self::invoke($command, 'hasVentoyPartition', '/dev/sdb'));
    }

    public function testInstallVentoyDerivesNvmePartitionNames(): void
    {
        // NVMe regression: partition paths must be nvme0n1p1/p2, never nvme0n11/nvme0n12.
        // Touches no hardware — the runner is fake and waitForPartition is an array lookup.
        [$command, $fake] = $this->makeCommand();
        $command->existingPartitions = ['/dev/nvme0n1p2'];
        $fake->on('bash ./', 0, "Ventoy install finished\n");

        self::invoke(
            $command,
            'installVentoy',
            '/opt/fake/Ventoy2Disk.sh',
            '/dev/nvme0n1',
            new BufferedOutput(),
            false
        );

        self::assertTrue($fake->ran("umount -f '/dev/nvme0n1p1'"));
        self::assertTrue($fake->ran("umount -f '/dev/nvme0n1p2'"));
        self::assertTrue($fake->ran("fuser -km '/dev/nvme0n1p1'"));
        self::assertFalse($fake->ran('nvme0n11'));
        self::assertFalse($fake->ran('nvme0n12'));
    }

    public function testInstallVentoyFullInstallUsesInstallFlag(): void
    {
        [$command, $fake] = $this->makeCommand();
        $command->existingPartitions = ['/dev/null2'];
        $fake->on('bash ./', 0, "Ventoy install finished\n");

        self::invoke($command, 'installVentoy', '/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), false);

        $ventoyCmd = $fake->commands[0];
        self::assertStringContainsString(' -I ', $ventoyCmd);
        self::assertStringNotContainsString(' -u ', $ventoyCmd);
        self::assertStringContainsString("'/dev/null'", $ventoyCmd);
        self::assertTrue($fake->ran('nsenter -t 1 --mount -- umount -f'));
        self::assertTrue($fake->ran('fuser -km'));
        self::assertTrue($fake->ran('partprobe'));
    }

    public function testInstallVentoyMissingVtoyefiPartitionFails(): void
    {
        [$command, $fake] = $this->makeCommand();
        $command->existingPartitions = []; // partition 2 never appears
        $fake->on('bash ./', 0, "Ventoy install finished\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never appeared/');
        self::invoke($command, 'installVentoy', '/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), false);
    }

    public function testInstallVentoyToolFailureMessageFails(): void
    {
        [$command, $fake] = $this->makeCommand();
        // Ventoy2Disk.sh swallows the worker's exit code — exit 0 with this message is a failure
        $fake->on('bash ./', 0, "Some tools can not run\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/log\.txt/');
        self::invoke($command, 'installVentoy', '/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), false);
    }

    public function testInstallVentoyUpdateUsesUpdateFlag(): void
    {
        [$command, $fake] = $this->makeCommand();
        $command->existingPartitions = ['/dev/null2'];
        $fake->on('bash ./', 0, "Ventoy update finished\n");

        self::invoke($command, 'installVentoy', '/opt/fake/Ventoy2Disk.sh', '/dev/null', new BufferedOutput(), true);

        self::assertStringContainsString(' -u ', $fake->commands[0]);
        self::assertStringNotContainsString(' -I ', $fake->commands[0]);
    }

    public function testIsFat32VentoyMatch(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('blkid -o value -s TYPE', 0, "vfat\n");
        $fake->on('blkid -o value -s LABEL', 0, "VENTOY\n");

        self::assertTrue(self::invoke($command, 'isFat32Ventoy', '/dev/sdb1'));
    }

    public function testIsFat32VentoyTypeProbeFailureShortCircuits(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('blkid', 2, '');

        self::assertFalse(self::invoke($command, 'isFat32Ventoy', '/dev/sdb1'));
        $blkidCalls = array_filter($fake->commands, static fn(string $c): bool => str_contains($c, 'blkid'));
        self::assertCount(1, $blkidCalls);
    }

    public function testIsFat32VentoyWrongLabel(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('blkid -o value -s TYPE', 0, "vfat\n");
        $fake->on('blkid -o value -s LABEL', 0, "OTHER\n");

        self::assertFalse(self::invoke($command, 'isFat32Ventoy', '/dev/sdb1'));
    }

    public function testIsFat32VentoyWrongType(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('blkid -o value -s TYPE', 0, "exfat\n");

        self::assertFalse(self::invoke($command, 'isFat32Ventoy', '/dev/sdb1'));
    }

    public function testLsblkInfoEmptyOutputIsEmpty(): void
    {
        [$command] = $this->makeCommand();

        self::assertSame([], self::invoke($command, 'lsblkInfo', '/dev/sdb'));
    }

    public function testLsblkInfoNonJsonOutputIsEmpty(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, 'not json at all');

        self::assertSame([], self::invoke($command, 'lsblkInfo', '/dev/sdb'));
    }

    public function testLsblkInfoNonzeroExitIsEmpty(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 32, '');

        self::assertSame([], self::invoke($command, 'lsblkInfo', '/dev/sdb'));
    }

    public function testLsblkInfoParsesFirstBlockdevice(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk -J -o NAME,', 0, self::LSBLK_VENTOY);

        $info = self::invoke($command, 'lsblkInfo', '/dev/sdb');

        self::assertSame('sdb', $info['name']);
        self::assertSame('FakeVend', $info['vendor']);
        self::assertCount(2, $info['children']);
    }

    public function testMirrorDryRunFailureThrows(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('--dry-run', 12, '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dry run failed/');
        self::invoke(
            $command,
            'mirrorDataPartition',
            '/tmp/src',
            '/tmp/dst',
            true,
            false,
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper()
        );
    }

    private function interactiveInput(string $answers): ArrayInput
    {
        $input = new ArrayInput([]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive(true);

        return $input;
    }

    public function testMirrorRsyncFailureThrows(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('rsync', 23, '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rsync failed \(exit 23\)/');
        self::invoke(
            $command,
            'mirrorDataPartition',
            '/tmp/src',
            '/tmp/dst',
            false,
            false,
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper()
        );
    }

    public function testMirrorWithDeleteConfirmedRunsRealRsync(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('--dry-run', 0, "*deleting foo\n");

        $result = self::invoke(
            $command,
            'mirrorDataPartition',
            '/tmp/src',
            '/tmp/dst',
            true,
            false,
            $this->interactiveInput("yes\n"),
            new BufferedOutput(),
            new QuestionHelper()
        );

        self::assertTrue($result);
        self::assertCount(2, $fake->commands);
        self::assertStringNotContainsString('--dry-run', $fake->commands[1]);
        self::assertStringContainsString('--delete', $fake->commands[1]);
    }

    public function testMirrorWithDeleteDeclinedStopsBeforeRealRsync(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('--dry-run', 0, "*deleting foo\n");

        $result = self::invoke(
            $command,
            'mirrorDataPartition',
            '/tmp/src',
            '/tmp/dst',
            true,
            false,
            $this->interactiveInput("no\n"),
            new BufferedOutput(),
            new QuestionHelper()
        );

        self::assertFalse($result);
        self::assertCount(1, $fake->commands);
        self::assertStringContainsString('--dry-run', $fake->commands[0]);
    }

    public function testMirrorWithDeleteSkipConfirmSkipsPrompt(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('--dry-run', 0, "*deleting foo\n");

        $result = self::invoke(
            $command,
            'mirrorDataPartition',
            '/tmp/src',
            '/tmp/dst',
            true,
            true,
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper()
        );

        self::assertTrue($result);
        self::assertCount(2, $fake->commands);
    }

    public function testMirrorWithoutDeleteRunsSingleRsync(): void
    {
        [$command, $fake] = $this->makeCommand();

        $result = self::invoke(
            $command,
            'mirrorDataPartition',
            '/tmp/src',
            '/tmp/dst',
            false,
            false,
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper()
        );

        self::assertTrue($result);
        $expected = (new ReflectionMethod(UsbSetupCommand::class, 'buildRsyncCommand'))
            ->invoke(null, '/tmp/src', '/tmp/dst', false, false);
        self::assertSame([$expected], $fake->commands);
    }

    public function testTargetDataCapacityBytesFallsBackToWholeDevice(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('blockdev --getsize64 \'/dev/sdb1\'', 1, '');
        $fake->on('blockdev --getsize64 \'/dev/sdb\'', 0, "16000000000\n");

        // whole-device estimate = size minus the 33 MiB Ventoy reserve
        self::assertSame(15965396992, self::invoke($command, 'targetDataCapacityBytes', '/dev/sdb'));
    }

    public function testTargetDataCapacityBytesFromPartition(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('blockdev --getsize64 \'/dev/sdb1\'', 0, "15000000000\n");

        self::assertSame(15000000000, self::invoke($command, 'targetDataCapacityBytes', '/dev/sdb'));
        self::assertCount(1, $fake->commands);
    }

    public function testTargetDataCapacityBytesUnknownIsZero(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('blockdev', 1, '');

        self::assertSame(0, self::invoke($command, 'targetDataCapacityBytes', '/dev/sdb'));
    }

    public function testValidateSourceDeviceHappyPath(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $fake->on('blkid -o value -s TYPE', 0, "vfat\n");
        $fake->on('blkid -o value -s LABEL', 0, "VENTOY\n");

        $result = self::invoke(
            $command,
            'validateSourceDevice',
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            '/dev/zero',
            '/dev/null',
            [],
            false
        );

        self::assertIsString($result);
        self::assertStringContainsString('/dev/zero', $result);
        $config = json_decode((string)file_get_contents($this->configPath), true);
        self::assertSame('usb FakeVend FakeModel', $config['source_device_name'] ?? null);
    }

    public function testValidateSourceDeviceMissingDeviceFails(): void
    {
        [$command] = $this->makeCommand();

        $result = self::invoke(
            $command,
            'validateSourceDevice',
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            '/dev/doesnotexist',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::FAILURE, $result);
    }

    public function testValidateSourceDeviceNonFat32Confirmed(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $fake->on('blkid -o value -s TYPE', 0, "exfat\n");

        $result = self::invoke(
            $command,
            'validateSourceDevice',
            $this->interactiveInput("yes\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            '/dev/zero',
            '/dev/null',
            [],
            false
        );

        self::assertIsString($result);
    }

    public function testValidateSourceDeviceNonFat32Declined(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $fake->on('blkid -o value -s TYPE', 0, "exfat\n");

        $result = self::invoke(
            $command,
            'validateSourceDevice',
            $this->interactiveInput("no\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            '/dev/zero',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::SUCCESS, $result);
    }

    public function testValidateSourceDeviceSameAsTargetFails(): void
    {
        [$command] = $this->makeCommand();

        $result = self::invoke(
            $command,
            'validateSourceDevice',
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            '/dev/null',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::FAILURE, $result);
    }

    public function testValidateSourceDeviceSwappedLettersDeclined(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);

        // Previous run recorded today's TARGET as its source → swapped-letters warning
        $result = self::invoke(
            $command,
            'validateSourceDevice',
            $this->interactiveInput("no\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            '/dev/zero',
            '/dev/null',
            ['source_device' => '/dev/null'],
            false
        );

        self::assertSame(Command::SUCCESS, $result);
    }

    public function testValidateSourceDeviceWithoutVentoyFails(): void
    {
        [$command, $fake] = $this->makeCommand();
        $fake->on('lsblk', 0, self::LSBLK_NO_VENTOY);

        $result = self::invoke(
            $command,
            'validateSourceDevice',
            $this->interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            '/dev/zero',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::FAILURE, $result);
    }

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'usb_setup_process_test_').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @unlink(substr($this->configPath, 0, -strlen('.json')));
    }
}
