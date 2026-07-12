<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Support\FakeProcessRunner;
use App\Tests\Support\TestableUsbSetupCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Interactive usb:setup regression tests through CommandTester. All runs target /dev/null: it
 * passes the device gates but never appears in /proc/1/mounts, and every case answers the
 * ISO/downloads prompts with skip so the mount/copy block is never entered — the only real
 * filesystem write is the temp config file.
 *
 * Prompt order: [name-mismatch confirm] → [mode, unless --update] → Ventoy → payload →
 * ISO → downloads (ChoiceQuestion once download_sources is configured; free-text on first
 * run) → [update confirm | 2× wipe confirm] → [update-mode reformat confirm if not FAT32].
 */
final class UsbSetupCommandFlowTest extends TestCase
{
    private const LSBLK_NO_VENTOY = '{"blockdevices":[{"name":"null","size":"14.9G","type":"disk","tran":"usb",'
    .'"vendor":"FakeVend","model":"FakeModel","children":[{"name":"null1","type":"part"}]}]}';
    private const LSBLK_TWO_DISKS = '{"blockdevices":[{"name":"null","size":"14.9G","type":"disk","tran":"usb",'
    .'"vendor":"FakeVend","model":"FakeModel"},{"name":"zero","size":"32G","type":"disk","tran":"usb",'
    .'"vendor":"OtherVend","model":"OtherModel"}]}';
    private const LSBLK_VENTOY = '{"blockdevices":[{"name":"null","size":"14.9G","type":"disk","tran":"usb",'
    .'"vendor":"FakeVend","model":"FakeModel","children":[{"name":"null1","type":"part"},'
    .'{"name":"null2","type":"part"}]}]}';
    private TestableUsbSetupCommand $command;
    private string $configPath;

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function modeDefaultProvider(): array
    {
        return [
            'Ventoy detected defaults to update' => [
                self::LSBLK_VENTOY,
                ['', '1', '', '2', '-', 'yes'],
                'update (skip completed steps)',
            ],
            'no Ventoy defaults to scratch' => [
                self::LSBLK_NO_VENTOY,
                ['', '1', '', '2', '-', 'yes', 'yes'],
                'redo from scratch',
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function ventoyFlagProvider(): array
    {
        return [
            'Ventoy missing falls back to full install (-I)' => [
                self::LSBLK_NO_VENTOY,
                ' -I ',
                'Ventoy installed (MBR).',
            ],
            'Ventoy present updates in place (-u)' => [
                self::LSBLK_VENTOY,
                ' -u ',
                'Ventoy updated.',
            ],
        ];
    }

    public function testConfigurationPathCopiesIsoPersistenceAndSoftwareOntoStick(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $iso = tempnam(sys_get_temp_dir(), 'usb_setup_flow_iso_').'.iso';
        file_put_contents($iso, 'fake-iso-bytes');
        $softwareFile = tempnam(sys_get_temp_dir(), 'usb_setup_flow_software_');
        file_put_contents($softwareFile, 'fake-installer-bytes');
        $downloadsFile = tempnam(sys_get_temp_dir(), 'usb_setup_flow_downloads_');
        file_put_contents($downloadsFile, $softwareFile."\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null1'];

        try {
            // scratch mode, skip Ventoy, payload=configuration (default), ISO="local path",
            // ISO path, persistence size, downloads file, then 2x wipe confirm
            $tester->setInputs(['1', '1', '', '1', $iso, '64', $downloadsFile, 'yes', 'yes']);
            $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

            self::assertSame(Command::SUCCESS, $exit);
            $display = $this->display($tester);
            self::assertStringContainsString('Partition 1 formatted as FAT32.', $display);
            self::assertStringNotContainsString('Data partition mirrored.', $display); // not duplicate mode
            self::assertStringContainsString('USB stick(s) ready.', $display);
            self::assertStringContainsString('1 software installer(s) copied to /software/', $display);
            self::assertTrue($fake->ran('cp --no-preserve=all '.escapeshellarg($iso)));
            self::assertTrue($fake->ran('fallocate'));
            self::assertTrue($fake->ran('mkfs.ext4'));
            self::assertTrue($fake->ran('cp --no-preserve=all '.escapeshellarg($softwareFile)));

            $mount = sys_get_temp_dir().'/usb_setup_'.getmypid().'_dst_null';
            self::assertFileExists($mount.'/ventoy/ventoy.json');
            $ventoyJson = json_decode((string)file_get_contents($mount.'/ventoy/ventoy.json'), true);
            self::assertSame('/'.basename($iso), $ventoyJson['persistence'][0]['image'] ?? null);
        } finally {
            self::cleanupMountPoints();
            @unlink($iso);
            @unlink($softwareFile);
            @unlink($downloadsFile);
        }
    }

    private function makeFake(string $lsblkJson, string $fstype = 'vfat'): FakeProcessRunner
    {
        return (new FakeProcessRunner())
            ->on('lsblk -J -o NAME,', 0, $lsblkJson)
            ->on('which ', 0, "/usr/bin/stub\n")
            ->on('blkid -o value -s TYPE', 0, "$fstype\n")
            ->on('blkid -o value -s LABEL', 0, "VENTOY\n")
            ->on('bash ./', 0, "Ventoy install finished\n");
    }

    private function makeTester(FakeProcessRunner $fake): CommandTester
    {
        $app = new Application();
        $this->command = new TestableUsbSetupCommand($this->configPath, $fake);
        $app->addCommand($this->command);

        return new CommandTester($this->command);
    }

    private function display(CommandTester $tester): string
    {
        return (string)preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private static function cleanupMountPoints(): void
    {
        foreach (['', '_src', '_dst_null', '_dst_zero', '_dst_urandom'] as $suffix) {
            self::rmrfMountSuffix($suffix);
        }
        self::rmrf(sys_get_temp_dir().'/persist_'.getmypid());
    }

    /**
     * usb_setup_<pid>[_suffix] mount points are deterministic (PartitionFormatter::mount ties
     * them to getmypid(), not a per-call unique id), so any test that actually writes real
     * content into the mount block must clean up afterwards or it leaks into the next test that
     * mounts to the same path within this process.
     */
    private static function rmrfMountSuffix(string $suffix): void
    {
        self::rmrf(sys_get_temp_dir().'/usb_setup_'.getmypid().$suffix);
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

    public function testConfigurationPayloadCapacityWarningDeclinedAborts(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $payload = tempnam(sys_get_temp_dir(), 'usb_setup_flow_payload_');
        file_put_contents($payload, str_repeat('x', 64));
        $downloadsFile = tempnam(sys_get_temp_dir(), 'usb_setup_flow_downloads_');
        file_put_contents($downloadsFile, $payload."\n");

        $fake = $this->makeFake(self::LSBLK_VENTOY)
            ->on('blockdev --getsize64', 0, "67108864\n"); // 64 MiB — below the fitsOnTarget margin
        $tester = $this->makeTester($fake);

        try {
            // mode default, skip Ventoy, payload default, ISO skip, downloads file, proceed,
            // then DECLINE the capacity warning
            $tester->setInputs(['', '1', '', '2', $downloadsFile, 'yes', 'no']);
            $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

            self::assertSame(Command::SUCCESS, $exit);
            $display = $this->display($tester);
            self::assertStringContainsString('may not fit on the target data partition', $display);
            self::assertStringContainsString('Aborted.', $display);
            self::assertFalse($fake->ran('mount '));
            self::assertFalse($fake->ran('bash ./'));
        } finally {
            @unlink($payload);
            @unlink($downloadsFile);
        }
    }

    public function testDeviceListRejectsDuplicateEntry(): void
    {
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(['--device' => '/dev/null,/dev/null'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString(
            'Duplicate device(s) in --device / USB_DEVICE: /dev/null',
            $this->display($tester)
        );
        self::assertFalse($fake->ran('mount '));
    }

    public function testDeviceNameMismatchDeclinedAborts(): void
    {
        file_put_contents(
            $this->configPath,
            json_encode(['dev_null_device' => '/dev/null', 'dev_null_device_name' => 'usb OldVendor OldModel'])."\n"
        );
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        $tester->setInputs(['no']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Aborted.', $this->display($tester));
    }

    public function testDeviceNameMismatchWarnsAndRecordsNewName(): void
    {
        file_put_contents(
            $this->configPath,
            json_encode(['dev_null_device' => '/dev/null', 'dev_null_device_name' => 'usb OldVendor OldModel'])."\n"
        );
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // confirm mismatch, then same flow as the clobber test
        $tester->setInputs(['yes', '', '1', '', '2', '-', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('now shows as "usb FakeVend FakeModel"', $display);
        self::assertStringContainsString('was "usb OldVendor OldModel" last time', $display);
        self::assertSame('usb FakeVend FakeModel', $this->savedConfig()['dev_null_device_name'] ?? null);
    }

    private function savedConfig(): array
    {
        return json_decode((string)file_get_contents($this->configPath), true);
    }

    public function testDeviceNameSurvivesFinalConfigSave(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // mode default (update), skip Ventoy, payload default, ISO skip, no downloads, proceed
        $tester->setInputs(['', '1', '', '2', '-', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $config = $this->savedConfig();
        // device_name is written mid-run; the final batched save must merge, not clobber it
        self::assertSame('usb FakeVend FakeModel', $config['dev_null_device_name'] ?? null);
        self::assertSame(['/dev/null'], $config['devices'] ?? null);
        self::assertFalse($config['install_ventoy'] ?? null);
        self::assertSame('configuration', $config['payload_source'] ?? null);
        self::assertArrayHasKey('persistence_mib', $config);
        // answering '-' persists the skip so the downloads prompt is never asked again
        self::assertSame('-', $config['download_sources'] ?? null);
    }

    public function testDownloadsChoiceDefaultsToCopyFromSavedPath(): void
    {
        $downloadsFile = tempnam(sys_get_temp_dir(), 'usb_setup_flow_downloads_');
        file_put_contents($this->configPath, json_encode(['download_sources' => $downloadsFile])."\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        try {
            // empty answer accepts the 'copy software from <saved path>' default; the file is
            // empty so nothing is queued and the mount block is never entered
            $tester->setInputs(['', '1', '', '2', '', 'yes']);
            $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

            self::assertSame(Command::SUCCESS, $exit);
            $display = $this->display($tester);
            self::assertStringContainsString("copy software from $downloadsFile", $display);
            self::assertStringContainsString("queued from $downloadsFile", $display);
            self::assertSame($downloadsFile, $this->savedConfig()['download_sources'] ?? null);
        } finally {
            @unlink($downloadsFile);
        }
    }

    public function testDownloadsChoiceDefaultsToSkipWhenDisabledInConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['download_sources' => '-'])."\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // same flow as the clobber test; empty answer accepts the 'skip software downloads' default
        $tester->setInputs(['', '1', '', '2', '', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Software downloads:', $display);
        self::assertStringContainsString('skip software downloads', $display);
        self::assertStringNotContainsString('queued from', $display);
        self::assertSame('-', $this->savedConfig()['download_sources'] ?? null);
    }

    public function testDownloadsChoiceDisabledConfigCanEnableViaFreeText(): void
    {
        $downloadsFile = tempnam(sys_get_temp_dir(), 'usb_setup_flow_downloads_');
        file_put_contents($this->configPath, json_encode(['download_sources' => '-'])."\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        try {
            // '1' selects 'copy software from a downloads file', then the free-text question
            $tester->setInputs(['', '1', '', '2', '1', $downloadsFile, 'yes']);
            $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

            self::assertSame(Command::SUCCESS, $exit);
            $display = $this->display($tester);
            self::assertStringContainsString('Software downloads file', $display);
            self::assertStringContainsString("queued from $downloadsFile", $display);
            self::assertSame($downloadsFile, $this->savedConfig()['download_sources'] ?? null);
        } finally {
            @unlink($downloadsFile);
        }
    }

    public function testDownloadsChoiceSkipReplacesSavedPathWithDash(): void
    {
        $downloadsFile = tempnam(sys_get_temp_dir(), 'usb_setup_flow_downloads_');
        file_put_contents($this->configPath, json_encode(['download_sources' => $downloadsFile])."\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        try {
            // '2' selects 'skip software downloads' despite the saved path
            $tester->setInputs(['', '1', '', '2', '2', 'yes']);
            $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertStringNotContainsString('queued from', $this->display($tester));
            self::assertSame('-', $this->savedConfig()['download_sources'] ?? null);
        } finally {
            @unlink($downloadsFile);
        }
    }

    public function testDuplicateModeMirrorsSourceStickOntoTarget(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null1', '/dev/zero1'];

        try {
            // scratch mode, skip Ventoy, [no payload prompt: --source-device pre-set],
            // [no ISO/persistence/downloads prompts: duplicate mode skips them], 2x wipe confirm
            $tester->setInputs(['1', '1', 'yes', 'yes']);
            $exit = $tester->execute(
                ['--device' => '/dev/null', '--source-device' => '/dev/zero'],
                ['interactive' => true]
            );

            self::assertSame(Command::SUCCESS, $exit);
            $display = $this->display($tester);
            self::assertStringContainsString('Partition 1 formatted as FAT32.', $display);
            self::assertStringContainsString('Data partition mirrored.', $display);
            self::assertStringContainsString('USB stick(s) ready.', $display);
            self::assertStringContainsString('Payload duplicated from /dev/zero.', $display);
            self::assertTrue($fake->ran('rsync -rt'));
            self::assertFalse($fake->ran('--dry-run')); // scratch mode: no delete pass needed
        } finally {
            self::cleanupMountPoints();
        }
    }

    public function testEnvDeviceActsLikeCliOption(): void
    {
        putenv('USB_DEVICE=/dev/null');
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));
        $this->command->existingPartitions = ['/dev/null1', '/dev/null2'];

        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringNotContainsString('--device is required', $this->display($tester));
    }

    public function testInstallVentoyParamSuppressesPrompt(): void
    {
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // mode default, [no Ventoy prompt], payload default, ISO skip, no downloads, proceed
        $tester->setInputs(['', '', '2', '-', 'yes']);
        $exit = $tester->execute(
            ['--device' => '/dev/null', '--install-ventoy' => 'no'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringNotContainsString('Ventoy:', $display);
        self::assertStringContainsString('Ventoy install skipped.', $display);
        self::assertFalse($this->savedConfig()['install_ventoy'] ?? null);
    }

    public function testInteractiveDevicePromptWhenOptionOmitted(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // device prompt (no lsblk -d listing stubbed => falls back to free-text entry), then
        // mode default (update, Ventoy detected), skip Ventoy, payload default, ISO skip,
        // no downloads, single update confirm
        $tester->setInputs(['/dev/null', '', '1', '', '2', '-', 'yes']);
        $exit = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringNotContainsString('--device is required', $display);
        self::assertSame(['/dev/null'], $this->savedConfig()['devices'] ?? null);
    }

    public function testInteractiveMultiSelectDevicePromptWhenOptionOmitted(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY)->on('lsblk -J -d', 0, self::LSBLK_TWO_DISKS);
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null1', '/dev/zero1'];

        // multiselect device prompt (both disks), scratch mode, skip Ventoy, payload default,
        // ISO skip, no downloads, 2x wipe confirm
        $tester->setInputs(['0,1', '1', '1', '', '2', '-', 'yes', 'yes']);
        $exit = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['/dev/null', '/dev/zero'], $this->savedConfig()['devices'] ?? null);
    }

    public function testInvalidDeviceAmongMultipleFailsWholeRunBeforeAnyWrite(): void
    {
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(['--device' => '/dev/null,/dev/does-not-exist'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Device not found: /dev/does-not-exist', $this->display($tester));
        self::assertFalse($fake->ran('mount '));
        self::assertFalse($fake->ran('mkfs.fat'));
        self::assertFalse($fake->ran('bash ./'));
    }

    public function testInvalidIsoVariantFails(): void
    {
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        $exit = $tester->execute(
            ['--device' => '/dev/null', '--iso-variant' => 'bogus'],
            ['interactive' => false]
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString(
            'Invalid --iso-variant / USB_ISO_VARIANT value: bogus',
            $this->display($tester)
        );
    }

    public function testLegacySingularDeviceConfigUsedAsDevicesPromptDefault(): void
    {
        file_put_contents($this->configPath, json_encode(['device' => '/dev/null'])."\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // blank answer accepts the legacy single `device` as the free-text default (no lsblk -d
        // stub => falls back to free-text entry), then mode default (update, Ventoy detected),
        // skip Ventoy, payload default, ISO skip, no downloads, single update confirm
        $tester->setInputs(['', '', '1', '', '2', '-', 'yes']);
        $exit = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['/dev/null'], $this->savedConfig()['devices'] ?? null);
    }

    /**
     * @param list<string> $inputs
     */
    #[DataProvider('modeDefaultProvider')]
    public function testModeDefaultFollowsDetectedStickState(
        string $lsblkJson,
        array $inputs,
        string $expectedMode
    ): void {
        file_put_contents($this->configPath, "{}\n");
        $tester = $this->makeTester($this->makeFake($lsblkJson));
        // scratch mode reformats even with Ventoy skipped — partition 1 must "appear"
        $this->command->existingPartitions = ['/dev/null1'];

        // empty first input accepts the ChoiceQuestion default derived from the detected state
        $tester->setInputs($inputs);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString($expectedMode, $display);
        if ($expectedMode === 'redo from scratch') {
            self::assertStringContainsString('ALL DATA ON THE FOLLOWING DEVICE(S) WILL BE ERASED: /dev/null', $display);
        } else {
            self::assertStringContainsString('USB Setup — Update Summary', $display);
        }
    }

    public function testMultipleDevicesConfigurationPathCopiesIsoOntoEachDevice(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $iso = tempnam(sys_get_temp_dir(), 'usb_setup_flow_iso_').'.iso';
        file_put_contents($iso, 'fake-iso-bytes');
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null1', '/dev/zero1'];

        try {
            // scratch mode, skip Ventoy, payload default, ISO local path, persistence,
            // no downloads, 2x wipe confirm
            $tester->setInputs(['1', '1', '', '1', $iso, '64', '-', 'yes', 'yes']);
            $exit = $tester->execute(['--device' => '/dev/null,/dev/zero'], ['interactive' => true]);

            self::assertSame(Command::SUCCESS, $exit);
            $display = $this->display($tester);
            self::assertStringContainsString('USB stick(s) ready.', $display);
            self::assertSame(2, substr_count($display, 'Partition 1 formatted as FAT32.'));

            $mountNull = sys_get_temp_dir().'/usb_setup_'.getmypid().'_dst_null';
            $mountZero = sys_get_temp_dir().'/usb_setup_'.getmypid().'_dst_zero';
            self::assertFileExists($mountNull.'/ventoy/ventoy.json');
            self::assertFileExists($mountZero.'/ventoy/ventoy.json');
            self::assertSame(['/dev/null', '/dev/zero'], $this->savedConfig()['devices'] ?? null);
        } finally {
            self::cleanupMountPoints();
            @unlink($iso);
        }
    }

    public function testMultipleDevicesDuplicateModeMirrorsSourceOntoEachTarget(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null1', '/dev/zero1', '/dev/urandom1'];

        try {
            // scratch mode, skip Ventoy, [no payload prompt: --source-device pre-set],
            // [no ISO/persistence/downloads prompts], 2x wipe confirm
            $tester->setInputs(['1', '1', 'yes', 'yes']);
            $exit = $tester->execute(
                ['--device' => '/dev/null,/dev/zero', '--source-device' => '/dev/urandom'],
                ['interactive' => true]
            );

            self::assertSame(Command::SUCCESS, $exit);
            $display = $this->display($tester);
            self::assertSame(2, substr_count($display, 'Data partition mirrored.'));
            self::assertStringContainsString('Payload duplicated from /dev/urandom.', $display);
            // source is mounted twice total (once for the up-front capacity preflight, once
            // shared across the whole write loop) — NOT once per target device (would be 3)
            self::assertSame(
                2,
                count(
                    array_filter(
                        $fake->commands,
                        static fn(string $c): bool => str_contains($c, "mount -o ro '/dev/urandom1'")
                    )
                )
            );
        } finally {
            self::cleanupMountPoints();
        }
    }

    public function testMultipleDevicesMixedOutcomeOneFailsOneSucceedsReturnsFailure(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);
        // only /dev/null1 "appears" — /dev/zero1 never does, so its reformat step times out
        $this->command->existingPartitions = ['/dev/null1'];

        try {
            // scratch mode, skip Ventoy, payload default, ISO skip, no downloads, 2x wipe confirm
            $tester->setInputs(['1', '1', '', '2', '-', 'yes', 'yes']);
            $exit = $tester->execute(['--device' => '/dev/null,/dev/zero'], ['interactive' => true]);

            self::assertSame(Command::FAILURE, $exit);
            $display = $this->display($tester);
            self::assertStringContainsString('USB Setup — Results', $display);
            self::assertMatchesRegularExpression('#/dev/null\s+OK#', $display);
            self::assertStringContainsString('FAILED', $display);
            self::assertStringContainsString('did not appear within', $display);
            self::assertTrue($fake->ran("mkfs.fat -F 32 -n 'VENTOY'"));
        } finally {
            self::cleanupMountPoints();
        }
    }

    public function testNonInteractiveWithoutDeviceFails(): void
    {
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('--device is required', $this->display($tester));
    }

    public function testScratchSkipVentoyReformatsFat32(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_NO_VENTOY);
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null1'];

        // mode default (scratch), skip Ventoy, payload default, ISO skip, no downloads, 2× wipe confirm
        $tester->setInputs(['', '1', '', '2', '-', 'yes', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Ventoy install skipped.', $display);
        self::assertStringContainsString('Partition 1 formatted as FAT32.', $display);
        // no Ventoy anywhere: neutral label
        self::assertTrue($fake->ran("mkfs.fat -F 32 -n 'USBDATA'"));
        self::assertStringContainsString('Copy files onto the data partition (FAT32) of each stick', $display);
        self::assertFalse($fake->ran('bash ./'));
    }

    #[DataProvider('ventoyFlagProvider')]
    public function testUpdateModeVentoyFlagFollowsStickState(
        string $lsblkJson,
        string $expectedFlag,
        string $expectedMessage
    ): void {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake($lsblkJson);
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null2'];

        // Ventoy default (install/update), payload default, ISO skip, no downloads, proceed
        $tester->setInputs(['', '', '2', '-', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null', '--update' => true], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString($expectedMessage, $display);
        if ($expectedFlag === ' -I ') {
            self::assertStringContainsString('full Ventoy install is required', $display);
        }

        $ventoyCmds = array_values(
            array_filter(
                $fake->commands,
                static fn(string $cmd): bool => str_contains($cmd, 'bash ./')
            )
        );
        self::assertCount(1, $ventoyCmds);
        self::assertStringContainsString($expectedFlag, $ventoyCmds[0]);
        self::assertStringNotContainsString($expectedFlag === ' -I ' ? ' -u ' : ' -I ', $ventoyCmds[0]);
        self::assertStringContainsString("'/dev/null'", $ventoyCmds[0]);
    }

    public function testUpdateNonFat32ReformatAcceptedRuns(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY, 'exfat');
        $tester = $this->makeTester($fake);
        $this->command->existingPartitions = ['/dev/null1'];

        // same flow but ACCEPT the reformat prompt
        $tester->setInputs(['', '1', '', '2', '-', 'yes', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Partition 1 formatted as FAT32.', $this->display($tester));
        // Ventoy still on the stick — the VENTOY label is kept
        self::assertTrue($fake->ran("mkfs.fat -F 32 -n 'VENTOY'"));
    }

    public function testUpdateNonFat32ReformatDeclinedLeavesPartition(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY, 'exfat');
        $tester = $this->makeTester($fake);

        // mode default (update), skip Ventoy, payload default, ISO skip, no downloads,
        // update confirm, then DECLINE the reformat prompt
        $tester->setInputs(['', '1', '', '2', '-', 'yes', 'no']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Partition 1 is not FAT32', $display);
        self::assertStringContainsString('left untouched (not FAT32)', $display);
        self::assertFalse($fake->ran('mkfs.fat -F 32'));
    }

    public function testUpdateSkipVentoyFat32SkipsReformat(): void
    {
        file_put_contents($this->configPath, "{}\n");
        $fake = $this->makeFake(self::LSBLK_VENTOY);
        $tester = $this->makeTester($fake);

        // mode default (update), skip Ventoy, payload default, ISO skip, no downloads, update confirm
        $tester->setInputs(['', '1', '', '2', '-', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('already FAT32 (VENTOY) — skipping reformat.', $display);
        self::assertStringNotContainsString('Partition 1 is not FAT32', $display);
        self::assertFalse($fake->ran('mkfs.fat -F 32'));
    }

    public function testUsbUpdateEnvSkipsModePrompt(): void
    {
        putenv('USB_UPDATE=1');
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // [no mode prompt], skip Ventoy, payload default, ISO skip, no downloads, proceed
        $tester->setInputs(['1', '', '2', '-', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringNotContainsString('Mode:', $display);
        self::assertStringContainsString('update (skip completed steps)', $display);
    }

    public function testUsbYesEnvSkipsConfirmations(): void
    {
        putenv('USB_YES=1');
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // mode default, skip Ventoy, payload default, ISO skip, no downloads — no confirm inputs
        $tester->setInputs(['', '1', '', '2', '-']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringNotContainsString('Proceed with update?', $this->display($tester));
    }

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'usb_setup_flow_test_').'.json';
        // pin DOTENV_PATH to an empty file so a real repo/cwd .env never leaks into the tests
        file_put_contents($this->configPath.'.env', '');
        putenv('DOTENV_PATH='.$this->configPath.'.env');
    }

    protected function tearDown(): void
    {
        foreach (
            [
                'DOTENV_PATH',
                'USB_DEVICE',
                'USB_SOURCE_DEVICE',
                'USB_DEBIAN_ISO',
                'USB_PERSISTENCE_SIZE',
                'USB_DOWNLOADS_FILE',
                'USB_CACHE_DIR',
                'USB_UPDATE',
                'USB_YES',
                'USB_INSTALL_VENTOY',
                'USB_ISO_VARIANT',
            ] as $key
        ) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        @unlink($this->configPath.'.env');
        @unlink($this->configPath);
        @unlink(substr($this->configPath, 0, -strlen('.json')));
    }
}
