<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\Tests\Support\FakeProcessRunner;
use App\UsbSetup\DeviceInspector;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DeviceInspectorTest extends TestCase
{
    private const LSBLK_NO_VENTOY = '{"blockdevices":[{"name":"sdb","size":"14.9G","type":"disk","tran":"usb",'
    .'"vendor":"FakeVend","model":"FakeModel","children":[{"name":"sdb1","type":"part"}]}]}';
    private const LSBLK_VENTOY = '{"blockdevices":[{"name":"sdb","size":"14.9G","type":"disk","tran":"usb",'
    .'"vendor":"FakeVend","model":"FakeModel","children":[{"name":"sdb1","type":"part"},'
    .'{"name":"sdb2","type":"part"}]}]}';

    /**
     * @return array<string, array{array, string, string, string, string}>
     */
    public static function deviceNameCheckOutcomeProvider(): array
    {
        return [
            'empty config is silent' => [[], '', '/dev/sdb', 'usb Vendor Model', 'silent'],
            'different saved device is silent' => [
                ['device' => '/dev/sdc', 'device_name' => 'usb Other Stick'],
                '',
                '/dev/sdb',
                'usb Vendor Model',
                'silent',
            ],
            'matching name is silent' => [
                ['device' => '/dev/sdb', 'device_name' => 'usb Vendor Model'],
                '',
                '/dev/sdb',
                'usb Vendor Model',
                'silent',
            ],
            'saved name null is silent' => [
                ['device' => '/dev/sdb', 'device_name' => null],
                '',
                '/dev/sdb',
                'usb Vendor Model',
                'silent',
            ],
            'saved name empty string is silent' => [
                ['device' => '/dev/sdb', 'device_name' => ''],
                '',
                '/dev/sdb',
                'usb Vendor Model',
                'silent',
            ],
            'same device without recorded name warns missing' => [
                ['device' => '/dev/sdb'],
                '',
                '/dev/sdb',
                'usb Vendor Model',
                'warn_missing',
            ],
            'changed name warns mismatch' => [
                ['device' => '/dev/sdb', 'device_name' => 'usb Old Stick'],
                '',
                '/dev/sdb',
                'usb Vendor Model',
                'warn_mismatch',
            ],
            'source prefix ignores unprefixed keys' => [
                ['device' => '/dev/sdb', 'device_name' => 'usb Old Stick'],
                'source_',
                '/dev/sdb',
                'usb Vendor Model',
                'silent',
            ],
            'source prefix without recorded name warns missing' => [
                ['source_device' => '/dev/sdb'],
                'source_',
                '/dev/sdb',
                'usb Vendor Model',
                'warn_missing',
            ],
            'source prefix changed name warns mismatch' => [
                ['source_device' => '/dev/sdb', 'source_device_name' => 'usb Old Stick'],
                'source_',
                '/dev/sdb',
                'usb Vendor Model',
                'warn_mismatch',
            ],
        ];
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function estimateDataPartitionBytesProvider(): array
    {
        return [
            '16 GB disk minus 33 MiB Ventoy reserve' => [16000000000, 15965396992],
            'disk smaller than the reserve' => [10485760, 0],
            'disk exactly the reserve' => [34603008, 0],
        ];
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function fitsOnTargetProvider(): array
    {
        return [
            'zero used fits exactly the fixed margin' => [0, 67108864, true],
            'zero used misses margin by one byte' => [0, 67108863, false],
            'used equals capacity' => [1073741824, 1073741824, false],
            '1 GiB into 2 GiB' => [1073741824, 2147483648, true],
            'exact margin boundary' => [1000000000, 1087108864, true],
            'one byte below margin boundary' => [1000000000, 1087108863, false],
            '15.5 GiB payload onto 16 GB stick' => [16642998272, 16000000000, false],
            '15.5 GiB payload onto 32 GB stick' => [16642998272, 32000000000, true],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, array<int, string>}>
     */
    public static function partitionNamesProvider(): array
    {
        return [
            'ventoy layout (2 partitions)' => [
                [
                    'name' => 'sdb',
                    'type' => 'disk',
                    'children' => [
                        ['name' => 'sdb1', 'type' => 'part'],
                        ['name' => 'sdb2', 'type' => 'part'],
                    ],
                ],
                ['sdb1', 'sdb2'],
            ],
            'single partition' => [
                ['name' => 'sdb', 'type' => 'disk', 'children' => [['name' => 'sdb1', 'type' => 'part']]],
                ['sdb1'],
            ],
            'no children key' => [['name' => 'sdb', 'type' => 'disk'], []],
            'empty children' => [['name' => 'sdb', 'type' => 'disk', 'children' => []], []],
            'non-partition children ignored' => [
                [
                    'name' => 'sdb',
                    'type' => 'disk',
                    'children' => [
                        ['name' => 'sdb1', 'type' => 'part'],
                        ['name' => 'dm-0', 'type' => 'crypt'],
                        ['type' => 'part'],
                    ],
                ],
                ['sdb1'],
            ],
        ];
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function partitionPathProvider(): array
    {
        return [
            'sd-style partition 1' => ['/dev/sdb', 1, '/dev/sdb1'],
            'sd-style partition 2' => ['/dev/sdb', 2, '/dev/sdb2'],
            'nvme partition 1' => ['/dev/nvme0n1', 1, '/dev/nvme0n1p1'],
            'nvme partition 2' => ['/dev/nvme0n1', 2, '/dev/nvme0n1p2'],
            'mmcblk partition 1' => ['/dev/mmcblk0', 1, '/dev/mmcblk0p1'],
            'loop partition 2' => ['/dev/loop0', 2, '/dev/loop0p2'],
            'non-digit suffix stays plain' => ['/dev/null', 2, '/dev/null2'],
        ];
    }

    public function testCheckAndRecordDeviceNameDeclinedReturnsNull(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $inspector = self::make($fake);

        $result = $inspector->checkAndRecordDeviceName(
            self::interactiveInput("no\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/sdb',
            ['device' => '/dev/sdb', 'device_name' => 'usb Old Stick'],
            true,
            false
        );

        self::assertNull($result);
    }

    private static function make(
        FakeProcessRunner $runner,
        ?Closure $updateConfig = null,
        ?Closure $fileExists = null,
        ?array $mountsFileCandidates = null,
    ): DeviceInspector {
        $args = [
            $runner,
            $updateConfig ?? static function (array $u): void {
            },
            $fileExists,
        ];
        if ($mountsFileCandidates !== null) {
            $args[] = $mountsFileCandidates;
        }

        return new DeviceInspector(...$args);
    }

    private static function interactiveInput(string $answers): ArrayInput
    {
        $input = new ArrayInput([]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive(true);

        return $input;
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    public function testCheckAndRecordDeviceNameNonInteractiveSkipsPromptAndConfigUpdate(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $updateConfigCalls = [];
        $inspector = self::make(
            $fake,
            updateConfig: static function (array $u) use (&$updateConfigCalls): void {
                $updateConfigCalls[] = $u;
            }
        );

        $result = $inspector->checkAndRecordDeviceName(
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/sdb',
            ['device' => '/dev/sdb'], // warn_missing outcome, but non-interactive: no prompt, no update
            false,
            false
        );

        self::assertNotNull($result);
        self::assertSame([], $updateConfigCalls);
    }

    public function testCheckAndRecordDeviceNameSkipConfirmBypassesPrompt(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $updateConfigCalls = [];
        $inspector = self::make(
            $fake,
            updateConfig: static function (array $u) use (&$updateConfigCalls): void {
                $updateConfigCalls[] = $u;
            }
        );

        $result = $inspector->checkAndRecordDeviceName(
            self::interactiveInput(''), // no answer needed: skipConfirm bypasses the question
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/sdb',
            ['device' => '/dev/sdb', 'device_name' => 'usb Old Stick'],
            true,
            true
        );

        self::assertNotNull($result);
        self::assertCount(1, $updateConfigCalls);
    }

    #[DataProvider('deviceNameCheckOutcomeProvider')]
    public function testDeviceNameCheckOutcome(
        array $config,
        string $configKeyPrefix,
        string $device,
        string $currentDeviceName,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            DeviceInspector::deviceNameCheckOutcome($config, $configKeyPrefix, $device, $currentDeviceName)
        );
    }

    #[DataProvider('estimateDataPartitionBytesProvider')]
    public function testEstimateDataPartitionBytes(int $wholeDiskBytes, int $expected): void
    {
        self::assertSame($expected, DeviceInspector::estimateDataPartitionBytes($wholeDiskBytes));
    }

    #[DataProvider('fitsOnTargetProvider')]
    public function testFitsOnTarget(int $sourceUsedBytes, int $targetCapacityBytes, bool $expected): void
    {
        self::assertSame($expected, DeviceInspector::fitsOnTarget($sourceUsedBytes, $targetCapacityBytes));
    }

    public function testGetMountedPartitionsFallsBackThroughCandidates(): void
    {
        $missing = sys_get_temp_dir().'/does-not-exist-'.uniqid('', true);
        $fallback = tempnam(sys_get_temp_dir(), 'mounts_test_');
        file_put_contents($fallback, "/dev/sdb1 /mnt/stick vfat rw 0 0\n");

        try {
            $inspector = self::make(new FakeProcessRunner(), mountsFileCandidates: [$missing, $fallback]);

            self::assertSame([['/dev/sdb1', '/mnt/stick']], $inspector->getMountedPartitions('/dev/sdb'));
        } finally {
            @unlink($fallback);
        }
    }

    /**
     * getMountedPartitions previously read the real /proc/1/mounts or /proc/mounts with no seam
     * (TODO.md gap) — the mountsFileCandidates constructor param now makes it fixture-driven.
     */
    public function testGetMountedPartitionsMatchesDeviceLines(): void
    {
        $mounts = tempnam(sys_get_temp_dir(), 'mounts_test_');
        file_put_contents(
            $mounts,
            "/dev/sdb1 /mnt/stick vfat rw 0 0\n/dev/sda1 / ext4 rw 0 0\n/dev/sdb2 /mnt/efi vfat rw 0 0\n"
        );

        try {
            $inspector = self::make(new FakeProcessRunner(), mountsFileCandidates: [$mounts]);

            self::assertSame(
                [['/dev/sdb1', '/mnt/stick'], ['/dev/sdb2', '/mnt/efi']],
                $inspector->getMountedPartitions('/dev/sdb')
            );
        } finally {
            @unlink($mounts);
        }
    }

    public function testGetMountedPartitionsNoMatchIsEmpty(): void
    {
        $mounts = tempnam(sys_get_temp_dir(), 'mounts_test_');
        file_put_contents($mounts, "/dev/sda1 / ext4 rw 0 0\n");

        try {
            $inspector = self::make(new FakeProcessRunner(), mountsFileCandidates: [$mounts]);

            self::assertSame([], $inspector->getMountedPartitions('/dev/sdb'));
        } finally {
            @unlink($mounts);
        }
    }

    /**
     * Previously only exercised via the coincidence that /dev/null2 never exists on the host —
     * the fileExists seam now makes both fallback branches deterministic (TODO.md gap fix).
     */
    public function testHasVentoyPartitionLsblkEmptyFallsBackToDevNodeAbsent(): void
    {
        $inspector = self::make(new FakeProcessRunner(), fileExists: static fn(string $path): bool => false);

        self::assertFalse($inspector->hasVentoyPartition('/dev/null'));
    }

    public function testHasVentoyPartitionLsblkEmptyFallsBackToDevNodePresent(): void
    {
        $inspector = self::make(
            new FakeProcessRunner(),
            fileExists: static fn(string $path): bool => $path === '/dev/null2'
        );

        self::assertTrue($inspector->hasVentoyPartition('/dev/null'));
    }

    public function testHasVentoyPartitionOnePartition(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_NO_VENTOY);
        $inspector = self::make($fake);

        self::assertFalse($inspector->hasVentoyPartition('/dev/sdb'));
    }

    public function testHasVentoyPartitionTwoPartitions(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $inspector = self::make($fake);

        self::assertTrue($inspector->hasVentoyPartition('/dev/sdb'));
    }

    public function testIsFat32VentoyMatch(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('blkid -o value -s TYPE', 0, "vfat\n");
        $fake->on('blkid -o value -s LABEL', 0, "VENTOY\n");
        $inspector = self::make($fake);

        self::assertTrue($inspector->isFat32Ventoy('/dev/sdb1'));
    }

    public function testIsFat32VentoyTypeProbeFailureShortCircuits(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('blkid', 2, '');
        $inspector = self::make($fake);

        self::assertFalse($inspector->isFat32Ventoy('/dev/sdb1'));
        $blkidCalls = array_filter($fake->commands, static fn(string $c): bool => str_contains($c, 'blkid'));
        self::assertCount(1, $blkidCalls);
    }

    public function testIsFat32VentoyWrongLabel(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('blkid -o value -s TYPE', 0, "vfat\n");
        $fake->on('blkid -o value -s LABEL', 0, "OTHER\n");
        $inspector = self::make($fake);

        self::assertFalse($inspector->isFat32Ventoy('/dev/sdb1'));
    }

    public function testIsFat32VentoyWrongType(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('blkid -o value -s TYPE', 0, "exfat\n");
        $inspector = self::make($fake);

        self::assertFalse($inspector->isFat32Ventoy('/dev/sdb1'));
    }

    public function testLsblkInfoEmptyOutputIsEmpty(): void
    {
        $inspector = self::make(new FakeProcessRunner());

        self::assertSame([], $inspector->lsblkInfo('/dev/sdb'));
    }

    public function testLsblkInfoNonJsonOutputIsEmpty(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, 'not json at all');
        $inspector = self::make($fake);

        self::assertSame([], $inspector->lsblkInfo('/dev/sdb'));
    }

    public function testLsblkInfoNonzeroExitIsEmpty(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 32, '');
        $inspector = self::make($fake);

        self::assertSame([], $inspector->lsblkInfo('/dev/sdb'));
    }

    public function testLsblkInfoParsesFirstBlockdevice(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -o NAME,', 0, self::LSBLK_VENTOY);
        $inspector = self::make($fake);

        $info = $inspector->lsblkInfo('/dev/sdb');

        self::assertSame('sdb', $info['name']);
        self::assertSame('FakeVend', $info['vendor']);
        self::assertCount(2, $info['children']);
    }

    /**
     * @param array<string, mixed> $lsblkInfo
     * @param array<int, string> $expected
     */
    #[DataProvider('partitionNamesProvider')]
    public function testPartitionNames(array $lsblkInfo, array $expected): void
    {
        self::assertSame($expected, DeviceInspector::partitionNames($lsblkInfo));
    }

    #[DataProvider('partitionPathProvider')]
    public function testPartitionPath(string $device, int $number, string $expected): void
    {
        self::assertSame($expected, DeviceInspector::partitionPath($device, $number));
    }

    public function testPromptForDeviceDefaultSelectionOnEmptyInput(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 0, self::twoDisksJson());
        $inspector = self::make($fake);

        $result = $inspector->promptForDevice(
            self::interactiveInput("\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/sdc'
        );

        self::assertSame('/dev/sdc', $result);
    }

    private static function twoDisksJson(): string
    {
        return '{"blockdevices":[{"name":"sdb","size":"14.9G","type":"disk","tran":"usb",'
            .'"vendor":"FakeVend","model":"FakeModel"},{"name":"sdc","size":"32G","type":"disk","tran":"usb",'
            .'"vendor":"OtherVend","model":"OtherModel"}]}';
    }

    public function testPromptForDeviceEnterPathManually(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 0, self::twoDisksJson());
        $inspector = self::make($fake);

        $result = $inspector->promptForDevice(
            self::interactiveInput("2\n/dev/sdz\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertSame('/dev/sdz', $result);
    }

    public function testPromptForDeviceExcludesDeviceFromChoiceList(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 0, self::twoDisksJson());
        $inspector = self::make($fake);

        $result = $inspector->promptForDevice(
            self::interactiveInput("0\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            null,
            'Target device',
            '/dev/sdb'
        );

        self::assertSame('/dev/sdc', $result);
    }

    public function testPromptForDeviceListsDevicesAndSelectsByIndex(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 0, self::twoDisksJson());
        $inspector = self::make($fake);

        $result = $inspector->promptForDevice(
            self::interactiveInput("1\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertSame('/dev/sdc', $result);
    }

    public function testPromptForDeviceNoDevicesDefaultsToSavedDevice(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 1, '');
        $inspector = self::make($fake);

        $result = $inspector->promptForDevice(
            self::interactiveInput("\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/sdy'
        );

        self::assertSame('/dev/sdy', $result);
    }

    public function testPromptForDeviceNoDevicesEmptyAnswerReturnsNull(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 1, '');
        $inspector = self::make($fake);

        $result = $inspector->promptForDevice(
            self::interactiveInput("\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertNull($result);
    }

    public function testPromptForDeviceNoDevicesExcludedAnswerRejected(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 1, '');
        $inspector = self::make($fake);
        $input = self::interactiveInput("/dev/sdz\n");
        $output = new BufferedOutput();
        $io = new SymfonyStyle($input, $output);

        $result = $inspector->promptForDevice(
            $input,
            $output,
            new QuestionHelper(),
            $io,
            null,
            'Target device',
            '/dev/sdz'
        );

        self::assertNull($result);
        self::assertStringContainsString('Source and target must be different devices.', $output->fetch());
    }

    // --- rejectExcludedDevice ---

    public function testPromptForDeviceNoDevicesReturnsTypedAnswer(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk -J -d', 1, '');
        $inspector = self::make($fake);

        $result = $inspector->promptForDevice(
            self::interactiveInput("/dev/sdz\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertSame('/dev/sdz', $result);
    }

    public function testRejectExcludedDeviceDifferentReturnsDevice(): void
    {
        $inspector = self::make(new FakeProcessRunner());

        self::assertSame('/dev/sdb', $inspector->rejectExcludedDevice('/dev/sdb', '/dev/sdc', self::io()));
    }

    public function testRejectExcludedDeviceNullDeviceReturnsNull(): void
    {
        $inspector = self::make(new FakeProcessRunner());

        self::assertNull($inspector->rejectExcludedDevice(null, '/dev/sdc', self::io()));
    }

    // --- promptForDevice ---

    public function testRejectExcludedDeviceSameReturnsNullWithError(): void
    {
        $inspector = self::make(new FakeProcessRunner());
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $inspector->rejectExcludedDevice('/dev/sdb', '/dev/sdb', $io);

        self::assertNull($result);
        self::assertStringContainsString('Source and target must be different devices.', $output->fetch());
    }

    public function testTargetDataCapacityBytesFallsBackToWholeDevice(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('blockdev --getsize64 \'/dev/sdb1\'', 1, '');
        $fake->on('blockdev --getsize64 \'/dev/sdb\'', 0, "16000000000\n");
        $inspector = self::make($fake);

        // whole-device estimate = size minus the 33 MiB Ventoy reserve
        self::assertSame(15965396992, $inspector->targetDataCapacityBytes('/dev/sdb'));
    }

    public function testTargetDataCapacityBytesFromPartition(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('blockdev --getsize64 \'/dev/sdb1\'', 0, "15000000000\n");
        $inspector = self::make($fake);

        self::assertSame(15000000000, $inspector->targetDataCapacityBytes('/dev/sdb'));
        self::assertCount(1, $fake->commands);
    }

    public function testTargetDataCapacityBytesUnknownIsZero(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('blockdev', 1, '');
        $inspector = self::make($fake);

        self::assertSame(0, $inspector->targetDataCapacityBytes('/dev/sdb'));
    }

    public function testValidateSourceDeviceHappyPath(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'usb_setup_device_inspector_test_').'.json';
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $fake->on('blkid -o value -s TYPE', 0, "vfat\n");
        $fake->on('blkid -o value -s LABEL', 0, "VENTOY\n");
        $inspector = self::make($fake, updateConfig: self::persistingUpdateConfig($configPath));

        try {
            $result = $inspector->validateSourceDevice(
                self::interactiveInput(''),
                new BufferedOutput(),
                new QuestionHelper(),
                self::io(),
                '/dev/zero',
                '/dev/null',
                [],
                false
            );

            self::assertIsString($result);
            self::assertStringContainsString('/dev/zero', $result);
            $config = json_decode((string)file_get_contents($configPath), true);
            self::assertSame('usb FakeVend FakeModel', $config['source_device_name'] ?? null);
        } finally {
            @unlink($configPath);
        }
    }

    private static function persistingUpdateConfig(string $configPath): Closure
    {
        return static function (array $updates) use ($configPath): void {
            $current = is_file($configPath) ? (json_decode((string)file_get_contents($configPath), true) ?: []) : [];
            file_put_contents(
                $configPath,
                json_encode(array_merge($current, $updates), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        };
    }

    public function testValidateSourceDeviceMissingDeviceFails(): void
    {
        $inspector = self::make(new FakeProcessRunner());

        $result = $inspector->validateSourceDevice(
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/doesnotexist',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::FAILURE, $result);
    }

    public function testValidateSourceDeviceNonFat32Confirmed(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $fake->on('blkid -o value -s TYPE', 0, "exfat\n");
        $inspector = self::make($fake);

        $result = $inspector->validateSourceDevice(
            self::interactiveInput("yes\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/zero',
            '/dev/null',
            [],
            false
        );

        self::assertIsString($result);
    }

    public function testValidateSourceDeviceNonFat32Declined(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $fake->on('blkid -o value -s TYPE', 0, "exfat\n");
        $inspector = self::make($fake);

        $result = $inspector->validateSourceDevice(
            self::interactiveInput("no\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/zero',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::SUCCESS, $result);
    }

    // --- checkAndRecordDeviceName ---

    public function testValidateSourceDeviceSameAsTargetFails(): void
    {
        $inspector = self::make(new FakeProcessRunner());

        $result = $inspector->validateSourceDevice(
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/null',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::FAILURE, $result);
    }

    public function testValidateSourceDeviceSwappedLettersDeclined(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_VENTOY);
        $inspector = self::make($fake);

        // Previous run recorded today's TARGET as its source → swapped-letters warning
        $result = $inspector->validateSourceDevice(
            self::interactiveInput("no\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/zero',
            '/dev/null',
            ['source_device' => '/dev/null'],
            false
        );

        self::assertSame(Command::SUCCESS, $result);
    }

    public function testValidateSourceDeviceWithoutVentoyFails(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('lsblk', 0, self::LSBLK_NO_VENTOY);
        $inspector = self::make($fake);

        $result = $inspector->validateSourceDevice(
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io(),
            '/dev/zero',
            '/dev/null',
            [],
            false
        );

        self::assertSame(Command::FAILURE, $result);
    }
}
