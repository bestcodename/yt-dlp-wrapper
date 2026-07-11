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

    public function testDeviceNameMismatchDeclinedAborts(): void
    {
        file_put_contents(
            $this->configPath,
            json_encode(['device' => '/dev/null', 'device_name' => 'usb OldVendor OldModel'])."\n"
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
            json_encode(['device' => '/dev/null', 'device_name' => 'usb OldVendor OldModel'])."\n"
        );
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // confirm mismatch, then same flow as the clobber test
        $tester->setInputs(['yes', '', '1', '', '2', '-', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('now shows as "usb FakeVend FakeModel"', $display);
        self::assertStringContainsString('was "usb OldVendor OldModel" last time', $display);
        self::assertSame('usb FakeVend FakeModel', $this->savedConfig()['device_name'] ?? null);
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
        self::assertSame('usb FakeVend FakeModel', $config['device_name'] ?? null);
        self::assertSame('/dev/null', $config['device'] ?? null);
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
            self::assertStringContainsString('ALL DATA ON /dev/null WILL BE ERASED', $display);
        } else {
            self::assertStringContainsString('USB Setup — Update Summary', $display);
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
        // no Ventoy anywhere: neutral label, no Ventoy boot hint
        self::assertTrue($fake->ran("mkfs.fat -F 32 -n 'USBDATA'"));
        self::assertStringContainsString('no Ventoy on this stick', $display);
        self::assertStringNotContainsString('boot them with Ventoy', $display);
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
