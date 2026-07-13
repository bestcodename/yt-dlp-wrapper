<?php

declare(strict_types=1);

namespace App\Command;

use App\Playlists\AudioConverter;
use App\Playlists\SpotdlDownloader;
use App\Playlists\YtDlpDownloader;
use App\Process\BinaryChecker;
use App\Process\ProcessRunner;
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
    public const SOURCE_SPOTIFY = 'spotify';
    public const SOURCE_YTDLP = 'ytdlp';
    private const VALID_FORMATS = ['original', 'mp3', 'wav', 'flac'];
    private const VALID_PLAYLIST_LAYOUTS = ['flat', 'per-playlist', 'per-format'];
    private ?AudioConverter $audioConverter;
    private readonly BinaryChecker $binaryChecker;
    private ?string $cookiesFile;
    private string $extractorRetries;
    private string $ffmpegBin;
    private string $ffprobeBin;
    private string $jsRuntimes;
    private ?string $limitRate;
    private ?float $minOdg;
    private string $minOdgMode;
    private string $mp3Bitrate;
    private string $mp3Mode;
    private string $mp3Quality;
    private int $pauseBetween;
    private bool $reencodeStaleMp3;
    private string $retrySleep;
    private string $sleepRequests;
    private string $spotdlBin;
    private ?string $spotdlCookieFile;
    private ?SpotdlDownloader $spotdlDownloader;
    private string $ytDlpBin;
    private ?YtDlpDownloader $ytDlpDownloader;

    public function __construct(
        ?ProcessRunner $runner = null,
        ?string $name = null,
        ?BinaryChecker $binaryChecker = null,
        ?AudioConverter $audioConverter = null,
        ?SpotdlDownloader $spotdlDownloader = null,
        ?YtDlpDownloader $ytDlpDownloader = null,
    ) {
        parent::__construct($runner, $name);
        $this->binaryChecker = $binaryChecker ?? new BinaryChecker($this->runner);
        $this->audioConverter = $audioConverter;
        $this->spotdlDownloader = $spotdlDownloader;
        $this->ytDlpDownloader = $ytDlpDownloader;
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
                'playlist-layout',
                null,
                InputOption::VALUE_REQUIRED,
                'M3U8 directory layout: "flat" (default), "per-playlist", or "per-format"'
            )
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
                'Comma-separated output formats: original, mp3, wav, flac, or "all" for every format'
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
                'js-runtimes',
                null,
                InputOption::VALUE_REQUIRED,
                'yt-dlp --js-runtimes value (e.g. "node", "deno") — needed to solve YouTube\'s n-sig/EJS challenge'
            )
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
        $this->audioConverter ??= new AudioConverter(
            $this->runner,
            $this->ffmpegBin,
            $this->ffprobeBin,
            $this->mp3Mode,
            $this->mp3Quality,
            $this->mp3Bitrate,
            $this->reencodeStaleMp3,
        );

        // Formats: CLI → env → config; prompted every interactive run (pre-filled with the
        // current env/config/default value) unless an explicit --formats CLI value is given
        $formatsCli = $input->getOption('formats');
        $formatsRaw = $formatsCli
            ?? (getenv('FORMATS') ?: null)
            ?? (isset($config['formats']) ? (string)$config['formats'] : null)
            ?? self::DEFAULT_FORMATS;

        if ($input->isInteractive() && $formatsCli === null) {
            $formatsRaw = $this->askText(
                $input,
                $output,
                '<question>Output formats</question> (comma-separated: original, mp3, wav, flac, or "all")',
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
        $this->jsRuntimes = $this->resolveParam(
            $input->getOption('js-runtimes'),
            'JS_RUNTIMES',
            $config,
            'js_runtimes',
            'node'
        );
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
        $this->spotdlDownloader ??= new SpotdlDownloader($this->runner, $this->spotdlBin, $this->spotdlCookieFile);
        $this->ytDlpDownloader ??= new YtDlpDownloader(
            $this->runner,
            $this->ytDlpBin,
            $this->cookiesFile,
            $this->extractorRetries,
            $this->limitRate,
            $this->retrySleep,
            $this->sleepRequests,
            $this->minOdgMode,
            $this->minOdg,
            $this->jsRuntimes,
        );

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
        // Playlist layout: CLI → env → config; prompted once when configured nowhere, like formats
        // (stops firing once an answer is persisted to the config file)
        $playlistLayoutCli = $input->getOption('playlist-layout');
        $playlistLayoutEnv = getenv('PLAYLIST_LAYOUT') ?: null;
        $playlistLayoutConfigured = $playlistLayoutCli !== null
            || $playlistLayoutEnv !== null
            || array_key_exists('playlist_layout', $config);
        $playlistLayout = strtolower(
            $playlistLayoutCli
            ?? $playlistLayoutEnv
            ?? (isset($config['playlist_layout']) ? (string)$config['playlist_layout'] : null)
            ?? 'flat'
        );

        if ($input->isInteractive() && !$playlistLayoutConfigured) {
            $playlistLayout = $this->askChoice(
                $input,
                $output,
                'Playlist layout (flat = one dir; per-playlist = one dir per playlist;'
                .' per-format = one dir per format)',
                self::VALID_PLAYLIST_LAYOUTS,
                in_array($playlistLayout, self::VALID_PLAYLIST_LAYOUTS, true) ? $playlistLayout : 'flat'
            );
            $this->updateConfig(['playlist_layout' => $playlistLayout]);
        } elseif (!in_array($playlistLayout, self::VALID_PLAYLIST_LAYOUTS, true)) {
            $this->io->error(
                "Invalid --playlist-layout / PLAYLIST_LAYOUT value: $playlistLayout ".
                '(expected "flat", "per-playlist", or "per-format")'
            );

            return Command::FAILURE;
        }

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

        $playlistEntries = self::parseInputFileEntries(file($inputFile));
        $urls = array_column($playlistEntries, 'url');
        if (!$urls) {
            $this->io->error("No URLs found in $inputFile");

            return Command::FAILURE;
        }

        $sources = array_map([self::class, 'classifySourceUrl'], $urls);

        try {
            if (in_array(self::SOURCE_YTDLP, $sources, true)) {
                ($this->binaryChecker)($this->ytDlpBin, '--version');
            }
            if (in_array(self::SOURCE_SPOTIFY, $sources, true)) {
                ($this->binaryChecker)($this->spotdlBin, '--version');
            }
            ($this->binaryChecker)($this->ffmpegBin, '-version');
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
        foreach ($playlistEntries as $entry) {
            $url = $entry['url'];
            $alias = $entry['alias'];
            $fetchBar->setMessage(parse_url($url, PHP_URL_PATH) ?? $url);
            $source = self::classifySourceUrl($url);
            try {
                [$plTitle, , $plUploader, $plEntries, $plSkipped] = $source === self::SOURCE_SPOTIFY
                    ? $this->spotdlDownloader->getPlaylistIdentityAndEntries($url, $archiveDir, $this->io)
                    : $this->ytDlpDownloader->getPlaylistIdentityAndEntries($url, $this->io);
                $playlists[] = [
                    'url' => $url,
                    'source' => $source,
                    'folder' => $alias !== null
                        ? self::safeName($alias)
                        : self::safeName(sprintf('%s - %s', $plUploader, $plTitle)),
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
                ? ($this->spotdlDownloader)($url, $originalLibDir, $archiveDir, $overallBar, $plEntries, $this->io)
                : ($this->ytDlpDownloader)(
                    $url,
                    $originalLibDir,
                    $libFilenameTemplate,
                    $archiveDir,
                    $overallBar,
                    $plEntries,
                    $this->io
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
                $this->io->text(sprintf('Skipped (below est. ODG %s):', AudioConverter::formatOdg($this->minOdg)));
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
                $tags = is_array($info) ? AudioConverter::mapInfoJsonToTags($info) : [];
                if ($this->minOdg !== null) {
                    // a track can appear in many playlists — evaluate its library file once
                    if (!array_key_exists($srcPath, $qualityCache)) {
                        $abr = is_array($info) ? AudioConverter::audioBitrateKbps($info) : null;
                        $codec = is_array($info) ? AudioConverter::audioCodec($info) : null;
                        if ($abr === null) {
                            [$abr, $probedCodec] = $this->audioConverter->probeAudioProperties($srcPath);
                            $codec ??= $probedCodec;
                        }
                        $qualityCache[$srcPath] = [
                            $abr,
                            $codec,
                            $abr !== null ? AudioConverter::estimateOdg($codec, $abr) : null,
                        ];
                    }
                    [$abr, $codec, $odg] = $qualityCache[$srcPath];
                    if ($odg !== null && AudioConverter::isBelowMinOdg($odg, $this->minOdg)) {
                        if (!isset($lowQualityTracks[$entry['id']])) {
                            // set-playlist entries often carry no title; sidecar-less files
                            // (pre-info.json downloads) fall back to the library filename
                            $title = trim((string)($entry['title'] ?? ''));
                            if ($title === '') {
                                $title = trim((string)($tags['title'] ?? ''));
                            }
                            if ($title === '') {
                                $title = AudioConverter::titleFromFilename($srcPath, (string)$entry['id']);
                            }
                            $lowQualityTracks[$entry['id']] = sprintf(
                                '%s (%s): %s kbps %s, est. ODG %s',
                                $title,
                                $entry['id'],
                                AudioConverter::formatKbps($abr),
                                AudioConverter::normalizeCodec($codec),
                                AudioConverter::formatOdg($odg)
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
                    $made = ($this->audioConverter)($srcPath, $target, $fmt, $tags, $cover);
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
                $m3uPath = self::buildM3uPath($playlistsDir, $playlistLayout, $plFolder, $fmt);
                $m3uDir = dirname($m3uPath);
                if (!$this->ensureDirectory($m3uDir, 0777)) {
                    $this->io->error("Failed to create directory: $m3uDir");
                    $failed[] = "$plFolder — failed to create directory $m3uDir";
                    continue;
                }
                $m3u = "#EXTM3U\n";
                // $m3uDir must already exist (ensureDirectory() above) before this loop runs —
                // relativePath() realpath()s its "from" argument and silently falls back to a
                // non-canonicalized path otherwise, which can miscount the "../" prefix.
                foreach ($list as $abs) {
                    $m3u .= str_replace('\\', '/', self::relativePath($m3uDir, $abs))."\n";
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

        $reencodedStaleMp3 = $this->audioConverter->getReencodedStaleMp3();
        if ($reencodedStaleMp3 !== []) {
            $this->io->warning(
                array_merge(
                    [
                        sprintf(
                            '%d stale mp3(s) re-encoded (bitrate mismatch, likely pre-CBR VBR):',
                            count($reencodedStaleMp3)
                        ),
                    ],
                    $reencodedStaleMp3
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
                            AudioConverter::formatOdg($this->minOdg),
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
                    AudioConverter::formatOdg($this->minOdg)
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
     * drops empties, dedups, and rejects unknown names. "all" (case-insensitive), alone or mixed
     * with other entries, expands to every valid format. Throws on an empty or invalid list so
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
        if (in_array('all', array_map('strtolower', $entries), true)) {
            return implode(',', self::VALID_FORMATS);
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

    /**
     * Parses input-file lines into ordered [url, alias] pairs. Blank lines and plain `#` comments
     * are dropped with zero effect, exactly as before — existing files that use bare `#` lines as
     * human-only section headers (e.g. "# DJ Sets" above a block of URLs) must never be
     * reinterpreted. A comment matching `# alias: <name>` (case-insensitive "alias", colon
     * required, name trimmed) attaches its name to the URL line directly following it — nothing
     * (blank line, other comment) may sit in between; any intervening line clears the pending
     * alias. Consecutive alias comments before one URL: last one wins. An alias marker whose name
     * is empty after trimming is treated as no alias. Pure — unit-testable.
     *
     * @param list<string> $lines raw lines from file($inputFile), as returned (not yet trimmed)
     * @return list<array{url: string, alias: ?string}>
     */
    public static function parseInputFileEntries(array $lines): array
    {
        $entries = [];
        $pendingAlias = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $pendingAlias = null;
                continue;
            }
            if ($line[0] === '#') {
                $pendingAlias = (preg_match('/^#\s*alias\s*:\s*(.*)$/i', $line, $m) === 1 && trim($m[1]) !== '')
                    ? trim($m[1])
                    : null;
                continue;
            }
            $entries[] = ['url' => $line, 'alias' => $pendingAlias];
            $pendingAlias = null;
        }

        return $entries;
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

    private static function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim((string)$name);
    }

    /**
     * Builds the .m3u8 path for one playlist × format, per --playlist-layout:
     * "flat" (default): $playlistsDir/"$plFolder - $fmt.m3u8" — unchanged from before this option
     * existed. "per-playlist": $playlistsDir/$plFolder/"$fmt.m3u8" — one directory per playlist,
     * all requested formats inside. "per-format": $playlistsDir/$fmt/"$plFolder.m3u8" — one
     * directory per format, all playlists of that format inside. Each subdirectory mode drops the
     * redundant name component since the containing directory already encodes it (matches the
     * existing library/{mp3,wav,flac}/<id> - <title>.<ext> convention). Pure — unit-testable.
     */
    private static function buildM3uPath(string $playlistsDir, string $layout, string $plFolder, string $fmt): string
    {
        $base = rtrim($playlistsDir, DIRECTORY_SEPARATOR);

        return match ($layout) {
            'per-playlist' => $base.DIRECTORY_SEPARATOR.$plFolder.DIRECTORY_SEPARATOR."$fmt.m3u8",
            'per-format' => $base.DIRECTORY_SEPARATOR.$fmt.DIRECTORY_SEPARATOR."$plFolder.m3u8",
            default => $base.DIRECTORY_SEPARATOR."$plFolder - $fmt.m3u8",
        };
    }

    /** ODG value for display: "-1.5", "-0.2", "0". Pure — unit-testable. */
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
