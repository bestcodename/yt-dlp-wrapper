<?php

declare(strict_types=1);

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class PlaylistsSyncCommand extends BaseCommand
{
    private const DEFAULT_FORMATS = 'original,mp3,wav,flac';
    private const LOSSLESS_CODECS = ['flac', 'alac', 'wav', 'aiff', 'ape', 'wavpack', 'tak', 'tta'];
    /**
     * Guided tiers for the interactive min-odg prompt. Thresholds are estimated PEAQ ODG
     * values (Objective Difference Grade: 0 = transparent … -4 = very annoying); the
     * per-codec bitrate equivalents in the hints follow ODG_CALIBRATION via minKbpsForOdg().
     *
     * @var array<int, array{label: string, hint: string, odg: ?float}>
     */
    public const MIN_ODG_TIERS = [
        1 => [
            'label' => 'Archive / Pro Club Standard',
            'hint' => 'ODG ≥ -0.2 | ≈320 kbps MP3 / 256 AAC / 182 Opus | perfect for large venue PAs',
            'odg' => -0.2,
        ],
        2 => [
            'label' => 'Semi-Pro Performance Minimum',
            'hint' => 'ODG ≥ -1.0 | ≈192 kbps MP3 / 128 AAC / 96 Opus | minimum safe gig threshold',
            'odg' => -1.0,
        ],
        3 => [
            'label' => 'Preview Only',
            'hint' => 'ODG ≥ -2.0 | ≈128 kbps MP3 / 89 AAC / 64 Opus | casual listening only',
            'odg' => -2.0,
        ],
        4 => [
            'label' => 'Off',
            'hint' => 'disable quality filtering',
            'odg' => null,
        ],
    ];
    /**
     * Estimated PEAQ ODG anchor points per codec family: [kbps, ODG], kbps strictly
     * increasing, ODG non-decreasing. Sources of unknown codec use the MP3 curve
     * (most conservative); lossless codecs are always ODG 0. Values between anchors
     * are interpolated linearly; a virtual [0, -4.0] origin anchors the low end.
     *
     * @var array<string, list<array{0: float|int, 1: float}>>
     */
    private const ODG_CALIBRATION = [
        'mp3' => [[64, -3.7], [96, -3.0], [128, -2.0], [160, -1.4], [192, -1.0], [256, -0.5], [320, -0.2]],
        'aac' => [[64, -2.7], [96, -1.8], [128, -1.0], [160, -0.6], [192, -0.4], [256, -0.2], [320, -0.1]],
        'opus' => [[64, -2.0], [96, -1.0], [128, -0.5], [160, -0.3], [192, -0.15], [256, -0.1]],
        'vorbis' => [[64, -2.5], [96, -1.5], [128, -0.8], [160, -0.5], [192, -0.3], [256, -0.15]],
    ];
    public const SOURCE_SPOTIFY = 'spotify';
    public const SOURCE_YTDLP = 'ytdlp';
    private const VALID_FORMATS = ['original', 'mp3', 'wav', 'flac'];
    private ?string $cookiesFile;
    private string $extractorRetries;
    private string $ffmpegBin;
    private string $ffprobeBin;
    private ?string $limitRate;
    private ?float $minOdg;
    private string $minOdgMode;
    private string $mp3Bitrate;
    private string $mp3Mode;
    private string $mp3Quality;
    private int $pauseBetween;
    private bool $reencodeStaleMp3;
    /** @var list<string> target paths reencoded this run; reported in a summary, never inline (see ensureConverted) */
    private array $reencodedStaleMp3 = [];
    private string $retrySleep;
    private string $sleepRequests;
    private string $spotdlBin;
    private ?string $spotdlCookieFile;
    private string $ytDlpBin;

    private static function readTagsFromInfoJson(string $path): array
    {
        $j = json_decode((string)file_get_contents($path), true);

        return is_array($j) ? self::mapInfoJsonToTags($j) : [];
    }

    /**
     * Map a decoded yt-dlp .info.json to ffmpeg tags. Pure — unit-testable.
     *
     * @param array<string, mixed> $json
     * @return array{title: string, artist: string, album: string, genre: string, comment: string, date: string}
     */
    private static function mapInfoJsonToTags(array $json): array
    {
        return [
            'title' => (string)($json['title'] ?? ''),
            'artist' => (string)($json['uploader'] ?? ($json['artist'] ?? '')),
            'album' => (string)($json['playlist_title'] ?? ($json['album'] ?? '')),
            'genre' => (string)($json['genre'] ?? ''),
            'comment' => (string)($json['description'] ?? ''),
            'date' => (string)($json['upload_date'] ?? ''),
        ];
    }

    protected function configure(): void
    {
        $this
            ->setName('playlists:sync')
            ->setDescription('Download SoundCloud/Spotify/YouTube playlists and convert to MP3/WAV/FLAC.')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Path to file with playlist URLs')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Base output directory')
            ->addOption('playlists-dir', null, InputOption::VALUE_REQUIRED, 'Directory for M3U8 playlist files')
            ->addOption(
                'min-odg',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum estimated ODG, -4..0 (see --min-odg-mode)'
            )
            ->addOption(
                'min-odg-mode',
                null,
                InputOption::VALUE_REQUIRED,
                'Below --min-odg: "warn" (default) or "filter"'
            )
            ->addOption(
                'formats',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated output formats: original, mp3, wav, flac'
            )
            ->addOption('mp3-mode', null, InputOption::VALUE_REQUIRED, 'MP3 encoding: "cbr" (default) or "vbr"')
            ->addOption('mp3-bitrate', null, InputOption::VALUE_REQUIRED, 'CBR bitrate in kbps (--mp3-mode=cbr only)')
            ->addOption(
                'mp3-quality',
                null,
                InputOption::VALUE_REQUIRED,
                'LAME VBR quality: 0 = highest, 9 = lowest (--mp3-mode=vbr only)'
            )
            ->addOption(
                'reencode-stale-mp3',
                null,
                InputOption::VALUE_NEGATABLE,
                'Re-encode existing mp3s whose average bitrate does not match --mp3-bitrate'
                .' (fixes pre-CBR VBR files misreporting bitrate in Rekordbox; --mp3-mode=cbr only)',
                true,
            )
            ->addOption('library-dir', null, InputOption::VALUE_REQUIRED, 'Shared audio library directory')
            ->addOption('archive-dir', null, InputOption::VALUE_REQUIRED, 'Download archive directory')
            ->addOption(
                'lib-filename-template',
                null,
                InputOption::VALUE_REQUIRED,
                'yt-dlp filename template for library files'
            )
            ->addOption(
                'cookies',
                null,
                InputOption::VALUE_REQUIRED,
                'Cookie file (Netscape format) used by both yt-dlp and spotdl'
            )
            ->addOption(
                'ytdlp-cookies',
                null,
                InputOption::VALUE_REQUIRED,
                'Cookie file for yt-dlp only (overrides --cookies)'
            )
            ->addOption(
                'spotdl-cookies',
                null,
                InputOption::VALUE_REQUIRED,
                'Cookie file for spotdl only (overrides --cookies)'
            )
            ->addOption('ytdlp-bin', null, InputOption::VALUE_REQUIRED, 'yt-dlp binary')
            ->addOption('spotdl-bin', null, InputOption::VALUE_REQUIRED, 'spotdl binary')
            ->addOption('ffmpeg-bin', null, InputOption::VALUE_REQUIRED, 'ffmpeg binary')
            ->addOption('ffprobe-bin', null, InputOption::VALUE_REQUIRED, 'ffprobe binary')
            ->addOption('extractor-retries', null, InputOption::VALUE_REQUIRED, 'yt-dlp --extractor-retries value')
            ->addOption('retry-sleep', null, InputOption::VALUE_REQUIRED, 'yt-dlp --retry-sleep value')
            ->addOption(
                'sleep-requests',
                null,
                InputOption::VALUE_REQUIRED,
                'yt-dlp --sleep-requests value (number or min-max range)'
            )
            ->addOption('limit-rate', null, InputOption::VALUE_REQUIRED, 'yt-dlp --limit-rate value (e.g. 1M)')
            ->addOption('pause-between', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between playlists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->loadDotenv();

        $config = $this->loadConfig();

        // E-category: CLI option, env override, config-file fallback, or ddev-installed default
        $this->ytDlpBin = $this->resolveParam(
            $input->getOption('ytdlp-bin'),
            'YTDLP_BIN',
            $config,
            'ytdlp_bin',
            'yt-dlp'
        );
        $this->spotdlBin = $this->resolveParam(
            $input->getOption('spotdl-bin'),
            'SPOTDL_BIN',
            $config,
            'spotdl_bin',
            'spotdl'
        );
        $this->ffmpegBin = $this->resolveParam(
            $input->getOption('ffmpeg-bin'),
            'FFMPEG_BIN',
            $config,
            'ffmpeg_bin',
            'ffmpeg'
        );
        $this->ffprobeBin = $this->resolveParam(
            $input->getOption('ffprobe-bin'),
            'FFPROBE_BIN',
            $config,
            'ffprobe_bin',
            'ffprobe'
        );
        $this->mp3Quality = $this->resolveParam(
            $input->getOption('mp3-quality'),
            'MP3_QUALITY',
            $config,
            'mp3_quality',
            '0'
        );
        $this->mp3Bitrate = $this->resolveParam(
            $input->getOption('mp3-bitrate'),
            'MP3_BITRATE',
            $config,
            'mp3_bitrate',
            '320'
        );
        if (!is_numeric($this->mp3Bitrate) || (int)$this->mp3Bitrate <= 0) {
            $this->io->error(
                "Invalid --mp3-bitrate / MP3_BITRATE value: {$this->mp3Bitrate} (expected a positive number, kbps)"
            );

            return Command::FAILURE;
        }
        $this->mp3Mode = strtolower(
            $this->resolveParam($input->getOption('mp3-mode'), 'MP3_MODE', $config, 'mp3_mode', 'cbr')
        );
        if (!in_array($this->mp3Mode, ['cbr', 'vbr'], true)) {
            $this->io->error("Invalid --mp3-mode / MP3_MODE value: {$this->mp3Mode} (expected \"cbr\" or \"vbr\")");

            return Command::FAILURE;
        }
        $this->reencodeStaleMp3 = (bool)$input->getOption('reencode-stale-mp3');
        $this->reencodedStaleMp3 = [];

        // Formats: CLI → env → config; prompted when configured nowhere, every interactive run
        // (like input/output — stops firing once an answer is persisted to the config file)
        $formatsCli = $input->getOption('formats');
        $formatsEnv = getenv('FORMATS') ?: null;
        $formatsConfigured = $formatsCli !== null || $formatsEnv !== null || array_key_exists('formats', $config);
        $formatsRaw = $formatsCli
            ?? $formatsEnv
            ?? (isset($config['formats']) ? (string)$config['formats'] : null)
            ?? self::DEFAULT_FORMATS;

        if ($input->isInteractive() && !$formatsConfigured) {
            $formatsRaw = $this->askText(
                $input,
                $output,
                '<question>Output formats</question> (comma-separated: original, mp3, wav, flac)',
                $formatsRaw,
                static fn(?string $v): string => self::parseFormatsAnswer($v)
            );
            $this->updateConfig(['formats' => $formatsRaw]);
        }

        try {
            $formatsStr = self::parseFormatsAnswer($formatsRaw);
        } catch (RuntimeException $e) {
            $this->io->error("Invalid --formats / FORMATS value: {$e->getMessage()}");

            return Command::FAILURE;
        }

        // Cookies: one shared file for both tools by default (Netscape format holds multiple
        // domains — e.g. SoundCloud cookies for yt-dlp plus YT Music cookies for spotdl),
        // overridable per tool. A file is only passed on when it actually exists.
        $genericCookies = $this->resolveParam(
            $input->getOption('cookies'),
            'COOKIES_FILE',
            $config,
            'cookies_file',
            dirname(__DIR__, 2).'/config/cookies.txt'
        );
        $ytdlpCookies = $this->resolveParam(
            $input->getOption('ytdlp-cookies'),
            'YTDLP_COOKIE_FILE',
            $config,
            'ytdlp_cookie_file'
        ) ?? $genericCookies;
        $spotdlCookies = $this->resolveParam(
            $input->getOption('spotdl-cookies'),
            'SPOTDL_COOKIE_FILE',
            $config,
            'spotdl_cookie_file'
        ) ?? $genericCookies;
        $this->cookiesFile = is_file($ytdlpCookies) ? $ytdlpCookies : null;
        $this->spotdlCookieFile = is_file($spotdlCookies) ? $spotdlCookies : null;

        // B-category: from CLI option, env, or config (prompt once if not set)
        $inputFile = $this->resolveParam($input->getOption('input'), 'INPUT_FILE', $config, 'input_file');
        $baseOutDir = $this->resolveParam($input->getOption('out'), 'OUTPUT_DIR', $config, 'output_dir');

        if ($input->isInteractive()) {
            if (!$inputFile) {
                $savedInput = $config['input_file'] ?? 'config/playlists.txt';
                $inputFile = $this->askText($input, $output, 'Input file', $savedInput);
            }
            if (!$baseOutDir) {
                $savedOut = $config['output_dir'] ?? './downloads';
                $baseOutDir = $this->askText($input, $output, 'Output directory', $savedOut);
            }
            // reload fresh (not the stale $config captured at the top of execute()) so an
            // earlier prompt save in this same run — e.g. formats — isn't clobbered
            $this->updateConfig([
                'input_file' => $inputFile,
                'output_dir' => $baseOutDir,
            ]);
        } elseif (!$inputFile || !$baseOutDir) {
            $this->io->error('--input and --out are required in non-interactive mode.');

            return Command::FAILURE;
        }

        $this->extractorRetries = $this->resolveParam(
            $input->getOption('extractor-retries'),
            'EXTRACTOR_RETRIES',
            $config,
            'extractor_retries',
            '10'
        );
        $this->retrySleep = $this->resolveParam(
            $input->getOption('retry-sleep'),
            'RETRY_SLEEP',
            $config,
            'retry_sleep',
            'exp=2:10:120'
        );
        $this->sleepRequests = $this->resolveSleepRequests(
            $this->resolveParam($input->getOption('sleep-requests'), 'SLEEP_REQUESTS', $config, 'sleep_requests', '2')
        );
        $this->limitRate = $this->resolveParam($input->getOption('limit-rate'), 'LIMIT_RATE', $config, 'limit_rate');
        $this->pauseBetween = (int)$this->resolveParam(
            $input->getOption('pause-between'),
            'PAUSE_BETWEEN',
            $config,
            'pause_between',
            '2'
        );

        // Min ODG: CLI → env → config; prompted once (and persisted) when configured nowhere
        $minOdgCli = $input->getOption('min-odg');
        $minOdgEnv = getenv('MIN_ODG') ?: null;
        $minOdgConfigured = $minOdgCli !== null || $minOdgEnv !== null || array_key_exists('min_odg', $config);
        $minOdgRaw = $minOdgCli
            ?? $minOdgEnv
            ?? (isset($config['min_odg']) ? (string)$config['min_odg'] : null);

        if ($input->isInteractive() && !$minOdgConfigured) {
            $this->io->section('Audio quality threshold (estimated ODG)');
            $this->io->text([
                'Please specify the minimum source audio quality for the playlist sync.',
                'ODG: 0 = transparent … -4 = very annoying (PEAQ scale, estimated from codec + bitrate).',
                '',
                'Available options & use cases:',
            ]);
            foreach (self::MIN_ODG_TIERS as $num => $tier) {
                $this->io->text("  [$num] <info>{$tier['label']}</info> ({$tier['hint']})");
            }
            $this->io->newLine();
            $answer = $this->askText(
                $input,
                $output,
                '<question>Minimum source audio baseline</question>'
                .' (option 1-4, custom ODG value between -4 and 0, or empty = off)',
                null,
                static fn(?string $v): ?float => self::parseMinOdgAnswer($v)
            );
            $minOdgRaw = $answer !== null ? (string)$answer : null;
            $this->updateConfig(['min_odg' => $answer]);
        }

        if ($minOdgRaw !== null && (!is_numeric($minOdgRaw) || (float)$minOdgRaw < -4 || (float)$minOdgRaw > 0)) {
            $this->io->error(
                "Invalid --min-odg / MIN_ODG value: $minOdgRaw (expected a number between -4 and 0, ODG scale)"
            );

            return Command::FAILURE;
        }
        $this->minOdg = $minOdgRaw !== null ? (float)$minOdgRaw : null;

        // Min ODG mode: prompted every interactive run while a minimum is active;
        // a CLI option is an explicit answer and suppresses the prompt, env/config seed the default
        $minOdgModeCli = $input->getOption('min-odg-mode');
        $minOdgMode = $minOdgModeCli
            ?? (getenv('MIN_ODG_MODE') ?: null)
            ?? ($config['min_odg_mode'] ?? null)
            ?? 'warn';
        if ($input->isInteractive() && $minOdgModeCli === null && $this->minOdg !== null) {
            $minOdgMode = $this->askChoice(
                $input,
                $output,
                'Low-quality handling (warn = list in summary, filter = exclude)',
                ['warn', 'filter'],
                in_array($minOdgMode, ['warn', 'filter'], true) ? $minOdgMode : 'warn'
            );
            $this->updateConfig(['min_odg_mode' => $minOdgMode]);
        } elseif (!in_array($minOdgMode, ['warn', 'filter'], true)) {
            $this->io->error(
                "Invalid --min-odg-mode / MIN_ODG_MODE value: $minOdgMode (expected \"warn\" or \"filter\")"
            );

            return Command::FAILURE;
        }
        $this->minOdgMode = $minOdgMode;

        $libFilenameTemplate = $this->resolveParam(
            $input->getOption('lib-filename-template'),
            'LIB_FILENAME_TEMPLATE',
            $config,
            'lib_filename_template',
            '%(id)s - %(title)s'
        );

        if (!is_file($inputFile)) {
            $this->io->error("Input file not found: $inputFile");

            return Command::FAILURE;
        }
        if (!$this->ensureDirectory($baseOutDir, 0777)) {
            $this->io->error("Failed to create output directory: $baseOutDir");

            return Command::FAILURE;
        }

        $libraryDir = $this->resolveParam(
            $input->getOption('library-dir'),
            'LIBRARY_DIR',
            $config,
            'library_dir',
            $baseOutDir.DIRECTORY_SEPARATOR.'library'
        );
        $archiveDir = $this->resolveParam(
            $input->getOption('archive-dir'),
            'ARCHIVE_DIR',
            $config,
            'archive_dir',
            $baseOutDir.DIRECTORY_SEPARATOR.'.archive'
        );
        $playlistsDir = $this->resolveParam(
            $input->getOption('playlists-dir'),
            'PLAYLISTS_DIR',
            $config,
            'playlists_dir',
            $baseOutDir.DIRECTORY_SEPARATOR.'playlists'
        );

        foreach ([$libraryDir, $archiveDir] as $dir) {
            if (!$this->ensureDirectory($dir, 0777)) {
                $this->io->error("Failed to create directory: $dir");

                return Command::FAILURE;
            }
        }
        $originalLibDir = $libraryDir.DIRECTORY_SEPARATOR.'original';
        if (!$this->ensureDirectory($originalLibDir, 0777)) {
            $this->io->error("Failed to create directory: $originalLibDir");

            return Command::FAILURE;
        }
        if ($playlistsDir && !$this->ensureDirectory($playlistsDir, 0777)) {
            $this->io->error("Failed to create playlists directory: $playlistsDir");

            return Command::FAILURE;
        }

        $urls = array_values(
            array_filter(
                array_map('trim', file($inputFile)),
                static fn($l) => $l !== '' && $l[0] !== '#'
            )
        );
        if (!$urls) {
            $this->io->error("No URLs found in $inputFile");

            return Command::FAILURE;
        }

        $sources = array_map([self::class, 'classifySourceUrl'], $urls);

        try {
            if (in_array(self::SOURCE_YTDLP, $sources, true)) {
                $this->requireBinary($this->ytDlpBin, '--version');
            }
            if (in_array(self::SOURCE_SPOTIFY, $sources, true)) {
                $this->requireBinary($this->spotdlBin, '--version');
            }
            $this->requireBinary($this->ffmpegBin, '-version');
        } catch (RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        $formatsRequested = array_values(array_filter(array_map('trim', explode(',', $formatsStr))));

        $formatDirs = [
            'mp3' => $libraryDir.DIRECTORY_SEPARATOR.'mp3',
            'wav' => $libraryDir.DIRECTORY_SEPARATOR.'wav',
            'flac' => $libraryDir.DIRECTORY_SEPARATOR.'flac',
        ];

        $this->io->text('Fetching playlist metadata...');

        $failed = [];
        $lowQualityTracks = []; // track id => warn line; one entry per unique library file
        $qualityCache = []; // srcPath => [?float abr, ?string codec, ?float odg]
        $filteredTotal = 0;
        $spotifyFilterNoteShown = false;

        $fetchBar = new ProgressBar(self::barOutput($output), count($urls));
        $fetchBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $fetchBar->setMessage('');
        $fetchBar->start();

        $playlists = [];
        foreach ($urls as $url) {
            $fetchBar->setMessage(parse_url($url, PHP_URL_PATH) ?? $url);
            $source = self::classifySourceUrl($url);
            try {
                [$plTitle, , $plUploader, $plEntries, $plSkipped] = $source === self::SOURCE_SPOTIFY
                    ? $this->getSpotifyPlaylistIdentityAndEntries($url, $archiveDir)
                    : $this->getPlaylistIdentityAndEntries($url);
                $playlists[] = [
                    'url' => $url,
                    'source' => $source,
                    'folder' => self::safeName(sprintf('%s - %s', $plUploader, $plTitle)),
                    'entries' => $plEntries,
                    'skipped' => $plSkipped,
                ];
            } catch (RuntimeException $e) {
                $this->io->error($e->getMessage());
                $failed[] = "$url — failed to fetch playlist info";
            }
            $fetchBar->advance();
        }

        $fetchBar->finish();
        $output->writeln('');

        $totalTracks = array_sum(array_map(static fn(array $p) => count($p['entries']), $playlists));
        $this->io->text(
            sprintf(
                'Found %d playlists, %d tracks total. Starting downloads...',
                count($playlists),
                $totalTracks
            )
        );

        // max(1, ...): the %remaining% placeholder throws when max is 0, which happens when
        // every playlist fetch failed or all playlists are empty
        $overallBar = new ProgressBar(self::barOutput($output), max(1, $totalTracks * 2));
        $overallBar->setFormat(' Overall: %percent:3s%% [%bar%] remaining: %remaining% %message%');
        $overallBar->setMessage('');
        $overallBar->start();
        $output->writeln('');

        foreach ($playlists as $playlistIdx => $playlist) {
            $url = $playlist['url'];
            $plFolder = $playlist['folder'];
            $plEntries = $playlist['entries'];

            $this->io->section(sprintf('[%d/%d] %s', $playlistIdx + 1, count($playlists), $url));
            $this->io->text("Playlist: $plFolder");

            $tool = $playlist['source'] === self::SOURCE_SPOTIFY ? 'spotdl' : 'yt-dlp';
            if (
                !$spotifyFilterNoteShown
                && $this->minOdg !== null
                && $this->minOdgMode === 'filter'
                && $playlist['source'] === self::SOURCE_SPOTIFY
            ) {
                $this->io->text(
                    'Note: the quality filter cannot skip Spotify downloads (spotdl); '
                    .'tracks below the threshold are excluded after download.'
                );
                $spotifyFilterNoteShown = true;
            }
            $this->io->text('Downloading originals...');
            $overallBar->setMessage('downloading...');
            [
                $newCount,
                $archivedCount,
                $exit,
                $failedEntries,
                $filteredEntries,
            ] = $playlist['source'] === self::SOURCE_SPOTIFY
                ? $this->downloadSpotifyOriginals($url, $originalLibDir, $archiveDir, $overallBar, $plEntries)
                : $this->downloadYtDlpOriginals(
                    $url,
                    $originalLibDir,
                    $libFilenameTemplate,
                    $archiveDir,
                    $overallBar,
                    $plEntries
                );
            $overallBar->setMessage('');
            $failedCount = max(0, count($plEntries) - $newCount - $archivedCount - count($filteredEntries));
            $summary = sprintf('Download: %d new, %d already in archive', $newCount, $archivedCount);
            if ($failedCount > 0) {
                $summary .= sprintf(', %d failed', $failedCount);
            }
            $this->io->text($summary);
            if ($failedEntries) {
                $this->io->text('Failed tracks:');
                foreach ($failedEntries as $entry) {
                    $this->io->text(sprintf('  - %s (%s)', $entry['title'], $entry['id']));
                }
            }
            if ($filteredEntries) {
                $filteredTotal += count($filteredEntries);
                $this->io->text(sprintf('Skipped (below est. ODG %s):', self::formatOdg($this->minOdg)));
                foreach ($filteredEntries as $entry) {
                    $this->io->text(sprintf('  - %s (%s)', $entry['title'], $entry['id']));
                }
            }
            if ($playlist['skipped']) {
                $this->io->text('Skipped playlists (not downloaded):');
                foreach ($playlist['skipped'] as $entry) {
                    $this->io->text(sprintf('  - %s (%s)', $entry['title'], $entry['id']));
                }
            }
            if ($exit !== 0 && $failedEntries === [] && $filteredEntries !== []) {
                // yt-dlp exits nonzero when any entry errors; with only filter-skips that's expected.
                $this->io->text("$tool exited with code $exit (tracks below the quality threshold); continuing.");
            } elseif ($exit !== 0) {
                $this->io->warning("$tool exited with code $exit for originals; continuing.");
                $failed[] = "$plFolder — $tool exit code $exit";
            }

            foreach ($formatDirs as $d) {
                if (!$this->ensureDirectory($d, 0777)) {
                    $this->io->error("Failed to create directory: $d");
                    $failed[] = "$plFolder — failed to create directory $d";
                    $overallBar->advance(count($plEntries));
                    continue 2;
                }
            }

            $m3uEntries = ['original' => [], 'mp3' => [], 'wav' => [], 'flac' => []];

            $metaExts = ['json', 'jpg', 'jpeg', 'png', 'webp', 'vtt'];

            $convBar = null;
            if ($plEntries !== []) {
                $convBar = new ProgressBar(self::barOutput($output), count($plEntries));
                $convBar->setFormat(
                    ' Tracks:    %current%/%max% [%bar%] %percent:3s%% remaining: %remaining% %message%'
                );
                $convBar->setMessage('');
                $convBar->start();
            }

            foreach ($plEntries as $entry) {
                $overallBar->advance();
                if ($convBar !== null) {
                    $convBar->setMessage($entry['title'] ?? '');
                    $convBar->advance();
                }
                $all = glob($originalLibDir.DIRECTORY_SEPARATOR.$entry['id'].' - *.*', GLOB_NOSORT) ?: [];
                $matches = array_values(
                    array_filter(
                        $all,
                        static fn(string $f) => !in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $metaExts, true)
                    )
                );
                if (!$matches || !is_file($matches[0])) {
                    continue;
                }
                $srcPath = str_replace('\\', '/', (string)(realpath($matches[0]) ?: $matches[0]));
                $infoJson = preg_replace('/\.\w+$/', '.info.json', $srcPath);
                $coverJpg = preg_replace('/\.\w+$/', '.jpg', $srcPath);
                $info = is_file($infoJson) ? json_decode((string)file_get_contents($infoJson), true) : null;
                $tags = is_array($info) ? self::mapInfoJsonToTags($info) : [];
                if ($this->minOdg !== null) {
                    // a track can appear in many playlists — evaluate its library file once
                    if (!array_key_exists($srcPath, $qualityCache)) {
                        $abr = is_array($info) ? self::audioBitrateKbps($info) : null;
                        $codec = is_array($info) ? self::audioCodec($info) : null;
                        if ($abr === null) {
                            [$abr, $probedCodec] = $this->probeAudioProperties($srcPath);
                            $codec ??= $probedCodec;
                        }
                        $qualityCache[$srcPath] = [
                            $abr,
                            $codec,
                            $abr !== null ? self::estimateOdg($codec, $abr) : null,
                        ];
                    }
                    [$abr, $codec, $odg] = $qualityCache[$srcPath];
                    if ($odg !== null && $odg < $this->minOdg) {
                        if (!isset($lowQualityTracks[$entry['id']])) {
                            // set-playlist entries often carry no title; sidecar-less files
                            // (pre-info.json downloads) fall back to the library filename
                            $title = trim((string)($entry['title'] ?? ''));
                            if ($title === '') {
                                $title = trim((string)($tags['title'] ?? ''));
                            }
                            if ($title === '') {
                                $title = self::titleFromFilename($srcPath, (string)$entry['id']);
                            }
                            $lowQualityTracks[$entry['id']] = sprintf(
                                '%s (%s): %s kbps %s, est. ODG %s',
                                $title,
                                $entry['id'],
                                self::formatKbps($abr),
                                self::normalizeCodec($codec),
                                self::formatOdg($odg)
                            );
                        }
                        if ($this->minOdgMode === 'filter') {
                            // keep low-quality originals (e.g. downloaded before the threshold
                            // existed) out of conversions and playlist files; the file stays on disk
                            continue;
                        }
                    }
                }

                if (in_array('original', $formatsRequested, true)) {
                    $m3uEntries['original'][] = $srcPath;
                }

                foreach (['mp3', 'wav', 'flac'] as $fmt) {
                    if (!in_array($fmt, $formatsRequested, true)) {
                        continue;
                    }
                    $ext = $fmt;
                    $target = $formatDirs[$fmt].DIRECTORY_SEPARATOR.pathinfo($srcPath, PATHINFO_FILENAME).".$ext";
                    $cover = ($fmt !== 'wav' && is_file($coverJpg)) ? $coverJpg : null;
                    $made = $this->ensureConverted($srcPath, $target, $fmt, $tags, $cover);
                    if ($made) {
                        $m3uEntries[$fmt][] = str_replace('\\', '/', realpath($made) ?: $made);
                    }
                }
            }

            if ($convBar !== null) {
                $convBar->finish();
                $output->writeln('');
            }

            foreach (['original', 'mp3', 'wav', 'flac'] as $fmt) {
                if (!in_array($fmt, $formatsRequested, true)) {
                    continue;
                }
                $list = $m3uEntries[$fmt];
                natsort($list);
                $list = array_values($list);
                $m3uPath = rtrim($playlistsDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR."$plFolder - $fmt.m3u8";
                $m3u = "#EXTM3U\n";
                foreach ($list as $abs) {
                    $m3u .= str_replace('\\', '/', self::relativePath($playlistsDir, $abs))."\n";
                }
                file_put_contents($m3uPath, $m3u);
                $this->io->text("Wrote playlist: $m3uPath (".count($list).' entries)');
            }

            $this->io->text("Completed: $plFolder");
            if ($this->pauseBetween > 0) {
                sleep($this->pauseBetween);
            }
        }

        $overallBar->finish();
        $output->writeln('');

        if ($failed !== []) {
            $this->io->warning(array_merge(['The following playlists had errors:'], $failed));
        }

        if ($this->reencodedStaleMp3 !== []) {
            $this->io->warning(
                array_merge(
                    [
                        sprintf(
                            '%d stale mp3(s) re-encoded (bitrate mismatch, likely pre-CBR VBR):',
                            count($this->reencodedStaleMp3)
                        ),
                    ],
                    $this->reencodedStaleMp3
                )
            );
        }

        if ($lowQualityTracks !== []) {
            $this->io->warning(
                array_merge(
                    [
                        sprintf(
                            '%d track(s) below est. ODG %s in the library%s:',
                            count($lowQualityTracks),
                            self::formatOdg($this->minOdg),
                            $this->minOdgMode === 'filter' ? ' (excluded from playlists)' : ''
                        ),
                    ],
                    $lowQualityTracks
                )
            );
        }
        if ($filteredTotal > 0) {
            $this->io->text(
                sprintf(
                    'Skipped %d track(s) below est. ODG %s (quality filter).',
                    $filteredTotal,
                    self::formatOdg($this->minOdg)
                )
            );
        }

        $this->io->success('All done.');

        return Command::SUCCESS;
    }

    protected function loadConfig(): array
    {
        if (!is_file($this->getConfigPath())) {
            $legacy = $this->getLegacyConfigPath();
            if ($legacy !== null && is_file($legacy)) {
                return $this->loadConfigFrom($legacy);
            }
        }

        return parent::loadConfig();
    }

    protected function getConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/playlists-sync.json';
    }

    /** Pre-rename config location (soundcloud:download) — read once as fallback, never written. */
    protected function getLegacyConfigPath(): ?string
    {
        return dirname(__DIR__, 2).'/config/soundcloud-download.json';
    }

    /**
     * Parses a comma-separated formats answer/value against VALID_FORMATS: trims each entry,
     * drops empties, dedups, and rejects unknown names. Throws on an empty or invalid list so
     * QuestionHelper re-asks (and non-interactive callers get a clear error). Pure — unit-testable.
     */
    public static function parseFormatsAnswer(?string $answer): string
    {
        $entries = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$answer)))));
        if ($entries === []) {
            throw new RuntimeException(
                'Expected a comma-separated list of formats ('.implode(', ', self::VALID_FORMATS)."), got: $answer"
            );
        }
        $unknown = array_diff($entries, self::VALID_FORMATS);
        if ($unknown !== []) {
            throw new RuntimeException(
                'Unknown format(s): '.implode(', ', $unknown).' (expected: '.implode(', ', self::VALID_FORMATS).')'
            );
        }

        return implode(',', $entries);
    }

    private function resolveSleepRequests(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[-:]\s*(\d+(?:\.\d+)?)\s*$/', $raw, $m)) {
            $min = (float)$m[1];
            $max = (float)$m[2];
            if ($max < $min) {
                [$min, $max] = [$max, $min];
            }

            return sprintf('%.3f', $min + (mt_rand() / mt_getrandmax()) * max(0.0, $max - $min));
        }

        return is_numeric($raw) ? (string)(float)$raw : '2';
    }

    /**
     * Parses an answer to the guided min-odg prompt: a MIN_ODG_TIERS option number (1-4), a
     * custom ODG value in [-4, 0], or empty/'-'/'off' for off (null). Positive kbps-style
     * numbers are rejected to avoid silent unit ambiguity. Throws on anything else so
     * QuestionHelper re-asks. Pure — unit-testable.
     */
    public static function parseMinOdgAnswer(?string $answer): ?float
    {
        $answer = trim(str_replace("\u{2212}", '-', (string)$answer));
        if ($answer === '' || $answer === '-' || strcasecmp($answer, 'off') === 0) {
            return null;
        }
        if (preg_match('/^[1-4]$/', $answer) === 1) {
            return self::MIN_ODG_TIERS[(int)$answer]['odg'];
        }
        if (!is_numeric($answer) || (float)$answer < -4 || (float)$answer > 0) {
            throw new RuntimeException(
                "Expected an option (1-4), an ODG value between -4 and 0 (e.g. -1.5), or empty for off, got: $answer"
            );
        }

        return (float)$answer;
    }

    private function requireBinary(string $bin, ?string $versionArg = null): void
    {
        $cmd = escapeshellcmd($bin).($versionArg ? ' '.$versionArg : '');
        [$exit] = $this->runCmd($cmd.' 2>&1', static fn() => null);
        if ($exit !== 0) {
            throw new RuntimeException("Missing dependency: $bin. Please install it and ensure it's in PATH.");
        }
    }

    private function runCmd(string $cmd, ?callable $onLine = null, ?callable $onErrLine = null): array
    {
        return $this->runner->run(
            $cmd,
            function (string $chunk) use ($onLine): void {
                foreach (preg_split('/\R/u', $chunk) as $line) {
                    if ($line !== '') {
                        $onLine ? $onLine($line) : $this->io->text($line);
                    }
                }
            },
            function (string $chunk) use ($onErrLine): void {
                foreach (preg_split('/\R/u', $chunk) as $line) {
                    if ($line !== '') {
                        if ($onErrLine !== null) {
                            $onErrLine($line);
                        }
                        $this->io->getErrorStyle()->text($line);
                    }
                }
            },
            true,
        );
    }

    /**
     * ProgressBar silently redirects to $output->getErrorOutput() for any ConsoleOutputInterface
     * (Symfony's built-in behaviour, so piping a command's real stdout output stays clean of
     * progress noise). On a real terminal stdout/stderr share one tty so this is invisible, but
     * under `docker exec`/`ddev exec` (no pty allocated) they're two independently-buffered
     * pipes — merging them for display desyncs the visual order. Forcing the bar onto the same
     * stream as the rest of this command's output avoids that split entirely. Only applies to a
     * real ConsoleOutputInterface: CommandTester/BufferedOutput (used in tests) aren't one, so
     * ProgressBar already writes straight to them with no redirect — left untouched.
     */
    private static function barOutput(OutputInterface $output): OutputInterface
    {
        if (!$output instanceof ConsoleOutputInterface) {
            return $output;
        }

        return new StreamOutput(
            fopen('php://stdout', 'wb'),
            $output->getVerbosity(),
            $output->isDecorated(),
            $output->getFormatter(),
        );
    }

    /**
     * Routes an input-file line to its downloader. Everything not recognizably Spotify goes to
     * yt-dlp (SoundCloud, YouTube, ...). Pure — unit-testable.
     */
    public static function classifySourceUrl(string $url): string
    {
        $webPattern = '#^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?'
            .'(?:playlist|album|track)/[A-Za-z0-9]+#i';
        if (
            preg_match($webPattern, $url) === 1
            || preg_match('#^spotify:(?:playlist|album|track):[A-Za-z0-9]+$#i', $url) === 1
        ) {
            return self::SOURCE_SPOTIFY;
        }

        return self::SOURCE_YTDLP;
    }

    /**
     * Spotify pendant to getPlaylistIdentityAndEntries(): `spotdl save` writes the playlist
     * metadata to a JSON file, which is then mapped onto the same identity tuple.
     *
     * @return array{string, string, string, list<array{id: string, title: string}>, list<array{id: string, title: string}>}
     */
    private function getSpotifyPlaylistIdentityAndEntries(string $url, string $archiveDir): array
    {
        $saveFile = self::spotdlSaveFilePath($archiveDir, $url);
        $cmd = implode(' ', [
            escapeshellcmd($this->spotdlBin),
            escapeshellarg('save'),
            escapeshellarg($url),
            escapeshellarg('--save-file'),
            escapeshellarg($saveFile),
        ]);
        [$exit] = $this->runCmd($cmd, fn() => null);
        $songs = is_file($saveFile) ? json_decode((string)file_get_contents($saveFile), true) : null;
        if ($exit !== 0 || !is_array($songs)) {
            throw new RuntimeException("Failed to query Spotify playlist info for URL: $url");
        }

        return self::parseSpotdlSaveData($songs, $url);
    }

    /**
     * Deterministic per-URL save-file location ('.spotdl' suffix is required by spotdl).
     * Kept after parsing — overwritten on the next run, useful as a debug artifact.
     */
    private static function spotdlSaveFilePath(string $archiveDir, string $url): string
    {
        return $archiveDir.DIRECTORY_SEPARATOR.'spotify-save-'.md5($url).'.spotdl';
    }

    /**
     * Maps decoded .spotdl save data (JSON array of Song dicts written by `spotdl save`) to the
     * [title, id, uploader, entries, skippedPlaylists] tuple getPlaylistIdentityAndEntries()
     * yields for yt-dlp sources. The save file carries no playlist-owner field, so the uploader
     * is always 'Spotify'; it also never contains nested playlists, so skippedPlaylists is
     * always empty. Pure — unit-testable.
     *
     * @param list<array<string, mixed>> $songs
     * @return array{string, string, string, list<array{id: string, title: string}>, list<array{id: string, title: string}>}
     */
    private static function parseSpotdlSaveData(array $songs, string $url): array
    {
        if ($songs === []) {
            throw new RuntimeException("Failed to query Spotify playlist info for URL: $url");
        }

        $first = $songs[0];
        // single-track URLs have list_name: null — fall back to the track name
        $title = (string)($first['list_name'] ?? '') ?: (string)($first['name'] ?? '') ?: 'Playlist';
        $id = self::spotifyIdFromUrl($url) ?: md5($url);

        $entries = [];
        foreach ($songs as $song) {
            $songId = (string)($song['song_id'] ?? '') ?: self::spotifyIdFromUrl((string)($song['url'] ?? ''));
            if ($songId === '') {
                continue;
            }
            $entries[] = ['id' => $songId, 'title' => (string)($song['name'] ?? '')];
        }

        return [$title, $id, 'Spotify', $entries, []];
    }

    /**
     * Last path segment of a Spotify URL (query stripped) or the last colon part of a
     * spotify:...:ID URI — the track/playlist ID. Pure — unit-testable.
     */
    private static function spotifyIdFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $tail = is_string($path) ? basename($path) : '';
        if ($tail === '' || str_contains($tail, ':')) {
            $parts = explode(':', rtrim($url, ':'));
            $tail = (string)end($parts);
        }

        return $tail;
    }

    private function getPlaylistIdentityAndEntries(string $url): array
    {
        $args = [
            '-J',
            '--flat-playlist',
            '--extractor-retries',
            $this->extractorRetries,
            '--retry-sleep',
            $this->retrySleep,
            '--sleep-requests',
            $this->sleepRequests,
        ];
        if ($this->limitRate) {
            $args[] = '--limit-rate';
            $args[] = $this->limitRate;
        }
        if ($this->cookiesFile) {
            $args[] = '--cookies';
            $args[] = $this->cookiesFile;
        }
        $args[] = $url;

        $cmd = escapeshellcmd($this->ytDlpBin).' '.implode(' ', array_map('escapeshellarg', $args));
        [$code, $out] = $this->runCmd($cmd, fn() => null);
        if ($code !== 0) {
            throw new RuntimeException("Failed to query playlist info for URL: $url");
        }
        $json = json_decode($out, true);
        if (!is_array($json)) {
            throw new RuntimeException("Invalid JSON from yt-dlp for URL: $url");
        }

        $title = $json['title'] ?? 'Playlist';
        $id = $json['id'] ?? md5($url);
        $uploader = $json['uploader'] ?? ($json['channel'] ?? 'SoundCloud');
        $entries = [];
        $skippedPlaylists = [];
        foreach ($json['entries'] ?? [] as $e) {
            $tid = (string)($e['id'] ?? '');
            if ($tid === '') {
                continue;
            }
            if (self::isNestedPlaylistEntry($e)) {
                $skippedPlaylists[] = ['id' => $tid, 'title' => (string)($e['title'] ?? '')];
                continue;
            }
            $entries[] = ['id' => $tid, 'title' => (string)($e['title'] ?? '')];
        }

        return [$title, $id, $uploader, $entries, $skippedPlaylists];
    }

    /**
     * Whether a flat-playlist entry is itself a playlist (e.g. a liked set inside SoundCloud
     * likes). Such entries are never downloaded recursively and must not count as tracks.
     * SoundCloud liked sets arrive as _type "url" without ie_key — only the /sets/ URL gives
     * them away. Pure — unit-testable.
     *
     * @param array<string, mixed> $e
     */
    private static function isNestedPlaylistEntry(array $e): bool
    {
        if (($e['_type'] ?? '') === 'playlist') {
            return true;
        }
        $ieKey = (string)($e['ie_key'] ?? ($e['extractor_key'] ?? ''));
        if (in_array($ieKey, ['SoundcloudSet', 'SoundcloudPlaylist', 'YoutubeTab', 'YoutubePlaylist'], true)) {
            return true;
        }

        return (bool)preg_match('~soundcloud\.com/[^/]+/sets/~', (string)($e['url'] ?? ''));
    }

    private static function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim((string)$name);
    }

    /**
     * Runs spotdl for one playlist URL. Progress counts come from diffing the archive file
     * before/after — spotdl's stdout is not machine-readable, and it archives each song's URL
     * on success (spotdl exits 0 even when individual songs fail to match, so failed entries
     * are those still missing from the archive after the run).
     *
     * @param list<array{id: string, title: string}> $plEntries
     * @return array{int, int, int, list<array{id: string, title: string}>, list<array{id: string, title: string}>}
     *         [newCount, archivedCount, exitCode, failedEntries, filteredEntries]
     */
    private function downloadSpotifyOriginals(
        string $url,
        string $originalLibDir,
        string $archiveDir,
        ProgressBar $overallBar,
        array $plEntries
    ): array {
        $archiveFile = $archiveDir.DIRECTORY_SEPARATOR.'spotify.txt';
        $pre = self::loadSpotdlArchiveIds($archiveFile);
        $cmd = self::buildSpotdlDownloadCmd(
            $this->spotdlBin,
            $url,
            $originalLibDir,
            $archiveFile,
            $this->spotdlCookieFile
        );
        [$exit] = $this->runCmd(implode(' ', $cmd), fn() => null);
        $post = self::loadSpotdlArchiveIds($archiveFile);
        $overallBar->advance(count($plEntries));

        return [
            max(0, count($post) - count($pre)),
            self::countArchived($plEntries, $pre),
            $exit,
            self::missingEntries($plEntries, $post),
            // spotdl has no per-format bitrate filter; the quality filter never applies here.
            [],
        ];
    }

    /**
     * Parse a spotdl --archive file (one song URL per line) into a set of Spotify track IDs.
     *
     * @return array<string, true>
     */
    private static function loadSpotdlArchiveIds(string $archiveFile): array
    {
        if (!is_file($archiveFile)) {
            return [];
        }
        $ids = [];
        foreach (preg_split('/\R/', (string)file_get_contents($archiveFile)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $id = self::spotifyIdFromUrl($line);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * Builds the spotdl download command. The output template is fixed to '{track-id} - {title}'
     * so the conversion loop's `{id} - *.*` glob finds the files (LIB_FILENAME_TEMPLATE applies
     * to yt-dlp sources only); m4a + '--bitrate disable' yields the best quality spotdl offers
     * (256k with YT Music Premium cookies). Pure — unit-testable.
     *
     * @return list<string> fully escaped command parts
     */
    private static function buildSpotdlDownloadCmd(
        string $spotdlBin,
        string $url,
        string $originalLibDir,
        string $archiveFile,
        ?string $cookieFile
    ): array {
        $cmd = [
            escapeshellcmd($spotdlBin),
            escapeshellarg('download'),
            escapeshellarg($url),
            escapeshellarg('--output'),
            escapeshellarg($originalLibDir.'/{track-id} - {title}.{output-ext}'),
            escapeshellarg('--format'),
            escapeshellarg('m4a'),
            escapeshellarg('--bitrate'),
            escapeshellarg('disable'),
            escapeshellarg('--archive'),
            escapeshellarg($archiveFile),
        ];
        if ($cookieFile !== null) {
            $cmd[] = escapeshellarg('--cookie-file');
            $cmd[] = escapeshellarg($cookieFile);
        }

        return $cmd;
    }

    /**
     * Count how many playlist entries are already present in the archive id set.
     *
     * @param list<array{id: string, title: string}> $entries
     * @param array<string, true> $archivedIds
     */
    private static function countArchived(array $entries, array $archivedIds): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            if (isset($archivedIds[$entry['id']])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Playlist entries whose id is not in the given id set — i.e. tracks that were neither
     * archived before the run nor downloaded during it, which means they failed.
     *
     * @param list<array{id: string, title: string}> $entries
     * @param array<string, true> $okIds
     * @return list<array{id: string, title: string}>
     */
    private static function missingEntries(array $entries, array $okIds): array
    {
        return array_values(
            array_filter(
                $entries,
                static fn(array $entry): bool => !isset($okIds[$entry['id']])
            )
        );
    }

    /**
     * Runs yt-dlp for one playlist URL (SoundCloud/YouTube/...). New downloads are counted via
     * the DONE: --print hook; already-archived entries emit no output at all, so they are
     * counted by snapshotting the archive before the run. Failed entries are those neither
     * present in the pre-run archive nor confirmed via a DONE: line.
     *
     * @param list<array{id: string, title: string}> $plEntries
     * @return array{int, int, int, list<array{id: string, title: string}>}
     *         [newCount, archivedCount, exitCode, failedEntries]
     */
    private function downloadYtDlpOriginals(
        string $url,
        string $originalLibDir,
        string $libFilenameTemplate,
        string $archiveDir,
        ProgressBar $overallBar,
        array $plEntries
    ): array {
        $commonArgs = [
            '--no-overwrites',
            '--continue',
            '--ignore-errors',
            '--no-abort-on-error',
            '--yes-playlist',
            // NOTE: no --add-metadata here. It makes yt-dlp remux the original to
            // write tags, which fails ("Conversion failed!") for WAV/AIFF sources
            // that carry an embedded cover image (the WAV muxer rejects the video
            // stream), leaving those tracks unarchived and unconverted. Metadata and
            // cover art are re-embedded per format by ensureConverted() from the
            // .info.json / .jpg sidecars, so this is redundant anyway.
            '--extractor-retries',
            $this->extractorRetries,
            '--retry-sleep',
            $this->retrySleep,
            '--sleep-requests',
            $this->sleepRequests,
            '--write-info-json',
            '--write-thumbnail',
            '--convert-thumbnails',
            'jpg',
        ];
        if ($this->limitRate) {
            $commonArgs[] = '--limit-rate';
            $commonArgs[] = $this->limitRate;
        }

        $archiveFile = $archiveDir.DIRECTORY_SEPARATOR.'original.txt';
        $originalOutTpl = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            $originalLibDir.DIRECTORY_SEPARATOR.$libFilenameTemplate.'.%(ext)s'
        );

        $filterOdg = $this->minOdgMode === 'filter' ? $this->minOdg : null;
        $dlArgs = ['-f', self::buildFormatSelector($filterOdg)];
        if ($this->cookiesFile) {
            $dlArgs[] = '--cookies';
            $dlArgs[] = $this->cookiesFile;
        }

        $ytCmd = [
            escapeshellcmd($this->ytDlpBin),
            ...array_map('escapeshellarg', $commonArgs),
            ...array_map('escapeshellarg', ['--download-archive', $archiveFile]),
            ...array_map('escapeshellarg', ['--output', $originalOutTpl]),
            ...array_map('escapeshellarg', $dlArgs),
            '--print',
            escapeshellarg('SEEN:%(title)s'),
            '--print',
            escapeshellarg('after_move:DONE:%(id)s'),
            escapeshellarg($url),
        ];

        // yt-dlp filters already-archived playlist entries during enumeration,
        // before any --print stage fires, so they emit no output at all. Snapshot
        // the archive before downloading and diff against it to count skips.
        $preArchivedIds = self::loadArchiveIds($archiveFile);

        $newCount = 0;
        $doneIds = [];
        $filteredIds = [];
        [$exit] = $this->runCmd(
            implode(' ', $ytCmd),
            function (string $line) use ($overallBar, &$newCount, &$doneIds): void {
                if (str_starts_with($line, 'SEEN:')) {
                    $overallBar->setMessage(substr($line, 5));
                    $overallBar->advance();
                } elseif (str_starts_with($line, 'DONE:')) {
                    $newCount++;
                    $doneIds[substr($line, 5)] = true;
                }
            },
            $filterOdg === null ? null : static function (string $line) use (&$filteredIds): void {
                $id = self::matchFormatUnavailableId($line);
                if ($id !== null) {
                    $filteredIds[$id] = true;
                }
            }
        );

        return [
            $newCount,
            self::countArchived($plEntries, $preArchivedIds),
            $exit,
            self::missingEntries($plEntries, $preArchivedIds + $doneIds + $filteredIds),
            array_values(array_filter($plEntries, static fn(array $e) => isset($filteredIds[$e['id']]))),
        ];
    }

    /**
     * yt-dlp format selector, optionally constrained to a minimum estimated ODG. yt-dlp
     * cannot compute ODG, so the threshold is inverted per codec into a minimum bitrate
     * (via minKbpsForOdg) and expressed as one branch per codec prefix; lossless codecs
     * always pass. Uses fail-closed filters ([abr>=X], not [abr>=?X]) and codec prefixes:
     * formats with unknown bitrate or codec are excluded, so nothing silently falls
     * through the threshold. Pure — unit-testable.
     */
    private static function buildFormatSelector(?float $minOdg): string
    {
        if ($minOdg === null) {
            return 'bestaudio/best';
        }
        // best-codec-first: yt-dlp picks the first branch with a matching format
        $codecPrefixes = ['opus' => ['opus'], 'aac' => ['mp4a', 'aac'], 'vorbis' => ['vorbis'], 'mp3' => ['mp3']];
        $thresholds = [];
        foreach ($codecPrefixes as $codec => $prefixes) {
            $kbps = self::minKbpsForOdg($codec, $minOdg);
            if ($kbps === null) {
                continue; // codec cannot reach the threshold at any bitrate
            }
            foreach ($prefixes as $prefix) {
                $thresholds[$prefix] = self::formatKbps($kbps);
            }
        }
        $branches = [];
        foreach (['bestaudio', 'best'] as $base) {
            foreach (['flac', 'alac', 'pcm'] as $lossless) {
                $branches[] = "{$base}[acodec^={$lossless}]";
            }
            foreach (['abr', 'tbr'] as $key) {
                foreach ($thresholds as $prefix => $t) {
                    $branches[] = "{$base}[acodec^={$prefix}][{$key}>={$t}]";
                }
            }
        }

        return implode('/', $branches);
    }

    /**
     * Inverse of estimateOdg for one codec family: the lowest bitrate whose estimated ODG
     * reaches the threshold, rounded up to 0.1 kbps so rounding never admits worse quality.
     * Null when the codec cannot reach the threshold at any bitrate (fail-closed: its
     * branch is omitted from the format selector). Pure — unit-testable.
     */
    public static function minKbpsForOdg(string $codec, float $minOdg): ?float
    {
        $anchors = self::ODG_CALIBRATION[$codec] ?? null;
        if ($anchors === null) {
            return null;
        }
        if ($minOdg <= -4.0) {
            return 0.0;
        }
        $points = [[0.0, -4.0], ...$anchors];
        if ($minOdg > $points[count($points) - 1][1]) {
            return null;
        }
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            [$k0, $o0] = $points[$i - 1];
            [$k1, $o1] = $points[$i];
            if ($minOdg <= $o1) {
                $kbps = $o1 === $o0 ? $k0 : $k0 + ($k1 - $k0) * (($minOdg - $o0) / ($o1 - $o0));

                return ceil($kbps * 10) / 10;
            }
        }

        return null;
    }

    /** Kbps value for display / format selectors: "128" not "128.0". Pure — unit-testable. */
    private static function formatKbps(float $kbps): string
    {
        return rtrim(rtrim(sprintf('%.1f', $kbps), '0'), '.');
    }

    /**
     * Parse a yt-dlp --download-archive file into a set of recorded ids.
     * Each line looks like "<extractor> <id>"; the id is the last token.
     *
     * @return array<string, true>
     */
    private static function loadArchiveIds(string $archiveFile): array
    {
        if (!is_file($archiveFile)) {
            return [];
        }
        $ids = [];
        foreach (preg_split('/\R/', (string)file_get_contents($archiveFile)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $id = (string)end($parts);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * Track id from a yt-dlp "Requested format is not available" stderr line, null otherwise.
     * Pure — unit-testable.
     */
    private static function matchFormatUnavailableId(string $line): ?string
    {
        return preg_match('~ERROR:\s+\[[^]]+]\s+(\S+):\s+Requested format is not available~', $line, $m)
            ? $m[1]
            : null;
    }

    /** ODG value for display: "-1.5", "-0.2", "0". Pure — unit-testable. */
    private static function formatOdg(float $odg): string
    {
        $s = rtrim(rtrim(sprintf('%.2f', $odg), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    /**
     * Audio bitrate in kbps from a decoded .info.json, falling back to total bitrate.
     * Pure — unit-testable.
     *
     * @param array<string, mixed> $info
     */
    private static function audioBitrateKbps(array $info): ?float
    {
        foreach (['abr', 'tbr'] as $key) {
            $v = $info[$key] ?? null;
            if (is_numeric($v) && (float)$v > 0) {
                return (float)$v;
            }
        }

        return null;
    }

    /**
     * Audio codec from a decoded .info.json, null when absent or "none".
     * Pure — unit-testable.
     *
     * @param array<string, mixed> $info
     */
    private static function audioCodec(array $info): ?string
    {
        $v = $info['acodec'] ?? null;

        return is_string($v) && trim($v) !== '' && strcasecmp(trim($v), 'none') !== 0 ? trim($v) : null;
    }

    /**
     * Audio bitrate (kbps) and codec name read from the file itself, for tracks without a
     * usable .info.json bitrate (spotdl writes no sidecar). Prefers the audio stream's
     * bit_rate, falls back to the container's (streams in some containers report N/A).
     * Keyed output (default=nw=1) keeps the codec and bitrate lines unambiguous.
     *
     * @return array{0: ?float, 1: ?string} [kbps, codec_name]
     */
    private function probeAudioProperties(string $path): array
    {
        if (!is_file($path)) {
            return [null, null];
        }
        $args = [
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=codec_name,bit_rate:format=bit_rate',
            '-of',
            'default=nw=1',
            $path,
        ];
        $cmd = escapeshellcmd($this->ffprobeBin).' '.implode(' ', array_map('escapeshellarg', $args));
        [$exit, $out] = $this->runCmd($cmd, fn() => null);
        if ($exit !== 0) {
            return [null, null];
        }
        $kbps = null;
        $codec = null;
        foreach (preg_split('/\R/', trim($out)) as $line) {
            $line = trim($line);
            if ($codec === null && preg_match('/^codec_name=(.+)$/', $line, $m)) {
                $codec = trim($m[1]);
            } elseif ($kbps === null && preg_match('/^bit_rate=(.+)$/', $line, $m)) {
                $v = trim($m[1]);
                if (is_numeric($v) && (float)$v > 0) {
                    $kbps = (float)$v / 1000.0;
                }
            }
        }

        return [$kbps, $codec];
    }

    /**
     * Estimated PEAQ ODG for a source, interpolated from ODG_CALIBRATION: lossless is
     * always 0, unknown codecs use the MP3 curve (most conservative), and bitrates above
     * a codec's highest anchor clamp to that anchor's ODG (a 400 kbps MP3 is still MP3,
     * not transparent). Pure — unit-testable.
     */
    public static function estimateOdg(?string $acodec, float $kbps): float
    {
        $codec = self::normalizeCodec($acodec);
        if ($codec === 'lossless') {
            return 0.0;
        }
        if ($kbps <= 0) {
            return -4.0;
        }
        $points = [[0.0, -4.0], ...(self::ODG_CALIBRATION[$codec] ?? self::ODG_CALIBRATION['mp3'])];
        $last = $points[count($points) - 1];
        if ($kbps >= $last[0]) {
            return $last[1];
        }
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            if ($kbps <= $points[$i][0]) {
                [$k0, $o0] = $points[$i - 1];
                [$k1, $o1] = $points[$i];

                return $o0 + ($o1 - $o0) * (($kbps - $k0) / ($k1 - $k0));
            }
        }

        return $last[1];
    }

    /**
     * Canonical codec family for an acodec / ffprobe codec_name value: "mp3", "aac",
     * "opus", "vorbis", "lossless", or "unknown". Pure — unit-testable.
     */
    public static function normalizeCodec(?string $acodec): string
    {
        $c = strtolower(trim((string)$acodec));
        if ($c === '' || $c === 'none') {
            return 'unknown';
        }
        if (str_starts_with($c, 'mp4a') || str_starts_with($c, 'aac')) {
            return 'aac';
        }
        if (str_starts_with($c, 'mp3') || $c === 'mpga' || $c === 'libmp3lame') {
            return 'mp3';
        }
        if (str_starts_with($c, 'opus') || $c === 'libopus') {
            return 'opus';
        }
        if (str_starts_with($c, 'vorbis') || $c === 'libvorbis') {
            return 'vorbis';
        }
        if (str_starts_with($c, 'pcm_') || in_array($c, self::LOSSLESS_CODECS, true)) {
            return 'lossless';
        }

        return 'unknown';
    }

    /**
     * Track title recovered from a "{id} - {title}.{ext}" library filename, for entries
     * without a title in the playlist metadata or an .info.json sidecar. Pure — unit-testable.
     */
    private static function titleFromFilename(string $srcPath, string $id): string
    {
        $base = pathinfo($srcPath, PATHINFO_FILENAME);

        return trim((string)preg_replace('/^'.preg_quote($id, '/').'\s*-\s*/', '', $base));
    }

    private function ensureConverted(
        string $sourcePath,
        string $targetPath,
        string $format,
        array $tags = [],
        ?string $coverPath = null,
    ): ?string {
        if (is_file($targetPath)) {
            if (!$this->shouldReencode($format, $targetPath)) {
                return $targetPath;
            }
            // Never write via $this->io here: this runs mid-loop while overallBar/convBar are
            // actively redrawing, and under a non-decorated output (e.g. `ddev exec`, no pty)
            // ProgressBar's redraw doesn't end with a newline, so an inline message would land
            // glued onto the bar's still-open line. Collected and reported in the end-of-run
            // summary instead, same as $failed/$lowQualityTracks.
            $this->reencodedStaleMp3[] = $targetPath;
            unlink($targetPath);
        }
        if (!is_file($sourcePath)) {
            return null;
        }

        // Cover art is embedded only for mp3/flac (see buildFfmpegArgs); ignore it otherwise.
        $cover = ($coverPath !== null && is_file($coverPath)) ? $coverPath : null;

        // Normalize sample rates the export target rejects (anything outside 44.1/48/96 kHz).
        // Resample to the nearest supported rate >= source (quality-preserving), capped at 96 kHz.
        $srcRate = $this->probeSampleRate($sourcePath);
        $targetRate = $srcRate !== null ? self::targetSampleRate($srcRate) : 44100;

        $cmd = self::buildFfmpegArgs(
            $this->ffmpegBin,
            $this->mp3Mode,
            $this->mp3Quality,
            $this->mp3Bitrate,
            $sourcePath,
            $targetPath,
            $format,
            $tags,
            $cover,
            $targetRate,
        );

        $cmdStr = implode(' ', array_map(static fn($p) => escapeshellarg((string)$p), $cmd));
        [$exit] = $this->runCmd($cmdStr, fn() => null);

        return $exit === 0 ? $targetPath : null;
    }

    /**
     * Whether an existing mp3 target should be deleted and reconverted. Only applies to mp3
     * under CBR (VBR has no fixed bitrate target to compare against) and only when the
     * feature is enabled (--reencode-stale-mp3, on by default).
     */
    private function shouldReencode(string $format, string $targetPath): bool
    {
        if (!$this->reencodeStaleMp3 || $format !== 'mp3' || $this->mp3Mode !== 'cbr') {
            return false;
        }
        [$actualKbps] = $this->probeAudioProperties($targetPath);

        return $actualKbps !== null && self::isBitrateMismatch($actualKbps, (float)$this->mp3Bitrate);
    }

    /**
     * Whether an already-probed existing mp3's average bitrate is far enough from the
     * configured target to be considered stale (e.g. a pre-CBR-default VBR encode). A real
     * CBR encode's average sits within a couple percent of the target; a 10%-or-8kbps
     * tolerance comfortably separates that from a genuinely mismatched encode. Pure —
     * unit-testable.
     */
    public static function isBitrateMismatch(float $actualKbps, float $configuredKbps): bool
    {
        return abs($actualKbps - $configuredKbps) > max(8.0, $configuredKbps * 0.1);
    }

    private function probeSampleRate(string $path): ?int
    {
        if (!is_file($path)) {
            return null;
        }
        $args = [
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=sample_rate',
            '-of',
            'default=nk=1:nw=1',
            $path,
        ];
        $cmd = escapeshellcmd($this->ffprobeBin).' '.implode(' ', array_map('escapeshellarg', $args));
        [$exit, $out] = $this->runCmd($cmd, fn() => null);
        if ($exit !== 0) {
            return null;
        }
        $rate = (int)trim($out);

        return $rate > 0 ? $rate : null;
    }

    public static function targetSampleRate(int $src): ?int
    {
        $supported = [44100, 48000, 96000];
        if (in_array($src, $supported, true)) {
            return null; // already supported → leave native rate
        }
        foreach ($supported as $rate) {
            if ($rate >= $src) {
                return $rate; // nearest supported >= source (quality-preserving)
            }
        }

        return 96000; // source above 96 kHz → cap at highest supported
    }

    /**
     * Build the ffmpeg command for one conversion. Pure — no I/O — so it is unit-testable.
     *
     * Only the source audio stream (`0:a:0`) is mapped, never the source's own video/cover
     * stream: copying an embedded cover into a WAV container makes ffmpeg fail
     * ("Conversion failed!"). Cover art is (re-)attached from $coverPath for mp3/flac only.
     *
     * @param array<string, string> $tags title/artist/album/genre/comment/date
     * @param ?string $coverPath validated cover file, or null to skip embedding
     * @param ?int $targetRate resample target (-ar), or null to keep native rate
     * @return list<string>
     */
    private static function buildFfmpegArgs(
        string $ffmpegBin,
        string $mp3Mode,
        string $mp3Quality,
        string $mp3Bitrate,
        string $sourcePath,
        string $targetPath,
        string $format,
        array $tags,
        ?string $coverPath,
        ?int $targetRate,
    ): array {
        $hasCover = $coverPath !== null && in_array($format, ['mp3', 'flac'], true);

        $cmd = [$ffmpegBin, '-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $sourcePath];
        if ($hasCover) {
            $cmd[] = '-i';
            $cmd[] = $coverPath;
        }

        $cmd = match ($format) {
            'mp3' => array_merge($cmd, [
                '-map',
                '0:a:0',
                ...($hasCover ? ['-map', '1:0', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic'] : []),
                '-c:a',
                'libmp3lame',
                ...($mp3Mode === 'vbr' ? ['-q:a', $mp3Quality] : ['-b:a', "{$mp3Bitrate}k"]),
                '-id3v2_version',
                '3',
            ]),
            'flac' => array_merge($cmd, [
                '-map',
                '0:a:0',
                ...($hasCover ? ['-map', '1:0', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic'] : []),
                '-c:a',
                'flac',
            ]),
            'wav' => array_merge($cmd, ['-map', '0:a:0', '-c:a', 'pcm_s16le']),
            default => $cmd,
        };

        if ($targetRate !== null) {
            $cmd = array_merge($cmd, ['-ar', (string)$targetRate]);
        }

        foreach (['title', 'artist', 'album', 'genre', 'comment'] as $k) {
            if (!empty($tags[$k])) {
                $cmd[] = '-metadata';
                $cmd[] = "$k={$tags[$k]}";
            }
        }
        if (!empty($tags['date']) && preg_match('/^\d{4}/', $tags['date'], $m)) {
            $cmd = array_merge($cmd, ['-metadata', "date=$m[0]", '-metadata', "year=$m[0]"]);
        }
        $cmd[] = $targetPath;

        return $cmd;
    }

    private static function relativePath(string $from, string $to): string
    {
        $from = str_replace('\\', '/', realpath($from) ?: $from);
        $to = str_replace('\\', '/', realpath($to) ?: $to);
        $fromDir = rtrim(is_dir($from) ? $from : dirname($from), '/');

        return self::relativeFromParts($fromDir, $to);
    }

    /**
     * Compute a relative path between two already-normalized absolute paths (both use '/',
     * $fromDir is a directory). Pure — no filesystem access — so it is unit-testable.
     */
    private static function relativeFromParts(string $fromDir, string $to): string
    {
        $fromParts = explode('/', $fromDir);
        $toParts = explode('/', $to);
        while (count($fromParts) && count($toParts) && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromParts)).implode('/', $toParts);
    }
}
