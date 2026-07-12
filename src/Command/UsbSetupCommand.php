<?php

declare(strict_types=1);

namespace App\Command;

use App\Process\BinaryChecker;
use App\Process\ProcessRunner;
use App\UsbSetup\DeviceInspector;
use App\UsbSetup\IsoDownloader;
use App\UsbSetup\IsoPayloadManager;
use App\UsbSetup\PartitionFormatter;
use App\UsbSetup\RsyncMirror;
use App\UsbSetup\SoftwareDownloadsManager;
use App\UsbSetup\VentoyInstaller;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class UsbSetupCommand extends BaseCommand
{
    /** Windows/desktop-trash/vfat-fsck artifacts never worth mirroring between sticks. */
    private const ISO_VARIANTS = ['standard', 'gnome', 'kde', 'cinnamon', 'lxde', 'lxqt', 'mate', 'xfce'];

    private readonly BinaryChecker $binaryChecker;
    private readonly DeviceInspector $deviceInspector;
    private readonly IsoDownloader $isoDownloader;
    private readonly IsoPayloadManager $isoPayloadManager;
    private readonly PartitionFormatter $partitionFormatter;
    private readonly RsyncMirror $rsyncMirror;
    private readonly SoftwareDownloadsManager $softwareDownloadsManager;
    private readonly VentoyInstaller $ventoyInstaller;

    public function __construct(
        ?ProcessRunner $runner = null,
        ?string $name = null,
        ?BinaryChecker $binaryChecker = null,
        ?VentoyInstaller $ventoyInstaller = null,
        ?PartitionFormatter $partitionFormatter = null,
        ?IsoDownloader $isoDownloader = null,
        ?DeviceInspector $deviceInspector = null,
        ?IsoPayloadManager $isoPayloadManager = null,
        ?RsyncMirror $rsyncMirror = null,
        ?SoftwareDownloadsManager $softwareDownloadsManager = null,
    ) {
        parent::__construct($runner, $name);
        $this->binaryChecker = $binaryChecker ?? new BinaryChecker($this->runner);
        $waitForPartition = fn(string $part, int $timeoutSec = 10) => $this->waitForPartition($part, $timeoutSec);
        $this->ventoyInstaller = $ventoyInstaller ?? new VentoyInstaller($this->runner, $waitForPartition);
        $this->partitionFormatter = $partitionFormatter ?? new PartitionFormatter($this->runner, $waitForPartition);
        $this->isoDownloader = $isoDownloader ?? new IsoDownloader($this->runner);
        $this->deviceInspector = $deviceInspector ?? new DeviceInspector(
            $this->runner,
            fn(array $updates) => $this->updateConfig($updates),
        );
        $this->isoPayloadManager = $isoPayloadManager ?? new IsoPayloadManager($this->runner);
        $this->rsyncMirror = $rsyncMirror ?? new RsyncMirror($this->runner);
        $this->softwareDownloadsManager = $softwareDownloadsManager ?? new SoftwareDownloadsManager($this->runner);
    }

    protected function waitForPartition(string $part, int $timeoutSec = 10): void
    {
        $deadline = time() + $timeoutSec;
        while (!file_exists($part) && time() < $deadline) {
            usleep(300000);
        }
        if (!file_exists($part)) {
            throw new RuntimeException("Partition {$part} did not appear within {$timeoutSec}s.");
        }
    }

    protected function configure(): void
    {
        $this
            ->setName('usb:setup')
            ->setDescription(
                'Install Ventoy (MBR, FAT32), optionally copy a Debian live ISO and configure persistence.'
            )
            ->addOption(
                'device',
                null,
                InputOption::VALUE_REQUIRED,
                'Target USB block device(s), comma-separated for multiple (e.g. /dev/sdb,/dev/sdc)'
            )
            ->addOption(
                'source-device',
                null,
                InputOption::VALUE_REQUIRED,
                'Duplicate payload (ISO, persistence, ventoy config, /software) from this already-set-up '.
                'Ventoy stick (e.g. /dev/sdc) instead of downloading/creating it'
            )
            ->addOption('debian-iso', null, InputOption::VALUE_REQUIRED, 'Path to Debian live ISO')
            ->addOption(
                'persistence-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Persistence file size in MiB (default: 2048)'
            )
            ->addOption(
                'ventoy-bin',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to Ventoy2Disk.sh (auto-detected if omitted)'
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Skip confirmation prompts (implied by --no-interaction / -n)'
            )
            ->addOption(
                'update',
                null,
                InputOption::VALUE_NONE,
                'Update existing setup: skip steps that are already correct, use Ventoy -U'
            )
            ->addOption(
                'downloads-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to file listing software URLs and/or local file/directory paths to copy onto the stick '.
                '(magnet:/urn:btmh: reserved for future torrent support)'
            )
            ->addOption(
                'cache-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Directory for cached ISO/software downloads (default: downloads/.cache)'
            )
            ->addOption(
                'install-ventoy',
                null,
                InputOption::VALUE_REQUIRED,
                'Install/update Ventoy: "yes" or "no" (skips the Ventoy prompt)'
            )
            ->addOption(
                'iso-variant',
                null,
                InputOption::VALUE_REQUIRED,
                'Debian live ISO variant to download (standard, gnome, kde, cinnamon, lxde, lxqt, mate, xfce) — '.
                'implies ISO source "download" and skips the ISO prompts'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        if (!$this->isRoot()) {
            $this->io->error('This script must be run as root (use sudo).');

            return Command::FAILURE;
        }

        $this->loadDotenv();

        // USB_* env vars act like their CLI option: they suppress the prompt. They deliberately
        // skip the config layer (empty config array) — config keys only pre-fill prompt defaults,
        // so saved devices etc. never silently bypass the per-run prompts.
        $deviceParam = $this->resolveParam($input->getOption('device'), 'USB_DEVICE', [], '');
        $devices = $deviceParam !== null ? DeviceInspector::parseDeviceList($deviceParam) : null;
        if ($devices === []) {
            $devices = null;
        }
        $debianIso = $this->resolveParam($input->getOption('debian-iso'), 'USB_DEBIAN_ISO', [], '');
        $persistenceParam = $this->resolveParam($input->getOption('persistence-size'), 'USB_PERSISTENCE_SIZE', [], '');
        $persistenceMib = $persistenceParam !== null ? (int)$persistenceParam : 2048;
        $ventoyBinHint = $input->getOption('ventoy-bin') !== null ? (string)$input->getOption('ventoy-bin') : null;
        $downloadsFile = $this->resolveParam($input->getOption('downloads-file'), 'USB_DOWNLOADS_FILE', [], '');
        $sourceDeviceParam = $this->resolveParam($input->getOption('source-device'), 'USB_SOURCE_DEVICE', [], '');
        $sourceDevice = $sourceDeviceParam !== null ? rtrim($sourceDeviceParam, '/') : null;
        $yesEnv = getenv('USB_YES') ?: null;
        $skipConfirm = (bool)$input->getOption('yes') || self::parseBoolLike($yesEnv) || !$input->isInteractive();
        $updateParam = $this->resolveParam($input->getOption('update') ? '1' : null, 'USB_UPDATE', [], '');
        $isUpdate = self::parseBoolLike($updateParam);
        $isDuplicate = $sourceDevice !== null;
        $persistenceExplicit = $persistenceParam !== null;
        $installVentoyParam = $this->resolveParam($input->getOption('install-ventoy'), 'USB_INSTALL_VENTOY', [], '');
        $installVentoy = $installVentoyParam === null || self::parseBoolLike($installVentoyParam);
        $isoVariantParam = $this->resolveParam($input->getOption('iso-variant'), 'USB_ISO_VARIANT', [], '');
        if ($isoVariantParam !== null && !in_array($isoVariantParam, self::ISO_VARIANTS, true)) {
            $this->io->error(
                "Invalid --iso-variant / USB_ISO_VARIANT value: $isoVariantParam (expected one of: "
                .implode(', ', self::ISO_VARIANTS).')'
            );

            return Command::FAILURE;
        }
        if ($devices !== null) {
            $dupes = array_keys(array_filter(array_count_values($devices), static fn(int $c): bool => $c > 1));
            if ($dupes !== []) {
                $this->io->error('Duplicate device(s) in --device / USB_DEVICE: '.implode(', ', $dupes));

                return Command::FAILURE;
            }
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $config = $this->loadConfig();
        // cache_dir is never prompted — full resolution chain including the config file
        $cacheDir = $this->resolveParam(
            $input->getOption('cache-dir'),
            'USB_CACHE_DIR',
            $config,
            'cache_dir',
            dirname(__DIR__, 2).'/downloads/.cache'
        );

        $isoSrc = null;
        $variant = null;
        $deviceDescs = [];
        $sourceDeviceDesc = null;

        // --iso-variant / USB_ISO_VARIANT implies ISO source "download" and skips the ISO prompts
        if (!$isDuplicate && $debianIso === null && $isoVariantParam !== null) {
            $isoSrc = 'download';
            $variant = $isoVariantParam;
        }

        if ($input->isInteractive() && $devices === null) {
            $savedDevices = $config['devices'] ?? (isset($config['device']) ? [$config['device']] : null);
            $devices = $this->deviceInspector->promptForDevices($input, $output, $helper, $this->io, $savedDevices);
            if ($devices === []) {
                $this->io->note('Aborted.');

                return Command::SUCCESS;
            }
        } elseif ($devices === null) {
            $this->io->error('--device is required in non-interactive mode.');

            return Command::FAILURE;
        }

        foreach ($devices as $device) {
            if (!file_exists($device)) {
                $this->io->error("Device not found: $device");

                return Command::FAILURE;
            }
            if (!preg_match('#^/dev/[a-z][a-z0-9]*$#', $device)) {
                $this->io->error(
                    "Device must be a top-level block device like /dev/sdb or /dev/nvme0n1, got: $device"
                );

                return Command::FAILURE;
            }
        }
        // Pre-install snapshot of whether Ventoy is already present on each device — needed for
        // the mode-prompt default, the per-device update/redo decision, and the write loop below.
        // Evaluated once here since setting up one device never affects another's state.
        $ventoyOnStick = [];
        foreach ($devices as $device) {
            $ventoyOnStick[$device] = $this->deviceInspector->hasVentoyPartition($device);
        }

        if ($input->isInteractive()) {
            // Check/record each device's vendor+model name as early as possible, right after the
            // device list is finalized, so a mismatch warning appears before any further prompts.
            foreach ($devices as $device) {
                $deviceDesc = $this->deviceInspector->checkAndRecordDeviceName(
                    $input,
                    $output,
                    $helper,
                    $this->io,
                    $device,
                    $config,
                    true,
                    $skipConfirm,
                    self::configPrefixForDevice($device)
                );
                if ($deviceDesc === null) {
                    return Command::SUCCESS;
                }
                $deviceDescs[$device] = $deviceDesc;

                // Warn immediately if any partitions of this device are mounted on the host
                $mountedParts = $this->deviceInspector->getMountedPartitions($device);
                if (!empty($mountedParts)) {
                    $lines = [
                        "Partitions of $device are mounted on the host system — unmount them before proceeding:",
                    ];
                    foreach ($mountedParts as [$part, $mountpoint]) {
                        $lines[] = "  sudo umount $part   (mounted at $mountpoint)";
                    }
                    $lines[] = '';
                    $lines[] = 'Run those commands on the HOST (not inside ddev), then re-run this command.';
                    $this->io->warning($lines);
                    if (!$this->askConfirmation(
                        $input,
                        $output,
                        'Continue anyway? [yes/NO] ',
                        false,
                        false,
                        true,
                        '/^yes$/i'
                    )) {
                        $this->io->note('Aborted.');

                        return Command::SUCCESS;
                    }
                }
            }

            // Mode: update existing setup or redo from scratch (--update / USB_UPDATE skip the
            // prompt). Defaults to "update" only when every selected device already has Ventoy.
            if ($updateParam === null) {
                $modeLabels = ['update existing setup', 'redo from scratch'];
                $modeDefault = !in_array(false, $ventoyOnStick, true) ? 0 : 1;
                $isUpdate = $this->askChoice($input, $output, 'Mode', $modeLabels, $modeDefault)
                    === 'update existing setup';
            }

            // Ventoy install/update (optional; --install-ventoy / USB_INSTALL_VENTOY skip the prompt)
            if ($installVentoyParam === null) {
                $ventoyLabels = ['install/update Ventoy', 'skip Ventoy'];
                $ventoyDefault = ($config['install_ventoy'] ?? true) ? 0 : 1;
                $installVentoy = $this->askChoice($input, $output, 'Ventoy', $ventoyLabels, $ventoyDefault)
                    === 'install/update Ventoy';
            }

            // Payload: built from configuration (download/local files) or duplicated from an existing stick
            if ($sourceDevice === null) {
                $payloadLabels = [
                    'configuration (download / local files)',
                    'duplicate from an existing Ventoy stick',
                ];
                $payloadDefault = ($config['payload_source'] ?? 'configuration') === 'duplicate' ? 1 : 0;
                if ($this->askChoice(
                        $input,
                        $output,
                        'Payload',
                        $payloadLabels,
                        $payloadDefault
                    ) === $payloadLabels[1]) {
                    $sourceDevice = $this->deviceInspector->promptForDevice(
                        $input,
                        $output,
                        $helper,
                        $this->io,
                        $config['source_device'] ?? null,
                        'Source device',
                        $devices
                    );
                    if ($sourceDevice === null) {
                        $this->io->note('Aborted.');

                        return Command::SUCCESS;
                    }
                }
            }
            $isDuplicate = $sourceDevice !== null;
            if ($isDuplicate) {
                // duplicate mode mirrors the source stick — a pre-set ISO download does not apply
                $isoSrc = null;
                $variant = null;
                $res = $this->validateSourceAgainstTargets(
                    $input,
                    $output,
                    $helper,
                    $sourceDevice,
                    $devices,
                    $config,
                    $skipConfirm
                );
                if (is_int($res)) {
                    return $res;
                }
                $sourceDeviceDesc = $res;
            }

            if (!$isDuplicate && $debianIso === null && $isoSrc === null) {
                $savedIsoSrc = $config['iso_source'] ?? 'download';
                $isoSrc = $this->askChoice(
                    $input,
                    $output,
                    'Debian ISO',
                    ['download', 'local path', 'skip'],
                    $savedIsoSrc,
                    ' <comment>(downloads are cached in downloads/.cache/)</comment>'
                );

                if ($isoSrc === 'download') {
                    $variant = $this->askChoice(
                        $input,
                        $output,
                        'Variant',
                        self::ISO_VARIANTS,
                        $config['iso_variant'] ?? 'standard'
                    );
                } elseif ($isoSrc === 'local path') {
                    $savedPath = $config['iso_path'] ?? null;
                    $ans = $this->askText($input, $output, '<question>Path to Debian ISO</question>', $savedPath);
                    $debianIso = ($ans !== null && trim($ans) !== '') ? trim($ans) : null;
                    if ($debianIso !== null && !is_file($debianIso)) {
                        $this->io->error("ISO file not found: $debianIso");

                        return Command::FAILURE;
                    }
                }
            }
            $wantIso = $debianIso !== null || $isoSrc === 'download';
            if ($wantIso && !$persistenceExplicit) {
                $persistDefault = (string)min((int)($config['persistence_mib'] ?? 2048), 4090);
                $ans = $this->askText(
                    $input,
                    $output,
                    '<question>Persistence size in MiB</question> (max 4090 on FAT32)',
                    $persistDefault,
                    static function (?string $v) {
                        $n = (int)($v ?? '');
                        if ($n <= 0) {
                            throw new RuntimeException('Must be a positive integer.');
                        }
                        if ($n > 4090) {
                            throw new RuntimeException(
                                "FAT32 limits single files to ~4 GiB — maximum is 4090 MiB (got $n)."
                            );
                        }

                        return (string)$n;
                    }
                );
                $persistenceMib = (int)($ans ?? $persistDefault);
            }

            // Downloads: --downloads-file bypasses the prompt; duplicate mode never prompts.
            // The first run asks free-text to establish a path; later runs get a ChoiceQuestion
            // whose default mirrors the saved answer ('-' or '' = skip). The answer is persisted
            // as the next run's default.
            if ($downloadsFile === null && !$isDuplicate) {
                $savedPath = null;
                $askPath = true;
                if (array_key_exists('download_sources', $config)) {
                    $saved = trim((string)$config['download_sources']);
                    $savedPath = ($saved === '' || $saved === '-') ? null : $saved;
                    $downloadLabels = $savedPath !== null
                        ? ["copy software from $savedPath", 'use a different downloads file', 'skip software downloads']
                        : ['skip software downloads', 'copy software from a downloads file'];
                    $q = new ChoiceQuestion(
                        '<question>Software downloads:</question> [<info>'.$downloadLabels[0].'</info>]',
                        $downloadLabels,
                        0
                    );
                    $choice = (string)$helper->ask($input, $output, $q);
                    if ($savedPath !== null && $choice === $downloadLabels[0]) {
                        $downloadsFile = $savedPath;
                        $askPath = false;
                    } elseif (str_starts_with($choice, 'skip')) {
                        $downloadsFile = null;
                        $askPath = false;
                    }
                    // else: fall through to the free-text question below
                }

                if ($askPath) {
                    $downloadsDefault = $savedPath ?? 'config/usb-downloads.txt';
                    $ans = $this->askText(
                        $input,
                        $output,
                        "<question>Software downloads file</question> (http(s) URLs copied to /software/ on the stick; '-' to skip)",
                        $downloadsDefault
                    );
                    $ans = $ans !== null ? trim($ans) : '';
                    $downloadsFile = ($ans === '' || $ans === '-') ? null : $ans;
                }
            }

            $updates = [
                'devices' => $devices,
                'persistence_mib' => $persistenceMib,
                'cache_dir' => $cacheDir,
            ];
            foreach ($devices as $device) {
                $updates[self::configPrefixForDevice($device).'device'] = $device;
            }
            if ($isoSrc !== null) {
                $updates['iso_source'] = $isoSrc;
            }
            if ($variant !== null) {
                $updates['iso_variant'] = $variant;
            }
            if ($isoSrc === 'local path') {
                $updates['iso_path'] = $debianIso;
            }
            $updates['install_ventoy'] = $installVentoy;
            if (!$isDuplicate) {
                // '-' persists an explicit "no downloads" so the prompt is never asked again
                $updates['download_sources'] = $downloadsFile ?? '-';
            } elseif ($downloadsFile !== null) {
                $updates['download_sources'] = $downloadsFile;
            }
            $updates['payload_source'] = $isDuplicate ? 'duplicate' : 'configuration';
            if ($sourceDevice !== null) {
                $updates['source_device'] = $sourceDevice;
            }
            $this->updateConfig($updates);
        }

        // runs in both interactive and non-interactive mode (--iso-variant / USB_ISO_VARIANT)
        if ($isoSrc === 'download' && $variant !== null) {
            try {
                $debianIso = ($this->isoDownloader)($variant, $output, $this->io, $cacheDir);
            } catch (RuntimeException $e) {
                $this->io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        foreach ($devices as $device) {
            if (!isset($deviceDescs[$device])) {
                $deviceDesc = $this->deviceInspector->checkAndRecordDeviceName(
                    $input,
                    $output,
                    $helper,
                    $this->io,
                    $device,
                    $config,
                    $input->isInteractive(),
                    $skipConfirm,
                    self::configPrefixForDevice($device)
                );
                if ($deviceDesc === null) {
                    return Command::SUCCESS;
                }
                $deviceDescs[$device] = $deviceDesc;
            }
        }

        if ($sourceDevice !== null && $sourceDeviceDesc === null) {
            $res = $this->validateSourceAgainstTargets(
                $input,
                $output,
                $helper,
                $sourceDevice,
                $devices,
                $config,
                $skipConfirm
            );
            if (is_int($res)) {
                return $res;
            }
            $sourceDeviceDesc = $res;
        }

        if ($debianIso !== null && !is_file($debianIso)) {
            $this->io->error("ISO file not found: $debianIso");

            return Command::FAILURE;
        }

        if ($downloadsFile !== null && !is_file($downloadsFile)) {
            $this->io->error("Downloads file not found: $downloadsFile");

            return Command::FAILURE;
        }

        try {
            ($this->binaryChecker)('lsblk');
            ($this->binaryChecker)('mkfs.fat');
            ($this->binaryChecker)('mkfs.ext4');
            ($this->binaryChecker)('mount');
            ($this->binaryChecker)('umount');
            if ($isDuplicate) {
                ($this->binaryChecker)('rsync');
                ($this->binaryChecker)('blockdev');
            }
            $ventoyBin = $this->ventoyInstaller->findBin($ventoyBinHint);
        } catch (RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Preflight for duplicate mode: measure the source payload and make sure it fits the
        // target BEFORE anything is confirmed or wiped.
        $sourceUsedBytes = 0;
        if ($isDuplicate) {
            $sourcePartition = DeviceInspector::partitionPath($sourceDevice, 1);
            $srcMount = null;
            $oversized = '';
            try {
                $this->waitForPartition($sourcePartition);
                $srcMount = $this->partitionFormatter->mount($sourcePartition, $output, 'src', true);
                $total = disk_total_space($srcMount);
                $free = disk_free_space($srcMount);
                if ($total === false || $free === false) {
                    throw new RuntimeException("Cannot determine used space on $sourcePartition.");
                }
                $sourceUsedBytes = (int)$total - (int)$free;
                [, $oversized] = $this->runCmd(
                    'find '.escapeshellarg($srcMount).' -type f -size +4090M 2>/dev/null'
                );
            } catch (RuntimeException $e) {
                $this->io->error($e->getMessage());

                return Command::FAILURE;
            } finally {
                if ($srcMount !== null) {
                    $this->partitionFormatter->unmount($srcMount);
                }
            }

            // A device that can't physically fit the duplicated payload is dropped from the batch
            // (with a warning) rather than failing the whole run — the rest may still fit fine.
            foreach ($devices as $device) {
                $targetCapacityBytes = $this->deviceInspector->targetDataCapacityBytes($device);
                if (
                    $targetCapacityBytes > 0
                    && !DeviceInspector::fitsOnTarget($sourceUsedBytes, $targetCapacityBytes)
                ) {
                    $this->io->warning(
                        sprintf(
                            '%s: source payload (%.1f GiB used) does not fit the target data partition '.
                            '(%.1f GiB) — dropping this device from the batch.',
                            $device,
                            $sourceUsedBytes / 1073741824,
                            $targetCapacityBytes / 1073741824
                        )
                    );
                    unset($deviceDescs[$device], $ventoyOnStick[$device]);
                    $devices = array_values(array_diff($devices, [$device]));
                }
            }
            if ($devices === []) {
                $this->io->error('No target device has enough space for the source payload.');

                return Command::FAILURE;
            }

            $oversizedFiles = array_values(
                array_filter(
                    array_map(
                        static fn(string $line): string => ltrim(substr(trim($line), strlen($srcMount)), '/'),
                        explode("\n", trim($oversized))
                    )
                )
            );
            if ($oversizedFiles !== []) {
                $this->io->warning(
                    array_merge(
                        ['These files on the source exceed the ~4 GiB FAT32 single-file limit and will be SKIPPED:'],
                        $oversizedFiles
                    )
                );
                if (!$this->askConfirmation(
                    $input,
                    $output,
                    'Continue without these files? [yes/NO] ',
                    false,
                    $skipConfirm,
                    true,
                    '/^yes$/i'
                )) {
                    $this->io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }
        }

        $this->io->section($isUpdate ? 'USB Setup — Update Summary' : 'USB Setup — Summary');
        $rows = [
            ['Mode', $isUpdate ? 'update (skip completed steps)' : 'redo from scratch'],
            ['Partition table', $installVentoy ? 'MBR' : 'existing (Ventoy skipped)'],
            ['Ventoy binary', $ventoyBin],
        ];
        if ($isDuplicate) {
            $rows[] = ['Payload source', 'duplicate of '.($sourceDeviceDesc ?? $sourceDevice)];
            $rows[] = ['Source payload', sprintf('%.1f GiB used', $sourceUsedBytes / 1073741824)];
        }
        if ($debianIso) {
            $rows[] = ['Debian ISO', $debianIso];
            $rows[] = ['Persistence', "{$persistenceMib} MiB (ext4, Ventoy persistence plugin)"];
        }
        if ($downloadsFile !== null) {
            $rows[] = ['Software downloads', "queued from $downloadsFile"];
        }
        $this->io->table(['Option', 'Value'], $rows);

        // Ventoy update (-u) is refused on a stick without Ventoy — the flag must follow each
        // device's actual state, not the chosen mode.
        $deviceRows = [];
        $ventoyInstallRequired = false;
        foreach ($devices as $device) {
            $ventoyActive = $installVentoy || $ventoyOnStick[$device];
            $dataLabel = $ventoyActive ? 'VENTOY' : 'USBDATA';
            $deviceRows[] = [
                $deviceDescs[$device],
                $isUpdate ? 'FAT32 if already — reformat offered otherwise' : "FAT32 (label: $dataLabel)",
            ];
            if ($isUpdate && $installVentoy && !$ventoyOnStick[$device]) {
                $ventoyInstallRequired = true;
            }
        }
        $this->io->table(['Device', 'Data partition'], $deviceRows);
        if ($isDuplicate) {
            $this->io->text("Source $sourceDevice is only ever mounted read-only — it is never written.");
        }

        if ($isUpdate) {
            if ($ventoyInstallRequired) {
                $this->io->warning(
                    'Ventoy is not present on one or more devices — a full Ventoy install is required there. '.
                    'Their partition table will be recreated and ISO/persistence/software re-copied.'
                );
            } else {
                $this->io->note('Updating — data partition is preserved on devices that already have it.');
            }
            if (!$this->askConfirmation($input, $output, 'Proceed with update? [YES/no] ', true, $skipConfirm)) {
                $this->io->note('Aborted.');

                return Command::SUCCESS;
            }
        } else {
            $this->io->warning(
                'ALL DATA ON THE FOLLOWING DEVICE(S) WILL BE ERASED: '.implode(', ', $devices)
            );
            if (!$this->askConfirmation(
                $input,
                $output,
                'Are you absolutely sure you want to proceed? [yes/NO] ',
                false,
                $skipConfirm,
                true,
                '/^yes$/i'
            )) {
                $this->io->note('Aborted.');

                return Command::SUCCESS;
            }
            if (!$this->askConfirmation(
                $input,
                $output,
                'Second confirmation — wipe these device(s)? [yes/NO] ',
                false,
                $skipConfirm,
                true,
                '/^yes$/i'
            )) {
                $this->io->note('Aborted.');

                return Command::SUCCESS;
            }
        }

        $softwareFiles = [];
        if ($downloadsFile !== null) {
            try {
                $softwareFiles = ($this->softwareDownloadsManager)($downloadsFile, $output, $this->io, $cacheDir);
            } catch (RuntimeException $e) {
                $this->io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        // Free-space preflight for the configuration path: all sizes are known now (ISO
        // downloaded/validated, software fetched to cache) and nothing has touched any stick yet.
        // Warning only — in update mode parts of the payload may already be on a stick, so the
        // estimate can overshoot.
        if (!$isDuplicate && ($debianIso !== null || $softwareFiles !== [])) {
            $requiredBytes = IsoPayloadManager::estimateConfigurationPayloadBytes(
                $debianIso,
                $persistenceMib,
                $softwareFiles,
                'filesize'
            );
            $tightDevices = [];
            foreach ($devices as $device) {
                $capacityBytes = $this->deviceInspector->targetDataCapacityBytes($device);
                if ($capacityBytes > 0 && !DeviceInspector::fitsOnTarget($requiredBytes, $capacityBytes)) {
                    $tightDevices[] = $device;
                }
            }
            if ($tightDevices !== []) {
                $this->io->warning(
                    sprintf(
                        'Configured payload (%.1f GiB: ISO + persistence + software) may not fit on the '.
                        'target data partition of: %s.',
                        $requiredBytes / 1073741824,
                        implode(', ', $tightDevices)
                    )
                );
                if (!$this->askConfirmation(
                    $input,
                    $output,
                    'Continue anyway? [yes/NO] ',
                    false,
                    $skipConfirm,
                    true,
                    '/^yes$/i'
                )) {
                    $this->io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }
        }

        // In duplicate mode the read-only source is mounted once and shared across every target
        // in the write loop below — it never changes between targets.
        $sourceMount = null;
        if ($isDuplicate) {
            try {
                $sourcePartition = DeviceInspector::partitionPath($sourceDevice, 1);
                $sourceMount = $this->partitionFormatter->mount($sourcePartition, $output, 'src', true);
                $this->io->text("Mounted $sourcePartition read-only at $sourceMount.");
            } catch (Throwable $t) {
                $this->io->error($t->getMessage());

                return Command::FAILURE;
            }
        }

        // One device failing does not abort the rest of the batch — each iteration's outcome is
        // recorded and reported at the end; the overall exit code reflects whether any failed.
        $outcomes = [];
        try {
            foreach ($devices as $device) {
                $dataPartition = DeviceInspector::partitionPath($device, 1);
                $ventoyUpdate = $isUpdate && $ventoyOnStick[$device];
                $dataLabel = ($installVentoy || $ventoyOnStick[$device]) ? 'VENTOY' : 'USBDATA';

                $this->io->section("Device: {$deviceDescs[$device]}");
                try {
                    // Step 1: Ventoy (-u to update in place, -I for full install)
                    if ($installVentoy) {
                        ($this->ventoyInstaller)($ventoyBin, $device, $output, $this->io, $ventoyUpdate);
                        $this->io->text($ventoyUpdate ? 'Ventoy updated.' : 'Ventoy installed (MBR).');
                    } else {
                        $this->io->text('Ventoy install skipped.');
                    }

                    // Step 2: FAT32 — scratch mode always reformats (the wipe was double-confirmed,
                    // even when Ventoy itself was skipped); update mode asks before erasing the
                    // data partition.
                    $doReformat = true;
                    if ($isUpdate && $this->deviceInspector->isFat32Ventoy($dataPartition, $dataLabel)) {
                        $this->io->text("Partition 1 already FAT32 ($dataLabel) — skipping reformat.");
                        $doReformat = false;
                    } elseif ($isUpdate) {
                        $doReformat = $this->askConfirmation(
                            $input,
                            $output,
                            "Partition 1 is not FAT32 (label $dataLabel). Reformat it? ALL DATA on ".
                            "{$dataPartition} will be erased. [yes/NO] ",
                            false,
                            $skipConfirm,
                            true,
                            '/^yes$/i'
                        );
                        if (!$doReformat) {
                            $this->io->warning("$dataPartition left untouched (not FAT32).");
                        }
                    }
                    if ($doReformat) {
                        try {
                            $this->partitionFormatter->reformatFat32($dataPartition, $output, $this->io, $dataLabel);
                        } catch (Throwable $t) {
                            $messages = [$t->getMessage()];
                            if (!$installVentoy) {
                                $messages[] = "If $device has no partition table yet, rerun and choose ".
                                    "'install/update Ventoy' to create one.";
                            }
                            throw new RuntimeException(implode("\n", $messages), 0, $t);
                        }
                        $this->io->text('Partition 1 formatted as FAT32.');
                    }

                    if ($debianIso !== null || $softwareFiles !== [] || $isDuplicate) {
                        $mount = null;
                        try {
                            $mount = $this->partitionFormatter->mount(
                                $dataPartition,
                                $output,
                                self::mountSuffixForDevice($device)
                            );
                            $this->io->text("Mounted $dataPartition at $mount.");

                            if ($isDuplicate) {
                                // Exact capacity re-check now that the target filesystem is mounted
                                $targetFsBytes = disk_total_space($mount);
                                if ($targetFsBytes !== false && !DeviceInspector::fitsOnTarget(
                                        $sourceUsedBytes,
                                        (int)$targetFsBytes
                                    )) {
                                    throw new RuntimeException(
                                        sprintf(
                                            'Source payload (%.1f GiB used) does not fit on the target data '.
                                            'partition (%.1f GiB).',
                                            $sourceUsedBytes / 1073741824,
                                            $targetFsBytes / 1073741824
                                        )
                                    );
                                }

                                $mirrored = ($this->rsyncMirror)(
                                    $sourceMount,
                                    $mount,
                                    $isUpdate,
                                    $skipConfirm,
                                    $input,
                                    $output,
                                    $helper,
                                    $this->io
                                );
                                if (!$mirrored) {
                                    $outcomes[$device] = 'Skipped (mirror declined).';
                                    continue;
                                }
                            }

                            if ($debianIso !== null) {
                                // Step 3: ISO (skip if same file already on stick)
                                $isoName = basename($debianIso);
                                if (
                                    ($isUpdate || $isDuplicate)
                                    && $this->isoPayloadManager->isoMatchesOnStick($mount, $isoName, $debianIso)
                                ) {
                                    $this->io->text('ISO already on stick — skipping copy.');
                                } else {
                                    $isoName = $this->isoPayloadManager->copyIso(
                                        $debianIso,
                                        $mount,
                                        $output,
                                        $this->io
                                    );
                                }

                                // Step 4: Persistence (skip if already valid ext4 at correct size, or
                                // carried over from the source stick — its size may legitimately differ
                                // from --persistence-size, and recreating it would destroy the
                                // duplicated user data)
                                $persistDat = 'persistence.dat';
                                if ($isDuplicate && is_file(rtrim($mount, '/').'/persistence.dat')) {
                                    $this->io->text(
                                        'persistence.dat carried over from source stick — skipping creation.'
                                    );
                                    if ($persistenceExplicit) {
                                        $this->io->note(
                                            '--persistence-size ignored — persistence.dat was duplicated '.
                                            'from the source stick.'
                                        );
                                    }
                                } elseif (
                                    $isUpdate
                                    && $this->isoPayloadManager->isPersistenceValid($mount, $persistenceMib)
                                ) {
                                    $this->io->text('persistence.dat already valid — skipping creation.');
                                } else {
                                    $persistDat = $this->isoPayloadManager->createPersistenceFile(
                                        $mount,
                                        $persistenceMib,
                                        $output,
                                        $this->io
                                    );
                                }

                                // Step 5: ventoy.json (skip if entry already correct)
                                if (
                                    ($isUpdate || $isDuplicate)
                                    && $this->isoPayloadManager->ventoyJsonHasEntry($mount, $isoName)
                                ) {
                                    $this->io->text('ventoy.json already has correct entry — skipping.');
                                } else {
                                    $this->isoPayloadManager->writeVentoyJson(
                                        $mount,
                                        $isoName,
                                        $persistDat,
                                        $this->io
                                    );
                                }
                            }

                            // Step 6: Software downloads (skip individually if already on stick, in
                            // update/duplicate mode)
                            if ($softwareFiles !== []) {
                                $this->softwareDownloadsManager->copySoftwareFiles(
                                    $softwareFiles,
                                    $mount,
                                    $output,
                                    $this->io,
                                    $isUpdate || $isDuplicate
                                );
                            }

                            $this->runCmd('sync', false, $output);
                            $this->io->text('Synced filesystem.');
                        } finally {
                            if ($mount !== null) {
                                $this->partitionFormatter->unmount($mount);
                                $this->io->text("Unmounted $dataPartition.");
                            }
                        }
                    }

                    $outcomes[$device] = 'ok';
                } catch (Throwable $t) {
                    $outcomes[$device] = $t->getMessage();
                    $this->io->error("$device: {$t->getMessage()}");
                }
            }
        } finally {
            if ($sourceMount !== null) {
                $this->partitionFormatter->unmount($sourceMount);
            }
        }

        $this->io->section('USB Setup — Results');
        $this->io->table(
            ['Device', 'Result'],
            array_map(
                static fn(string $device, string $result): array => [
                    $device,
                    $result === 'ok' ? 'OK' : "FAILED: $result",
                ],
                array_keys($outcomes),
                $outcomes
            )
        );

        if (array_filter($outcomes, static fn(string $r): bool => $r !== 'ok') !== []) {
            return Command::FAILURE;
        }

        $this->io->success('USB stick(s) ready.');
        if ($isDuplicate) {
            $this->io->text("Payload duplicated from $sourceDevice.");
        }
        if ($debianIso !== null) {
            $this->io->text([
                'Boot from a stick and select the Debian ISO in the Ventoy menu.',
                'Persistence is active for that ISO via /ventoy/ventoy.json.',
            ]);
        }
        if ($softwareFiles !== []) {
            $this->io->text(
                sprintf('%d software installer(s) copied to /software/ on each stick.', count($softwareFiles))
            );
        }
        if ($debianIso === null && $softwareFiles === [] && !$isDuplicate) {
            $this->io->text('Copy files onto the data partition (FAT32) of each stick — see the results above.');
        }

        return Command::SUCCESS;
    }

    protected function isRoot(): bool
    {
        return !function_exists('posix_geteuid') || posix_geteuid() === 0;
    }

    /**
     * Per-device config-key prefix, reusing DeviceInspector::checkAndRecordDeviceName()'s existing
     * arbitrary-prefix mechanism (already used for 'source_') so each target's name history is
     * tracked under its own key pair (e.g. dev_sdb_device / dev_sdb_device_name) instead of one
     * shared singular key. $device is already validated against #^/dev/[a-z][a-z0-9]*$# by the
     * time this is called.
     */
    private static function configPrefixForDevice(string $device): string
    {
        return 'dev_'.substr($device, 5).'_';
    }

    /**
     * Validates the duplicate-mode source device against every selected target: source ≠ any
     * target (checked across the whole list, not just one), the single-target checks in
     * DeviceInspector::validateSourceDevice() (existence, Ventoy detection, mounted partitions,
     * name history) evaluated once against the first target, and a combined letter-swap warning
     * for any additional target that was the previous run's source device.
     *
     * @param list<string> $devices
     * @param array<string, mixed> $config
     */
    private function validateSourceAgainstTargets(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        string $sourceDevice,
        array $devices,
        array $config,
        bool $skipConfirm,
    ): int|string {
        if (in_array($sourceDevice, $devices, true)) {
            $this->io->error('Source and target must be different devices.');

            return Command::FAILURE;
        }

        $res = $this->deviceInspector->validateSourceDevice(
            $input,
            $output,
            $helper,
            $this->io,
            $sourceDevice,
            $devices[0],
            $config,
            $skipConfirm
        );
        if (is_int($res)) {
            return $res;
        }

        $otherSwapped = array_values(
            array_filter(
                array_slice($devices, 1),
                static fn(string $d): bool => ($config['source_device'] ?? null) === $d
            )
        );
        if ($otherSwapped !== []) {
            $this->io->warning(
                'Target(s) '.implode(', ', $otherSwapped).' were the SOURCE device of the previous run — '.
                'device letters may have swapped after replugging. Double-check which stick is which before wiping.'
            );
            if (!$this->askConfirmation(
                $input,
                $output,
                'Continue with these devices anyway? [yes/NO] ',
                false,
                $skipConfirm,
                true,
                '/^yes$/i'
            )) {
                $this->io->note('Aborted.');

                return Command::SUCCESS;
            }
        }

        return $res;
    }

    private function runCmd(string $cmd, bool $passthru = false, ?OutputInterface $output = null): array
    {
        return $this->runner->run(
            $cmd,
            ($passthru && $output !== null) ? static fn(string $chunk) => $output->write($chunk) : null,
            $output !== null ? static fn(string $chunk) => $output->getErrorOutput()->write($chunk) : null,
        );
    }

    /**
     * Per-device PartitionFormatter::mount() suffix so a multi-device batch's mount points and log
     * lines are distinguishable per device.
     */
    private static function mountSuffixForDevice(string $device): string
    {
        return 'dst_'.substr($device, 5);
    }

    protected function getConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/usb-setup.json';
    }
}
