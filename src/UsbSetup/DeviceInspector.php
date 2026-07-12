<?php

declare(strict_types=1);

namespace App\UsbSetup;

use App\Process\ProcessRunner;
use Closure;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DeviceInspector
{
    /** @var list<string> */
    private const MOUNTS_FILE_CANDIDATES = ['/proc/1/mounts', '/proc/mounts'];
    private readonly Closure $fileExists;
    private readonly Closure $updateConfig;

    /**
     * @param Closure(array<string, mixed>): void $updateConfig persists config updates (merge + save)
     * @param ?Closure(string): bool $fileExists overridable in tests so the VTOYEFI-partition
     *     fallback check never touches the real filesystem
     * @param list<string> $mountsFileCandidates overridable in tests so mounted-partition
     *     detection never reads the real /proc files
     */
    public function __construct(
        private readonly ProcessRunner $runner,
        Closure $updateConfig,
        ?Closure $fileExists = null,
        private readonly array $mountsFileCandidates = self::MOUNTS_FILE_CANDIDATES,
    ) {
        $this->updateConfig = $updateConfig;
        $this->fileExists = $fileExists ?? static fn(string $path): bool => file_exists($path);
    }

    /**
     * Whether a payload of $sourceUsedBytes fits a target of $targetCapacityBytes, leaving 2%
     * FAT cluster/metadata slack plus a fixed 64 MiB margin. Pure — unit-testable.
     */
    public static function fitsOnTarget(int $sourceUsedBytes, int $targetCapacityBytes): bool
    {
        return $sourceUsedBytes + intdiv($sourceUsedBytes, 50) + 67108864 <= $targetCapacityBytes;
    }

    public function promptForDevice(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        SymfonyStyle $io,
        ?string $savedDevice = null,
        string $prompt = 'Target device',
        ?string $excludeDevice = null,
    ): ?string {
        [$exit, $json] = $this->runCmd('lsblk -J -d -o NAME,SIZE,TYPE,TRAN,VENDOR,MODEL 2>/dev/null');
        $devices = [];
        if ($exit === 0 && trim($json) !== '') {
            $data = json_decode($json, true);
            foreach ($data['blockdevices'] ?? [] as $dev) {
                if (($dev['type'] ?? '') === 'disk') {
                    $devices[] = $dev;
                }
            }
        }

        if (empty($devices)) {
            $savedHint = $savedDevice ? " [<info>$savedDevice</info>]" : '';
            $q = new Question(
                "<question>$prompt (e.g. /dev/sdb):</question>".$savedHint.' ',
                $savedDevice
            );
            $answer = $helper->ask($input, $output, $q);
            $answer = ($answer !== null && trim($answer) !== '') ? rtrim(trim($answer), '/') : null;

            return $this->rejectExcludedDevice($answer, $excludeDevice, $io);
        }

        $choices = [];
        $deviceMap = [];
        $defaultIdx = 0;
        foreach ($devices as $dev) {
            $path = '/dev/'.$dev['name'];
            if ($excludeDevice !== null && $path === $excludeDevice) {
                continue;
            }
            $parts = array_filter([
                $dev['tran'] ?? '',
                trim((string)($dev['vendor'] ?? '')),
                trim((string)($dev['model'] ?? '')),
            ]);
            $label = sprintf('/dev/%-12s  %6s  %s', $dev['name'], $dev['size'], implode(' ', $parts));
            $choices[] = $label;
            $deviceMap[$label] = $path;
            if ($savedDevice !== null && $path === $savedDevice) {
                $defaultIdx = count($choices) - 1;
            }
        }
        $choices[] = 'Enter path manually';

        $defaultLabel = $savedDevice !== null && $defaultIdx < count(
            $choices
        ) - 1 ? " [<info>$savedDevice</info>]" : '';
        $q = new ChoiceQuestion("<question>$prompt:</question>$defaultLabel", $choices, $defaultIdx);
        $chosen = $helper->ask($input, $output, $q);

        if ($chosen === 'Enter path manually') {
            $q = new Question('<question>Device path (e.g. /dev/sdb):</question> ');
            $answer = $helper->ask($input, $output, $q);
            $answer = ($answer !== null && trim($answer) !== '') ? rtrim(trim($answer), '/') : null;

            return $this->rejectExcludedDevice($answer, $excludeDevice, $io);
        }

        return $deviceMap[$chosen];
    }

    private function runCmd(string $cmd): array
    {
        return $this->runner->run($cmd);
    }

    public function rejectExcludedDevice(?string $device, ?string $excludeDevice, SymfonyStyle $io): ?string
    {
        if ($device !== null && $excludeDevice !== null && $device === $excludeDevice) {
            $io->error('Source and target must be different devices.');

            return null;
        }

        return $device;
    }

    /**
     * Data-partition capacity of the target via blockdev: partition 1 directly if probeable
     * (already-partitioned stick), otherwise estimated from the whole-disk size. 0 = unknown.
     */
    public function targetDataCapacityBytes(string $device): int
    {
        [$exit, $out] = $this->runCmd(
            'blockdev --getsize64 '.escapeshellarg(self::partitionPath($device, 1)).' 2>/dev/null'
        );
        if ($exit === 0 && trim($out) !== '') {
            return (int)trim($out);
        }
        [$exit, $out] = $this->runCmd('blockdev --getsize64 '.escapeshellarg($device).' 2>/dev/null');

        return ($exit === 0 && trim($out) !== '')
            ? self::estimateDataPartitionBytes((int)trim($out))
            : 0;
    }

    /**
     * Kernel partition naming: devices whose name ends in a digit (nvme0n1, mmcblk0, loop0)
     * get a 'p' separator before the partition number; others (sdb) do not. Pure — unit-testable.
     */
    public static function partitionPath(string $device, int $number): string
    {
        return $device.(preg_match('/\d$/', $device) === 1 ? 'p' : '').$number;
    }

    /**
     * Estimates the data-partition capacity of a Ventoy stick from the whole-disk size: Ventoy
     * reserves a 32 MiB VTOYEFI partition plus ~1 MiB alignment. Pure — unit-testable.
     */
    public static function estimateDataPartitionBytes(int $wholeDiskBytes): int
    {
        return max(0, $wholeDiskBytes - 34603008);
    }

    /**
     * Validates the duplicate-mode source device: must differ from the target, exist, look like a
     * set-up Ventoy stick, and not be swapped with the target after replugging. Returns the device
     * description on success, or a Command exit code (SUCCESS = user aborted, FAILURE = invalid).
     *
     * @param array<string, mixed> $config
     */
    public function validateSourceDevice(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        SymfonyStyle $io,
        string $sourceDevice,
        string $device,
        array $config,
        bool $skipConfirm,
    ): int|string {
        if ($sourceDevice === $device) {
            $io->error('Source and target must be different devices.');

            return Command::FAILURE;
        }
        if (!file_exists($sourceDevice)) {
            $io->error("Source device not found: $sourceDevice");

            return Command::FAILURE;
        }
        if (!preg_match('#^/dev/[a-z][a-z0-9]*$#', $sourceDevice)) {
            $io->error(
                "Source device must be a top-level block device like /dev/sdb or /dev/nvme0n1, got: $sourceDevice"
            );

            return Command::FAILURE;
        }

        $sourceDeviceDesc = $this->checkAndRecordDeviceName(
            $input,
            $output,
            $helper,
            $io,
            $sourceDevice,
            $config,
            $input->isInteractive(),
            $skipConfirm,
            'source_'
        );
        if ($sourceDeviceDesc === null) {
            return Command::SUCCESS;
        }

        // Letters may have swapped after replugging: today's TARGET was the SOURCE of the previous run.
        if (($config['source_device'] ?? null) === $device) {
            $io->warning(
                "Target $device was the SOURCE device of the previous run — device letters may have swapped ".
                'after replugging. Double-check which stick is which before wiping.'
            );
            if ($input->isInteractive() && !$skipConfirm) {
                $q = new ConfirmationQuestion('Continue with these devices anyway? [yes/NO] ', false, '/^yes$/i');
                if (!$helper->ask($input, $output, $q)) {
                    $io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }
        }

        if (!$this->hasVentoyPartition($sourceDevice)) {
            $io->error(
                "Source $sourceDevice does not look like a set-up Ventoy stick ".
                '(missing VTOYEFI partition '.self::partitionPath($sourceDevice, 2).').'
            );

            return Command::FAILURE;
        }
        $sourcePartition = self::partitionPath($sourceDevice, 1);
        if (!$this->isFat32Ventoy($sourcePartition)) {
            $io->warning(
                "Source partition $sourcePartition is not FAT32 labelled VENTOY (maybe reformatted as exFAT?) ".
                '— its contents will be mirrored as-is.'
            );
            if ($input->isInteractive() && !$skipConfirm) {
                $q = new ConfirmationQuestion('Continue with this source anyway? [yes/NO] ', false, '/^yes$/i');
                if (!$helper->ask($input, $output, $q)) {
                    $io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }
        }

        $mountedParts = $this->getMountedPartitions($sourceDevice);
        if (!empty($mountedParts)) {
            $lines = [
                "Partitions of source $sourceDevice are mounted on the host system — ".
                'concurrent writes could corrupt the copy; unmount them first:',
            ];
            foreach ($mountedParts as [$part, $mountpoint]) {
                $lines[] = "  sudo umount $part   (mounted at $mountpoint)";
            }
            $io->warning($lines);
            if ($input->isInteractive() && !$skipConfirm) {
                $q = new ConfirmationQuestion('Continue anyway? [yes/NO] ', false, '/^yes$/i');
                if (!$helper->ask($input, $output, $q)) {
                    $io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }
        }

        return $sourceDeviceDesc;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function checkAndRecordDeviceName(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        SymfonyStyle $io,
        string $device,
        array $config,
        bool $interactive,
        bool $skipConfirm,
        string $configKeyPrefix = '',
    ): ?string {
        $info = $this->lsblkInfo($device);
        $deviceDesc = $device;
        if (!empty($info)) {
            $parts = array_filter([$info['vendor'] ?? '', $info['model'] ?? '', $info['size'] ?? '']);
            $deviceDesc .= ' ('.implode(' ', $parts).')';
        }

        $currentDeviceName = trim(implode(' ', array_filter([
            $info['tran'] ?? '',
            trim((string)($info['vendor'] ?? '')),
            trim((string)($info['model'] ?? '')),
        ])));

        $outcome = self::deviceNameCheckOutcome($config, $configKeyPrefix, $device, $currentDeviceName);
        $savedDeviceName = $config[$configKeyPrefix.'device_name'] ?? null;

        $warned = $outcome !== 'silent';
        if ($outcome === 'warn_missing') {
            $io->warning(
                "No recorded name on file for $device from a previous run (currently detected as ".
                "\"$currentDeviceName\") — cannot verify this is still the same physical drive."
            );
        } elseif ($outcome === 'warn_mismatch') {
            $io->warning(
                "Device $device now shows as \"$currentDeviceName\", but was \"$savedDeviceName\" last time — ".
                'device letters can shift across reboots/replugging.'
            );
        }

        if ($warned && $interactive && !$skipConfirm) {
            $q = new ConfirmationQuestion('Continue with this device anyway? [yes/NO] ', false, '/^yes$/i');
            if (!$helper->ask($input, $output, $q)) {
                $io->note('Aborted.');

                return null;
            }
        }

        if ($interactive) {
            ($this->updateConfig)([$configKeyPrefix.'device_name' => $currentDeviceName]);
        }

        return $deviceDesc;
    }

    public function lsblkInfo(string $device): array
    {
        [$exit, $out] = $this->runCmd(
            'lsblk -J -o NAME,SIZE,TYPE,TRAN,VENDOR,MODEL,MOUNTPOINT '.escapeshellarg($device).' 2>/dev/null'
        );
        if ($exit !== 0 || trim($out) === '') {
            return [];
        }
        $json = json_decode($out, true);

        return is_array($json) ? ($json['blockdevices'][0] ?? []) : [];
    }

    /**
     * Decides how checkAndRecordDeviceName reacts to the saved-vs-detected device name.
     * Pure — unit-testable.
     *
     * @param array<string, mixed> $config
     * @return string 'silent'|'warn_missing'|'warn_mismatch'
     */
    public static function deviceNameCheckOutcome(
        array $config,
        string $configKeyPrefix,
        string $device,
        string $currentDeviceName,
    ): string {
        $sameDeviceAsSaved = ($config[$configKeyPrefix.'device'] ?? null) === $device;
        $hasSavedDeviceName = array_key_exists($configKeyPrefix.'device_name', $config);
        $savedDeviceName = $config[$configKeyPrefix.'device_name'] ?? null;

        if ($sameDeviceAsSaved && !$hasSavedDeviceName) {
            return 'warn_missing';
        }
        if (
            $sameDeviceAsSaved
            && $savedDeviceName !== null
            && $savedDeviceName !== ''
            && $savedDeviceName !== $currentDeviceName
        ) {
            return 'warn_mismatch';
        }

        return 'silent';
    }

    /**
     * Ventoy always leaves a second (VTOYEFI) partition behind. Detected via lsblk (sysfs-backed,
     * reliable inside the container) with a /dev-node fallback when lsblk yields nothing.
     */
    public function hasVentoyPartition(string $device): bool
    {
        $info = $this->lsblkInfo($device);
        if (!empty($info)) {
            return count(self::partitionNames($info)) >= 2;
        }

        return ($this->fileExists)(self::partitionPath($device, 2));
    }

    /**
     * Extracts partition names from a single lsblk -J blockdevice entry. Pure — unit-testable.
     */
    public static function partitionNames(array $lsblkInfo): array
    {
        $names = [];
        foreach ($lsblkInfo['children'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'part' && isset($child['name'])) {
                $names[] = (string)$child['name'];
            }
        }

        return $names;
    }

    public function isFat32Ventoy(string $partition, string $label = 'VENTOY'): bool
    {
        [$exit, $type] = $this->runCmd('blkid -o value -s TYPE '.escapeshellarg($partition).' 2>/dev/null');
        if ($exit !== 0 || strtolower(trim($type)) !== 'vfat') {
            return false;
        }
        [$exit, $actual] = $this->runCmd('blkid -o value -s LABEL '.escapeshellarg($partition).' 2>/dev/null');

        return $exit === 0 && trim($actual) === $label;
    }

    public function getMountedPartitions(string $device): array
    {
        // /proc/1/mounts reflects the HOST's mount table when pid:host is set in docker-compose;
        // fall back to the container's own /proc/mounts otherwise.
        $mounts = false;
        foreach ($this->mountsFileCandidates as $path) {
            $mounts = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($mounts !== false) {
                break;
            }
        }
        $mounts = $mounts ?: [];
        $result = [];
        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[0], $parts[1]) && str_starts_with($parts[0], $device)) {
                $result[] = [$parts[0], $parts[1]];
            }
        }

        return $result;
    }
}
