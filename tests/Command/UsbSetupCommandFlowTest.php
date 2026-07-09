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
 * ISO → downloads file → [update confirm | 2× wipe confirm].
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

    private function makeFake(string $lsblkJson): FakeProcessRunner
    {
        return (new FakeProcessRunner())
            ->on('lsblk -J -o NAME,', 0, $lsblkJson)
            ->on('which ', 0, "/usr/bin/stub\n")
            ->on('blkid -o value -s TYPE', 0, "vfat\n")
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

    public function testDownloadsPromptSkippedWhenConfigured(): void
    {
        file_put_contents($this->configPath, json_encode(['download_sources' => '-'])."\n");
        $tester = $this->makeTester($this->makeFake(self::LSBLK_VENTOY));

        // same flow as the clobber test but WITHOUT a downloads-file answer — no prompt expected
        $tester->setInputs(['', '1', '', '2', 'yes']);
        $exit = $tester->execute(['--device' => '/dev/null'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Software downloads disabled in config', $this->display($tester));
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

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'usb_setup_flow_test_').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @unlink(substr($this->configPath, 0, -strlen('.json')));
    }
}
