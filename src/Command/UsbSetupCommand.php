<?php

declare(strict_types=1);

namespace App\Command;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class UsbSetupCommand extends BaseCommand
{
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this
            ->setName('usb:setup')
            ->setDescription(
                'Install Ventoy (MBR, FAT32), optionally copy a Debian live ISO and configure persistence.'
            )
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'Target USB block device (e.g. /dev/sdb)')
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->io->error('This script must be run as root (use sudo).');

            return Command::FAILURE;
        }

        $device = $input->getOption('device') !== null ? rtrim((string)$input->getOption('device'), '/') : null;
        $debianIso = $input->getOption('debian-iso') !== null ? (string)$input->getOption('debian-iso') : null;
        $persistenceMib = $input->getOption('persistence-size') !== null ? (int)$input->getOption(
            'persistence-size'
        ) : 2048;
        $ventoyBinHint = $input->getOption('ventoy-bin') !== null ? (string)$input->getOption('ventoy-bin') : null;
        $downloadsFile = $input->getOption('downloads-file') !== null ? (string)$input->getOption(
            'downloads-file'
        ) : null;
        $skipConfirm = (bool)$input->getOption('yes') || !$input->isInteractive();
        $isUpdate = (bool)$input->getOption('update');
        $installVentoy = true;

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $config = $this->loadConfig();
        $cacheDir = $config['cache_dir'] ?? dirname(__DIR__, 2).'/.cache';

        $isoSrc = null;
        $variant = null;
        $deviceDesc = null;

        if ($input->isInteractive()) {
            if ($device === null) {
                $device = $this->promptForDevice($input, $output, $helper, $config['device'] ?? null);
                if ($device === null) {
                    $this->io->note('Aborted.');

                    return Command::SUCCESS;
                }
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

            // Check/record the device's vendor+model name as early as possible, right after the
            // device is finalized, so a mismatch warning appears before any further prompts.
            $deviceDesc = $this->checkAndRecordDeviceName(
                $input,
                $output,
                $helper,
                $device,
                $config,
                true,
                $skipConfirm
            );
            if ($deviceDesc === null) {
                return Command::SUCCESS;
            }

            // Warn immediately if any partitions of this device are mounted on the host
            $mountedParts = $this->getMountedPartitions($device);
            if (!empty($mountedParts)) {
                $lines = ["Partitions of $device are mounted on the host system — unmount them before proceeding:"];
                foreach ($mountedParts as [$part, $mountpoint]) {
                    $lines[] = "  sudo umount $part   (mounted at $mountpoint)";
                }
                $lines[] = '';
                $lines[] = 'Run those commands on the HOST (not inside ddev), then re-run this command.';
                $this->io->warning($lines);
                $q = new ConfirmationQuestion('Continue anyway? [yes/NO] ', false, '/^yes$/i');
                if (!$helper->ask($input, $output, $q)) {
                    $this->io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }

            // Mode: update existing setup or redo from scratch
            if (!$input->getOption('update')) {
                $modeLabels = ['update existing setup', 'redo from scratch'];
                $ventoyDetected = file_exists($device.'2');
                $modeDefault = $ventoyDetected ? 0 : 1;
                $q = new ChoiceQuestion(
                    '<question>Mode:</question> [<info>'.$modeLabels[$modeDefault].'</info>]',
                    $modeLabels,
                    $modeDefault
                );
                $isUpdate = $helper->ask($input, $output, $q) === 'update existing setup';
            }

            // Ventoy install/update (optional)
            $ventoyLabels = ['install/update Ventoy', 'skip Ventoy'];
            $ventoyDefault = ($config['install_ventoy'] ?? true) ? 0 : 1;
            $q = new ChoiceQuestion(
                '<question>Ventoy:</question> [<info>'.$ventoyLabels[$ventoyDefault].'</info>]',
                $ventoyLabels,
                $ventoyDefault
            );
            $installVentoy = $helper->ask($input, $output, $q) === 'install/update Ventoy';

            if ($debianIso === null) {
                $savedIsoSrc = $config['iso_source'] ?? 'download';
                $q = new ChoiceQuestion(
                    "<question>Debian ISO:</question> [<info>$savedIsoSrc</info>] <comment>(downloads are cached in .cache/)</comment>",
                    ['download', 'local path', 'skip'],
                    $savedIsoSrc
                );
                $isoSrc = $helper->ask($input, $output, $q);

                if ($isoSrc === 'download') {
                    $variants = ['standard', 'gnome', 'kde', 'cinnamon', 'lxde', 'lxqt', 'mate', 'xfce'];
                    $savedVariant = $config['iso_variant'] ?? 'standard';
                    $variantDefault = array_search($savedVariant, $variants, true);
                    $variantDefault = $variantDefault !== false ? $variantDefault : 0;
                    $q = new ChoiceQuestion(
                        "<question>Variant:</question> [<info>$savedVariant</info>]",
                        $variants,
                        $variantDefault
                    );
                    $variant = (string)$helper->ask($input, $output, $q);
                } elseif ($isoSrc === 'local path') {
                    $savedPath = $config['iso_path'] ?? null;
                    $q = new Question(
                        '<question>Path to Debian ISO:</question>'.($savedPath ? " [<info>$savedPath</info>]" : '').' ',
                        $savedPath
                    );
                    $ans = $helper->ask($input, $output, $q);
                    $debianIso = ($ans !== null && trim($ans) !== '') ? trim($ans) : null;
                    if ($debianIso !== null && !is_file($debianIso)) {
                        $this->io->error("ISO file not found: $debianIso");

                        return Command::FAILURE;
                    }
                }
            }
            $wantIso = $debianIso !== null || $isoSrc === 'download';
            if ($wantIso && $input->getOption('persistence-size') === null) {
                $persistDefault = (string)min((int)($config['persistence_mib'] ?? 2048), 4090);
                $q = new Question(
                    "<question>Persistence size in MiB</question> (max 4090 on FAT32) [<info>$persistDefault</info>]: ",
                    $persistDefault
                );
                $q->setValidator(static function (?string $v) {
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
                });
                $ans = $helper->ask($input, $output, $q);
                $persistenceMib = (int)($ans ?? $persistDefault);
            }

            if ($downloadsFile === null) {
                $savedDownloads = $config['download_sources'] ?? 'config/usb-downloads.txt';
                $q = new Question(
                    "<question>Software downloads file</question> (http(s) URLs copied to /software/ on the stick; '-' to skip) [<info>$savedDownloads</info>]: ",
                    $savedDownloads
                );
                $ans = $helper->ask($input, $output, $q);
                $ans = $ans !== null ? trim($ans) : '';
                $downloadsFile = ($ans === '' || $ans === '-') ? null : $ans;
            }

            $updates = [
                'device' => $device,
                'persistence_mib' => $persistenceMib,
                'cache_dir' => $cacheDir,
            ];
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
            if ($downloadsFile !== null) {
                $updates['download_sources'] = $downloadsFile;
            }
            $this->saveConfig(array_merge($config, $updates));

            if ($isoSrc === 'download' && $variant !== null) {
                try {
                    $debianIso = $this->downloadDebianIso($variant, $output, $cacheDir);
                } catch (RuntimeException $e) {
                    $this->io->error($e->getMessage());

                    return Command::FAILURE;
                }
            }
        } elseif ($device === null) {
            $this->io->error('--device is required in non-interactive mode.');

            return Command::FAILURE;
        }

        if (!file_exists($device)) {
            $this->io->error("Device not found: $device");

            return Command::FAILURE;
        }
        if (!preg_match('#^/dev/[a-z][a-z0-9]*$#', $device)) {
            $this->io->error("Device must be a top-level block device like /dev/sdb or /dev/nvme0n1, got: $device");

            return Command::FAILURE;
        }

        if ($deviceDesc === null) {
            $deviceDesc = $this->checkAndRecordDeviceName(
                $input,
                $output,
                $helper,
                $device,
                $config,
                $input->isInteractive(),
                $skipConfirm
            );
            if ($deviceDesc === null) {
                return Command::SUCCESS;
            }
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
            $this->requireBin('lsblk');
            $this->requireBin('mkfs.fat');
            $this->requireBin('mkfs.ext4');
            $this->requireBin('mount');
            $this->requireBin('umount');
            $ventoyBin = $this->findVentoyBin($ventoyBinHint);
        } catch (RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->io->section($isUpdate ? 'USB Setup — Update Summary' : 'USB Setup — Summary');
        $rows = [
            ['Device', $deviceDesc],
            ['Mode', $isUpdate ? 'update (skip completed steps)' : 'redo from scratch'],
            ['Partition table', 'MBR'],
            ['Data partition', 'FAT32 (label: VENTOY)'],
            ['Ventoy binary', $ventoyBin],
        ];
        if ($debianIso) {
            $rows[] = ['Debian ISO', $debianIso];
            $rows[] = ['Persistence', "{$persistenceMib} MiB (ext4, Ventoy persistence plugin)"];
        }
        if ($downloadsFile !== null) {
            $rows[] = ['Software downloads', "queued from $downloadsFile"];
        }
        $this->io->table(['Option', 'Value'], $rows);

        if ($isUpdate) {
            $this->io->note("Updating $deviceDesc — data partition is preserved.");
            if (!$skipConfirm) {
                $q = new ConfirmationQuestion('Proceed with update? [YES/no] ', true);
                if (!$helper->ask($input, $output, $q)) {
                    $this->io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }
        } else {
            $this->io->warning("ALL DATA ON {$device} WILL BE ERASED.");
            if (!$skipConfirm) {
                $q = new ConfirmationQuestion(
                    'Are you absolutely sure you want to proceed? [yes/NO] ',
                    false,
                    '/^yes$/i'
                );
                if (!$helper->ask($input, $output, $q)) {
                    $this->io->note('Aborted.');

                    return Command::SUCCESS;
                }
                $q = new ConfirmationQuestion("Second confirmation — wipe {$deviceDesc}? [yes/NO] ", false, '/^yes$/i');
                if (!$helper->ask($input, $output, $q)) {
                    $this->io->note('Aborted.');

                    return Command::SUCCESS;
                }
            }
        }

        $softwareFiles = [];
        if ($downloadsFile !== null) {
            try {
                $softwareFiles = $this->prepareSoftwareDownloads($downloadsFile, $output, $cacheDir);
            } catch (RuntimeException $e) {
                $this->io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $dataPartition = $device.'1';

        // Step 1: Ventoy (-U to update in place, -I for full install)
        if ($installVentoy) {
            try {
                $this->installVentoy($ventoyBin, $device, $skipConfirm, $output, $isUpdate);
            } catch (Throwable $t) {
                $this->io->error($t->getMessage());

                return Command::FAILURE;
            }
            $this->io->text($isUpdate ? 'Ventoy updated.' : 'Ventoy installed (MBR).');

            // Step 2: FAT32 (skip in update mode if already correct)
            if ($isUpdate && $this->isFat32Ventoy($dataPartition)) {
                $this->io->text('Partition 1 already FAT32 (VENTOY) — skipping reformat.');
            } else {
                try {
                    $this->reformatFat32($dataPartition, $output);
                } catch (Throwable $t) {
                    $this->io->error($t->getMessage());

                    return Command::FAILURE;
                }
                $this->io->text('Partition 1 formatted as FAT32.');
            }
        } else {
            $this->io->text('Ventoy install skipped.');
        }

        if ($debianIso !== null || $softwareFiles !== []) {
            $mount = null;
            try {
                $mount = $this->mountPartition($dataPartition, $output);
                $this->io->text("Mounted $dataPartition at $mount.");

                if ($debianIso !== null) {
                    // Step 3: ISO (skip if same file already on stick)
                    $isoName = basename($debianIso);
                    if ($isUpdate && $this->isoMatchesOnStick($mount, $isoName, $debianIso)) {
                        $this->io->text('ISO already on stick — skipping copy.');
                    } else {
                        $isoName = $this->copyIso($debianIso, $mount, $output);
                    }

                    // Step 4: Persistence (skip if already valid ext4 at correct size)
                    $persistDat = 'persistence.dat';
                    if ($isUpdate && $this->isPersistenceValid($mount, $persistenceMib)) {
                        $this->io->text('persistence.dat already valid — skipping creation.');
                    } else {
                        $persistDat = $this->createPersistenceFile($mount, $persistenceMib, $output);
                    }

                    // Step 5: ventoy.json (skip if entry already correct)
                    if ($isUpdate && $this->ventoyJsonHasEntry($mount, $isoName)) {
                        $this->io->text('ventoy.json already has correct entry — skipping.');
                    } else {
                        $this->writeVentoyJson($mount, $isoName, $persistDat);
                    }
                }

                // Step 6: Software downloads (skip individually if already on stick, in update mode)
                if ($softwareFiles !== []) {
                    $this->copySoftwareFiles($softwareFiles, $mount, $output, $isUpdate);
                }

                $this->runCmd('sync', false, $output);
                $this->io->text('Synced filesystem.');
            } catch (Throwable $t) {
                $this->io->error($t->getMessage());
                if ($mount !== null) {
                    $this->unmountAndClean($mount);
                }

                return Command::FAILURE;
            }
            $this->unmountAndClean($mount);
            $this->io->text("Unmounted $dataPartition.");
        }

        $this->io->success('USB stick is ready.');
        if ($debianIso !== null) {
            $this->io->text([
                'Boot from the stick and select the Debian ISO in the Ventoy menu.',
                'Persistence is active for that ISO via /ventoy/ventoy.json.',
            ]);
        }
        if ($softwareFiles !== []) {
            $this->io->text(
                sprintf('%d software installer(s) copied to /software/ on the stick.', count($softwareFiles))
            );
        }
        if ($debianIso === null && $softwareFiles === []) {
            $this->io->text("Copy ISO files onto $dataPartition (FAT32) to boot them with Ventoy.");
        }

        return Command::SUCCESS;
    }

    private function promptForDevice(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        ?string $savedDevice = null
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
            $q = new Question(
                '<question>Target device (e.g. /dev/sdb):</question>'.($savedDevice ? " [<info>$savedDevice</info>]" : '').' ',
                $savedDevice
            );
            $answer = $helper->ask($input, $output, $q);

            return ($answer !== null && trim($answer) !== '') ? rtrim(trim($answer), '/') : null;
        }

        $choices = [];
        $deviceMap = [];
        $defaultIdx = 0;
        foreach ($devices as $idx => $dev) {
            $parts = array_filter([
                $dev['tran'] ?? '',
                trim((string)($dev['vendor'] ?? '')),
                trim((string)($dev['model'] ?? '')),
            ]);
            $label = sprintf('/dev/%-12s  %6s  %s', $dev['name'], $dev['size'], implode(' ', $parts));
            $choices[] = $label;
            $deviceMap[$label] = '/dev/'.$dev['name'];
            if ($savedDevice !== null && $deviceMap[$label] === $savedDevice) {
                $defaultIdx = $idx;
            }
        }
        $choices[] = 'Enter path manually';

        $defaultLabel = $savedDevice !== null && $defaultIdx < count(
            $choices
        ) - 1 ? " [<info>$savedDevice</info>]" : '';
        $q = new ChoiceQuestion("<question>Target device:</question>$defaultLabel", $choices, $defaultIdx);
        $chosen = $helper->ask($input, $output, $q);

        if ($chosen === 'Enter path manually') {
            $q = new Question('<question>Device path (e.g. /dev/sdb):</question> ');
            $answer = $helper->ask($input, $output, $q);

            return ($answer !== null && trim($answer) !== '') ? rtrim(trim($answer), '/') : null;
        }

        return $deviceMap[$chosen];
    }

    private function runCmd(string $cmd, bool $passthru = false, ?OutputInterface $output = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("Failed to start: {$cmd}");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        while (true) {
            $status = proc_get_status($proc);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out !== false && $out !== '') {
                $stdout .= $out;
                if ($passthru && $output !== null) {
                    $output->write($out);
                }
            }
            if ($err !== false && $err !== '' && $output !== null) {
                $output->getErrorOutput()->write($err);
            }
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        }
        foreach ([0 => false, 1 => true] as $pipe => $isErr) {
            $chunk = stream_get_contents($pipes[$pipe + 1]);
            if ($chunk) {
                if (!$isErr) {
                    $stdout .= $chunk;
                    if ($passthru && $output !== null) {
                        $output->write($chunk);
                    }
                } elseif ($output !== null) {
                    $output->getErrorOutput()->write($chunk);
                }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stdout];
    }

    private function checkAndRecordDeviceName(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $helper,
        string $device,
        array $config,
        bool $interactive,
        bool $skipConfirm
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

        $sameDeviceAsSaved = ($config['device'] ?? null) === $device;
        $hasSavedDeviceName = array_key_exists('device_name', $config);
        $savedDeviceName = $config['device_name'] ?? null;

        $warned = false;
        if ($sameDeviceAsSaved && !$hasSavedDeviceName) {
            $this->io->warning(
                "No recorded name on file for $device from a previous run (currently detected as ".
                "\"$currentDeviceName\") — cannot verify this is still the same physical drive."
            );
            $warned = true;
        } elseif (
            $sameDeviceAsSaved
            && $savedDeviceName !== null
            && $savedDeviceName !== ''
            && $savedDeviceName !== $currentDeviceName
        ) {
            $this->io->warning(
                "Device $device now shows as \"$currentDeviceName\", but was \"$savedDeviceName\" last time — ".
                'device letters can shift across reboots/replugging.'
            );
            $warned = true;
        }

        if ($warned && $interactive && !$skipConfirm) {
            $q = new ConfirmationQuestion('Continue with this device anyway? [yes/NO] ', false, '/^yes$/i');
            if (!$helper->ask($input, $output, $q)) {
                $this->io->note('Aborted.');

                return null;
            }
        }

        if ($interactive) {
            $this->saveConfig(array_merge($this->loadConfig(), ['device_name' => $currentDeviceName]));
        }

        return $deviceDesc;
    }

    private function lsblkInfo(string $device): array
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

    private function getMountedPartitions(string $device): array
    {
        // /proc/1/mounts reflects the HOST's mount table when pid:host is set in docker-compose;
        // fall back to the container's own /proc/mounts otherwise.
        $mounts = @file('/proc/1/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            ?: @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                ?: [];
        $result = [];
        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[0], $parts[1]) && str_starts_with($parts[0], $device)) {
                $result[] = [$parts[0], $parts[1]];
            }
        }

        return $result;
    }

    private function downloadDebianIso(string $variant, OutputInterface $output, string $cacheDir): string
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            throw new RuntimeException("Cannot create ISO cache directory: $cacheDir");
        }

        $baseUrl = 'https://cdimage.debian.org/debian-cd/current-live/amd64/iso-hybrid/';

        $this->io->text("Fetching ISO listing from $baseUrl ...");
        [$exit, $html] = $this->runCmd('curl -fsSL '.escapeshellarg($baseUrl), false, $output);
        if ($exit !== 0 || trim($html) === '') {
            throw new RuntimeException('Failed to fetch Debian ISO listing.');
        }

        $pattern = '/href="(debian-live-[\d.]+-amd64-'.preg_quote($variant, '/').'\.iso)"/';
        if (!preg_match($pattern, $html, $m)) {
            throw new RuntimeException("No Debian live ISO found for variant '$variant'.");
        }

        $filename = $m[1];
        $url = $baseUrl.$filename;
        $dest = rtrim($cacheDir, '/').'/'.$filename;
        $checksumUrl = $baseUrl.'SHA256SUMS';

        $this->io->text('Fetching checksums...');
        [$exit, $sums] = $this->runCmd('curl -fsSL '.escapeshellarg($checksumUrl), false, $output);
        if ($exit !== 0 || trim($sums) === '') {
            throw new RuntimeException('Failed to fetch SHA256SUMS.');
        }
        $expected = self::parseChecksum($sums, $filename);
        if ($expected === null) {
            throw new RuntimeException("No checksum found for $filename in SHA256SUMS.");
        }

        if (is_file($dest)) {
            $this->io->text('Verifying cached ISO...');
            if ($this->sha256($dest, $output) === $expected) {
                $this->io->text("Cached ISO verified: $dest");

                return $dest;
            }
            $this->io->warning('Cached ISO is corrupt or incomplete — re-downloading.');
            @unlink($dest);
        }

        $this->io->text("Downloading $filename (this may take a while)...");
        [$exit] = $this->runCmd(
            'curl -fL -# -o '.escapeshellarg($dest).' '.escapeshellarg($url),
            true,
            $output
        );
        if ($exit !== 0) {
            @unlink($dest);
            throw new RuntimeException("Failed to download $url");
        }

        $this->io->text('Verifying download...');
        if ($this->sha256($dest, $output) !== $expected) {
            @unlink($dest);
            throw new RuntimeException('Checksum mismatch after download — file deleted.');
        }

        $this->io->text("Download verified. Saved to $dest");

        return $dest;
    }

    private static function parseChecksum(string $sumsContent, string $filename): ?string
    {
        foreach (explode("\n", $sumsContent) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            if (isset($parts[1]) && trim($parts[1], '* ') === $filename) {
                return strtolower($parts[0]);
            }
        }

        return null;
    }

    private function sha256(string $path, OutputInterface $output): string
    {
        [$exit, $out] = $this->runCmd('sha256sum '.escapeshellarg($path), false, $output);
        if ($exit !== 0) {
            throw new RuntimeException("sha256sum failed on $path");
        }

        return strtolower(explode(' ', trim($out))[0]);
    }

    private function requireBin(string $bin): string
    {
        [$exit, $out] = $this->runCmd('which '.escapeshellarg($bin).' 2>/dev/null');
        if ($exit !== 0 || trim($out) === '') {
            throw new RuntimeException("Required binary not found: {$bin}");
        }

        return trim($out);
    }

    private function findVentoyBin(?string $hint): string
    {
        $candidates = array_filter([
            $hint,
            getenv('VENTOY_BIN') ?: null,
            '/opt/ventoy/Ventoy2Disk.sh',
            '/usr/share/ventoy/Ventoy2Disk.sh',
            '/usr/lib/ventoy/Ventoy2Disk.sh',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        [$exit, $out] = $this->runCmd('which ventoy 2>/dev/null');
        if ($exit === 0 && trim($out) !== '') {
            return trim($out);
        }
        throw new RuntimeException(
            "Ventoy not found. Install it or pass --ventoy-bin /path/to/Ventoy2Disk.sh.\n".
            '  Download: https://github.com/ventoy/Ventoy/releases'
        );
    }

    private function prepareSoftwareDownloads(string $downloadsFile, OutputInterface $output, string $cacheDir): array
    {
        $classified = $this->parseDownloadsFile($downloadsFile);

        foreach ($classified['torrent'] as $line) {
            $this->io->note("Torrent link recognized but not yet supported (planned for a future release): $line");
        }
        foreach ($classified['invalid'] as $line) {
            $this->io->warning(
                'Skipping invalid downloads-file line (expected http(s)://, magnet:, urn:btmh:, '.
                "an existing local file/directory path, or a 'dir/**' recursive directory path): $line"
            );
        }

        $files = $this->collectLocalFiles($classified['local']);
        if ($files !== []) {
            $this->io->text(sprintf('Found %d locally-provided file(s).', count($files)));
        }

        if ($classified['http'] === [] && $files === []) {
            $this->io->text('No software downloads queued.');

            return [];
        }

        foreach ($classified['http'] as $url) {
            try {
                $dest = $this->downloadSoftwareFile($url, $output, $cacheDir);
                $files[] = ['source' => $dest, 'relative' => basename($dest)];
            } catch (RuntimeException $e) {
                $this->io->warning("Skipping $url — ".$e->getMessage());
            }
        }

        return $files;
    }

    /**
     * @return array{
     *     http: string[],
     *     torrent: string[],
     *     local: array<array{path: string, recursive: bool}>,
     *     invalid: string[]
     * }
     */
    private function parseDownloadsFile(string $path): array
    {
        $lines = array_values(
            array_filter(
                array_map('trim', file($path)),
                static fn($l) => $l !== '' && $l[0] !== '#'
            )
        );

        $result = ['http' => [], 'torrent' => [], 'local' => [], 'invalid' => []];
        foreach ($lines as $line) {
            $c = self::classifyDownloadLine($line, 'is_file', 'is_dir');
            if ($c['type'] === 'local') {
                $result['local'][] = ['path' => $c['path'], 'recursive' => $c['recursive']];
            } else {
                $result[$c['type']][] = $line;
            }
        }

        return $result;
    }

    /**
     * Classify one downloads-file line. Pure except for the injected $isFile/$isDir probes,
     * so the URL/torrent/recursive-suffix logic is unit-testable with stub callables.
     *
     * @param callable(string): bool $isFile
     * @param callable(string): bool $isDir
     * @return array{type: 'http'|'torrent'|'local'|'invalid', path: string, recursive: bool}
     */
    private static function classifyDownloadLine(string $line, callable $isFile, callable $isDir): array
    {
        if (preg_match('#^https?://#i', $line)) {
            return ['type' => 'http', 'path' => $line, 'recursive' => false];
        }
        if (preg_match('#^(magnet:|urn:btmh:)#i', $line)) {
            return ['type' => 'torrent', 'path' => $line, 'recursive' => false];
        }
        if (str_ends_with($line, '/**') && $isDir(substr($line, 0, -3))) {
            return ['type' => 'local', 'path' => substr($line, 0, -3), 'recursive' => true];
        }
        if ($isFile($line) || $isDir($line)) {
            return ['type' => 'local', 'path' => $line, 'recursive' => false];
        }

        return ['type' => 'invalid', 'path' => $line, 'recursive' => false];
    }

    /**
     * @param array<array{path: string, recursive: bool}> $entries
     *
     * @return array<array{source: string, relative: string}>
     */
    private function collectLocalFiles(array $entries): array
    {
        $files = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            if (!is_dir($path)) {
                $files[] = ['source' => $path, 'relative' => basename($path)];
                continue;
            }

            if (!$entry['recursive']) {
                foreach (glob(rtrim($path, '/').'/*') ?: [] as $child) {
                    if (is_file($child)) {
                        $files[] = ['source' => $child, 'relative' => basename($child)];
                    }
                }
                continue;
            }

            $root = rtrim($path, '/');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $relative = ltrim(substr($fileInfo->getPathname(), strlen($root)), '/');
                $files[] = ['source' => $fileInfo->getPathname(), 'relative' => $relative];
            }
        }

        return $files;
    }

    private function downloadSoftwareFile(string $url, OutputInterface $output, string $cacheDir): string
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            throw new RuntimeException("Cannot create download cache directory: $cacheDir");
        }

        $filename = basename((string)parse_url($url, PHP_URL_PATH));
        if ($filename === '' || $filename === '/') {
            throw new RuntimeException("Cannot determine filename from URL: $url");
        }
        $dest = rtrim($cacheDir, '/').'/'.$filename;

        if (is_file($dest) && filesize($dest) > 0) {
            $this->io->text("Already cached (no checksum available to verify) — skipping: $filename");

            return $dest;
        }

        $headerFile = $dest.'.headers';
        $this->io->text("Downloading $filename ...");
        [$exit] = $this->runCmd(
            'curl -fL -# -D '.escapeshellarg($headerFile).' -o '.escapeshellarg($dest).' '.escapeshellarg($url),
            true,
            $output
        );
        $contentType = $this->lastContentType($headerFile);
        @unlink($headerFile);
        if ($exit !== 0) {
            @unlink($dest);
            throw new RuntimeException("Failed to download $url");
        }
        if (self::shouldRejectAsHtml($contentType)) {
            @unlink($dest);
            throw new RuntimeException(
                "Refusing $url — server returned Content-Type \"$contentType\" instead of a binary file ".
                '(likely a login/session-gated page, not a direct download link).'
            );
        }

        $this->io->text("Downloaded: $dest");

        return $dest;
    }

    private function lastContentType(string $headerFile): ?string
    {
        if (!is_file($headerFile)) {
            return null;
        }

        return self::parseLastContentType(file($headerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    /**
     * Return the LAST Content-Type value across header lines (curl -D accumulates headers over
     * redirects, so the final response's type is the one that matters). Pure — unit-testable.
     *
     * @param string[] $headerLines
     */
    private static function parseLastContentType(array $headerLines): ?string
    {
        $type = null;
        foreach ($headerLines as $line) {
            if (preg_match('/^content-type:\s*(.+)$/i', trim($line), $m)) {
                $type = trim($m[1]);
            }
        }

        return $type;
    }

    /**
     * A binary download must not have a text/* Content-Type — that signals a login/session-gated
     * HTML page rather than the installer. Pure — unit-testable.
     */
    private static function shouldRejectAsHtml(?string $contentType): bool
    {
        return $contentType !== null && preg_match('#^text/#i', $contentType) === 1;
    }

    private function installVentoy(
        string $ventoyBin,
        string $device,
        bool $skipConfirm,
        OutputInterface $output,
        bool $update = false
    ): void {
        $flag = $update ? '-u' : '-I';
        $this->io->text($update ? "Updating Ventoy on $device..." : "Installing Ventoy onto $device (MBR mode)...");
        $cmd = 'bash '.escapeshellarg($ventoyBin)." $flag ".escapeshellarg($device);
        if ($skipConfirm) {
            $cmd = 'yes | '.$cmd;
        }
        [$exit] = $this->runCmd($cmd, true, $output);
        if ($exit !== 0) {
            throw new RuntimeException("Ventoy installation failed (exit {$exit}).");
        }
        $this->runCmd('udevadm settle 2>/dev/null');
        sleep(2);
        foreach (['1', '2'] as $n) {
            $part = escapeshellarg($device.$n);
            // Unmount from the host mount namespace (pid:host lets nsenter target host PID 1)
            $this->runCmd("nsenter -t 1 --mount -- umount -f $part 2>/dev/null");
            // Also unmount from within the container namespace and kill any holder processes
            $this->runCmd('fuser -km '.$part.' 2>/dev/null');
            $this->runCmd('umount -f '.$part.' 2>/dev/null');
        }
        $this->runCmd('partprobe '.escapeshellarg($device).' 2>/dev/null');
        $this->runCmd('udevadm settle 2>/dev/null');
    }

    private function isFat32Ventoy(string $partition): bool
    {
        [$exit, $type] = $this->runCmd('blkid -o value -s TYPE '.escapeshellarg($partition).' 2>/dev/null');
        if ($exit !== 0 || strtolower(trim($type)) !== 'vfat') {
            return false;
        }
        [$exit, $label] = $this->runCmd('blkid -o value -s LABEL '.escapeshellarg($partition).' 2>/dev/null');

        return $exit === 0 && trim($label) === 'VENTOY';
    }

    private function reformatFat32(string $partition, OutputInterface $output): void
    {
        $this->io->text("Reformatting $partition as FAT32...");
        $this->waitForPartition($partition);

        $exit = 1;
        $partArg = escapeshellarg($partition);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->runCmd("nsenter -t 1 --mount -- umount -f $partArg 2>/dev/null");
            $this->runCmd('fuser -km '.$partArg.' 2>/dev/null');
            $this->runCmd('umount -f '.$partArg.' 2>/dev/null');
            $this->runCmd('udevadm settle 2>/dev/null');
            [$exit] = $this->runCmd(
                'mkfs.fat -F 32 -n VENTOY '.escapeshellarg($partition).' 2>&1',
                true,
                $output
            );
            if ($exit === 0) {
                break;
            }
            $this->io->text("Partition busy, retrying in {$attempt}s...");
            sleep($attempt);
        }

        if ($exit !== 0) {
            throw new RuntimeException(
                "mkfs.fat failed on $partition after retries — the partition is likely still held by the host OS.\n".
                "Unmount it on the HOST (not inside ddev) and try again:\n".
                "  sudo umount $partition"
            );
        }
    }

    private function waitForPartition(string $part, int $timeoutSec = 10): void
    {
        $deadline = time() + $timeoutSec;
        while (!file_exists($part) && time() < $deadline) {
            usleep(300000);
        }
        if (!file_exists($part)) {
            throw new RuntimeException("Partition {$part} did not appear within {$timeoutSec}s.");
        }
    }

    private function mountPartition(string $partition, OutputInterface $output): string
    {
        $mount = sys_get_temp_dir().'/usb_setup_'.getmypid();
        if (!is_dir($mount) && !mkdir($mount, 0700, true) && !is_dir($mount)) {
            throw new RuntimeException("Cannot create mount point {$mount}.");
        }
        [$exit] = $this->runCmd(
            'mount '.escapeshellarg($partition).' '.escapeshellarg($mount).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            throw new RuntimeException("Failed to mount {$partition} at {$mount}.");
        }

        return $mount;
    }

    private function isoMatchesOnStick(string $mountPoint, string $isoName, string $localIsoPath): bool
    {
        $dest = rtrim($mountPoint, '/').'/'.$isoName;

        return is_file($dest) && is_file($localIsoPath) && filesize($dest) === filesize($localIsoPath);
    }

    private function copyIso(string $isoPath, string $mountPoint, OutputInterface $output): string
    {
        $dest = rtrim($mountPoint, '/').'/'.basename($isoPath);
        $isoSizeMb = filesize($isoPath) / 1048576;
        if ($isoSizeMb > 4090) {
            throw new RuntimeException(
                "ISO exceeds ~4 GiB FAT32 single-file limit ({$isoSizeMb} MiB) — refusing to copy."
            );
        }
        $this->io->text(sprintf('Copying %s to USB (%d MiB)...', basename($isoPath), (int)round($isoSizeMb)));
        [$exit] = $this->runCmd(
            'cp --no-preserve=all '.escapeshellarg($isoPath).' '.escapeshellarg($dest).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            throw new RuntimeException('Failed to copy ISO to USB.');
        }

        return basename($isoPath);
    }

    private function isPersistenceValid(string $mountPoint, int $expectedMib): bool
    {
        $datFile = rtrim($mountPoint, '/').'/persistence.dat';
        if (!is_file($datFile)) {
            return false;
        }
        [$exit, $type] = $this->runCmd('blkid -o value -s TYPE '.escapeshellarg($datFile).' 2>/dev/null');
        if ($exit !== 0 || trim($type) !== 'ext4') {
            return false;
        }
        [$exit, $label] = $this->runCmd('blkid -o value -s LABEL '.escapeshellarg($datFile).' 2>/dev/null');
        if ($exit !== 0 || trim($label) !== 'persistence') {
            return false;
        }

        // Allow ±10 MiB tolerance for filesystem overhead
        return abs((int)(filesize($datFile) / 1048576) - $expectedMib) <= 10;
    }

    private function createPersistenceFile(string $mountPoint, int $sizeMib, OutputInterface $output): string
    {
        $datFile = rtrim($mountPoint, '/').'/persistence.dat';
        if ($sizeMib > 4090) {
            $this->io->warning("Persistence size {$sizeMib} MiB exceeds FAT32 limit. Capping at 4090 MiB.");
            $sizeMib = 4090;
        }
        $this->io->text("Creating persistence file ({$sizeMib} MiB)...");
        [$exit] = $this->runCmd(
            'fallocate -l '.escapeshellarg("{$sizeMib}M").' '.escapeshellarg($datFile).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            $this->io->warning('fallocate failed, falling back to dd (slower)...');
            [$exit] = $this->runCmd(
                'dd if=/dev/zero of='.escapeshellarg($datFile).' bs=1M count='.$sizeMib.' 2>&1',
                true,
                $output
            );
            if ($exit !== 0) {
                throw new RuntimeException('Failed to create persistence file.');
            }
        }
        $this->io->text('Formatting persistence file as ext4...');
        [$exit] = $this->runCmd(
            'mkfs.ext4 -L persistence -F '.escapeshellarg($datFile).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            throw new RuntimeException('mkfs.ext4 failed on persistence file.');
        }
        $tmpMount = sys_get_temp_dir().'/persist_'.getmypid();
        if (!is_dir($tmpMount) && !mkdir($tmpMount, 0700, true) && !is_dir($tmpMount)) {
            throw new RuntimeException("Cannot create temp mount {$tmpMount}.");
        }
        [$exit] = $this->runCmd(
            'mount -o loop '.escapeshellarg($datFile).' '.escapeshellarg($tmpMount).' 2>&1',
            true,
            $output
        );
        if ($exit !== 0) {
            @rmdir($tmpMount);
            throw new RuntimeException('Failed to loop-mount persistence file.');
        }
        file_put_contents($tmpMount.'/persistence.conf', "/ union\n");
        $this->io->text('Written /persistence.conf inside persistence file.');
        $this->runCmd('umount '.escapeshellarg($tmpMount).' 2>/dev/null');
        @rmdir($tmpMount);

        return 'persistence.dat';
    }

    private function ventoyJsonHasEntry(string $mountPoint, string $isoName): bool
    {
        $jsonPath = rtrim($mountPoint, '/').'/ventoy/ventoy.json';
        if (!is_file($jsonPath)) {
            return false;
        }
        $data = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            return false;
        }
        foreach ($data['persistence'] ?? [] as $entry) {
            if (($entry['image'] ?? '') === '/'.$isoName) {
                return true;
            }
        }

        return false;
    }

    private function writeVentoyJson(string $mountPoint, string $isoName, string $persistenceDat): void
    {
        $ventoyDir = rtrim($mountPoint, '/').'/ventoy';
        if (!is_dir($ventoyDir) && !mkdir($ventoyDir, 0755, true) && !is_dir($ventoyDir)) {
            throw new RuntimeException('Cannot create /ventoy directory on USB.');
        }
        $jsonPath = $ventoyDir.'/ventoy.json';
        $existing = [];
        if (is_file($jsonPath)) {
            $existing = json_decode((string)file_get_contents($jsonPath), true) ?? [];
        }
        $existing['persistence'][] = [
            'image' => '/'.$isoName,
            'backend' => '/'.$persistenceDat,
        ];
        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($jsonPath, $json);
        $this->io->text(['Written /ventoy/ventoy.json:', $json]);
    }

    /**
     * @param array<array{source: string, relative: string}> $files
     */
    private function copySoftwareFiles(
        array $files,
        string $mountPoint,
        OutputInterface $output,
        bool $isUpdate
    ): void {
        $dir = rtrim($mountPoint, '/').'/software';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create /software directory on USB.');
        }

        foreach ($files as $file) {
            $source = $file['source'];
            $relative = $file['relative'];
            $destDir = dirname($dir.'/'.$relative);
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $this->io->warning("Cannot create destination directory for $relative on USB — skipping.");
                continue;
            }
            if ($isUpdate && $this->isoMatchesOnStick($dir, $relative, $source)) {
                $this->io->text("$relative already on stick — skipping copy.");
                continue;
            }
            $sizeMb = filesize($source) / 1048576;
            if ($sizeMb > 4090) {
                $this->io->warning(
                    "Skipping $relative — exceeds ~4 GiB FAT32 single-file limit ({$sizeMb} MiB)."
                );
                continue;
            }
            $this->io->text(sprintf('Copying %s to /software/ (%d MiB)...', $relative, (int)round($sizeMb)));
            $dest = $dir.'/'.$relative;
            [$exit] = $this->runCmd(
                'cp --no-preserve=all '.escapeshellarg($source).' '.escapeshellarg($dest).' 2>&1',
                true,
                $output
            );
            if ($exit !== 0) {
                $this->io->warning("Failed to copy $relative to USB — skipping.");
            }
        }
    }

    private function unmountAndClean(string $mount): void
    {
        $this->runCmd('umount '.escapeshellarg($mount).' 2>/dev/null');
        @rmdir($mount);
    }

    protected function getConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/usb-setup.json';
    }

    private function listUsbDevices(OutputInterface $output): void
    {
        [, $out] = $this->runCmd('lsblk -o NAME,SIZE,TYPE,TRAN,VENDOR,MODEL,MOUNTPOINT -d 2>/dev/null');
        $output->writeln('Detected block devices:');
        $output->writeln($out);
    }
}
