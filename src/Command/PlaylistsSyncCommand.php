<?php

declare(strict_types=1);

namespace App\Command;

use App\Process\ProcessRunner;
use App\Process\ProcOpenProcessRunner;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class PlaylistsSyncCommand extends BaseCommand
{
    public const SOURCE_SPOTIFY = 'spotify';
    public const SOURCE_YTDLP = 'ytdlp';
    private ?string $cookiesFile;
    private string $extractorRetries;
    private string $ffmpegBin;
    private string $ffprobeBin;
    private SymfonyStyle $io;
    private ?string $limitRate;
    private string $mp3Quality;
    private int $pauseBetween;
    private string $retrySleep;
    private readonly ProcessRunner $runner;
    private string $sleepRequests;
    private string $spotdlBin;
    private ?string $spotdlCookieFile;
    private string $ytDlpBin;

    public function __construct(?ProcessRunner $runner = null)
    {
        parent::__construct();
        $this->runner = $runner ?? new ProcOpenProcessRunner();
    }

    protected function configure(): void
    {
        $this
            ->setName('playlists:sync')
            ->setDescription('Download SoundCloud/Spotify/YouTube playlists and convert to MP3/WAV/FLAC.')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Path to file with playlist URLs')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Base output directory')
            ->addOption('playlists-dir', null, InputOption::VALUE_REQUIRED, 'Directory for M3U8 playlist files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->loadDotenv();

        $config = $this->loadConfig();

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        // E-category: env override or ddev-installed default
        $this->ytDlpBin = getenv('YTDLP_BIN') ?: 'yt-dlp';
        $this->spotdlBin = getenv('SPOTDL_BIN') ?: 'spotdl';
        $this->ffmpegBin = getenv('FFMPEG_BIN') ?: 'ffmpeg';
        $this->ffprobeBin = getenv('FFPROBE_BIN') ?: 'ffprobe';
        $this->mp3Quality = getenv('MP3_QUALITY') !== false ? (string)getenv('MP3_QUALITY') : '0';
        $formatsStr = getenv('FORMATS') ?: 'original,mp3,wav,flac';

        $cookiesPath = dirname(__DIR__, 2).'/config/cookies.txt';
        $this->cookiesFile = is_file($cookiesPath) ? $cookiesPath : null;

        // spotdl matches Spotify tracks on YouTube Music — its (optional) cookies are YT Music
        // cookies, a different account/site than the yt-dlp SoundCloud cookies above.
        $spotdlCookiePath = getenv('SPOTDL_COOKIE_FILE') ?: dirname(__DIR__, 2).'/config/spotdl-cookies.txt';
        $this->spotdlCookieFile = is_file($spotdlCookiePath) ? $spotdlCookiePath : null;

        // B-category: from CLI option, env, or config (prompt once if not set)
        $inputFile = $input->getOption('input') ?? (getenv('INPUT_FILE') ?: ($config['input_file'] ?? null));
        $baseOutDir = $input->getOption('out') ?? (getenv('OUTPUT_DIR') ?: ($config['output_dir'] ?? null));

        if ($input->isInteractive()) {
            if (!$inputFile) {
                $savedInput = $config['input_file'] ?? 'config/playlists.txt';
                $q = new Question("Input file [<info>$savedInput</info>]: ", $savedInput);
                $inputFile = $helper->ask($input, $output, $q);
            }
            if (!$baseOutDir) {
                $savedOut = $config['output_dir'] ?? './downloads';
                $q = new Question("Output directory [<info>$savedOut</info>]: ", $savedOut);
                $baseOutDir = $helper->ask($input, $output, $q);
            }
            $this->saveConfig(array_merge($config, [
                'input_file' => $inputFile,
                'output_dir' => $baseOutDir,
            ]));
        } elseif (!$inputFile || !$baseOutDir) {
            $this->io->error('--input and --out are required in non-interactive mode.');

            return Command::FAILURE;
        }

        $this->extractorRetries = getenv('EXTRACTOR_RETRIES') ?: '10';
        $this->retrySleep = getenv('RETRY_SLEEP') ?: 'exp=2:10:120';
        $this->sleepRequests = $this->resolveSleepRequests(getenv('SLEEP_REQUESTS') ?: '2');
        $this->limitRate = getenv('LIMIT_RATE') ?: null;
        $this->pauseBetween = (int)(getenv('PAUSE_BETWEEN') ?: '2');

        $libFilenameTemplate = getenv('LIB_FILENAME_TEMPLATE') ?: '%(id)s - %(title)s';

        if (!is_file($inputFile)) {
            $this->io->error("Input file not found: $inputFile");

            return Command::FAILURE;
        }
        if (!is_dir($baseOutDir) && !@mkdir($baseOutDir, 0777, true) && !is_dir($baseOutDir)) {
            $this->io->error("Failed to create output directory: $baseOutDir");

            return Command::FAILURE;
        }

        $libraryDir = getenv('LIBRARY_DIR') ?: ($baseOutDir.DIRECTORY_SEPARATOR.'library');
        $archiveDir = getenv('ARCHIVE_DIR') ?: ($baseOutDir.DIRECTORY_SEPARATOR.'.archive');
        $playlistsDir = $input->getOption('playlists-dir')
            ?? (getenv('PLAYLISTS_DIR') ?: ($baseOutDir.DIRECTORY_SEPARATOR.'playlists'));

        foreach ([$libraryDir, $archiveDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                $this->io->error("Failed to create directory: $dir");

                return Command::FAILURE;
            }
        }
        $originalLibDir = $libraryDir.DIRECTORY_SEPARATOR.'original';
        if (!is_dir($originalLibDir) && !@mkdir($originalLibDir, 0777, true) && !is_dir($originalLibDir)) {
            $this->io->error("Failed to create directory: $originalLibDir");

            return Command::FAILURE;
        }
        if ($playlistsDir && !is_dir($playlistsDir) && !@mkdir($playlistsDir, 0777, true) && !is_dir($playlistsDir)) {
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

        $fetchBar = new ProgressBar($output, count($urls));
        $fetchBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $fetchBar->setMessage('');
        $fetchBar->start();

        $playlists = [];
        foreach ($urls as $url) {
            $fetchBar->setMessage(parse_url($url, PHP_URL_PATH) ?? $url);
            $source = self::classifySourceUrl($url);
            try {
                [$plTitle, , $plUploader, $plEntries] = $source === self::SOURCE_SPOTIFY
                    ? $this->getSpotifyPlaylistIdentityAndEntries($url, $archiveDir)
                    : $this->getPlaylistIdentityAndEntries($url);
                $playlists[] = [
                    'url' => $url,
                    'source' => $source,
                    'folder' => self::safeName(sprintf('%s - %s', $plUploader, $plTitle)),
                    'entries' => $plEntries,
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
        $overallBar = new ProgressBar($output, max(1, $totalTracks * 2));
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
            $this->io->text('Downloading originals...');
            $overallBar->setMessage('downloading...');
            [$newCount, $archivedCount, $exit, $failedEntries] = $playlist['source'] === self::SOURCE_SPOTIFY
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
            $failedCount = max(0, count($plEntries) - $newCount - $archivedCount);
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
            if ($exit !== 0) {
                $this->io->warning("$tool exited with code $exit for originals; continuing.");
                $failed[] = "$plFolder — $tool exit code $exit";
            }

            foreach ($formatDirs as $d) {
                if (!is_dir($d) && !@mkdir($d, 0777, true) && !is_dir($d)) {
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
                $convBar = new ProgressBar($output, count($plEntries));
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
                $srcPath = str_replace('\\', '/', realpath($matches[0]) ?: $matches[0]);
                $infoJson = preg_replace('/\.\w+$/', '.info.json', $srcPath);
                $coverJpg = preg_replace('/\.\w+$/', '.jpg', $srcPath);
                $tags = is_file($infoJson) ? self::readTagsFromInfoJson($infoJson) : [];

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

            foreach (['mp3', 'wav', 'flac'] as $fmt) {
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

        $this->io->success('All done.');

        return Command::SUCCESS;
    }

    private function loadDotenv(): void
    {
        $candidates = [
            getenv('DOTENV_PATH') ?: null,
            getcwd().DIRECTORY_SEPARATOR.'.env',
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env',
        ];
        foreach (array_filter($candidates) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if ($v !== '' && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with(
                                $v,
                                "'"
                            )))) {
                    $v = substr($v, 1, -1);
                }
                $v = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/i', static function ($m) {
                    return getenv($m[1]) !== false ? (string)getenv($m[1]) : '';
                }, $v);
                putenv("$k=$v");
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
            break;
        }
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

    private function requireBinary(string $bin, ?string $versionArg = null): void
    {
        $cmd = escapeshellcmd($bin).($versionArg ? ' '.$versionArg : '');
        [$exit] = $this->runCmd($cmd.' 2>&1', static fn() => null);
        if ($exit !== 0) {
            throw new RuntimeException("Missing dependency: $bin. Please install it and ensure it's in PATH.");
        }
    }

    private function runCmd(string $cmd, ?callable $onLine = null): array
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
            function (string $chunk): void {
                foreach (preg_split('/\R/u', $chunk) as $line) {
                    if ($line !== '') {
                        $this->io->getErrorStyle()->text($line);
                    }
                }
            },
            true,
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
     * @return array{string, string, string, list<array{id: string, title: string}>}
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
     * [title, id, uploader, entries] tuple getPlaylistIdentityAndEntries() yields for yt-dlp
     * sources. The save file carries no playlist-owner field, so the uploader is always
     * 'Spotify'. Pure — unit-testable.
     *
     * @param list<array<string, mixed>> $songs
     * @return array{string, string, string, list<array{id: string, title: string}>}
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

        return [$title, $id, 'Spotify', $entries];
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
        foreach ($json['entries'] ?? [] as $e) {
            $tid = (string)($e['id'] ?? '');
            if ($tid !== '') {
                $entries[] = ['id' => $tid, 'title' => (string)($e['title'] ?? '')];
            }
        }

        return [$title, $id, $uploader, $entries];
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
     * @return array{int, int, int, list<array{id: string, title: string}>}
     *         [newCount, archivedCount, exitCode, failedEntries]
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

        $dlArgs = ['-f', 'bestaudio/best'];
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
            }
        );

        return [
            $newCount,
            self::countArchived($plEntries, $preArchivedIds),
            $exit,
            self::missingEntries($plEntries, $preArchivedIds + $doneIds),
        ];
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

    private function ensureConverted(
        string $sourcePath,
        string $targetPath,
        string $format,
        array $tags = [],
        ?string $coverPath = null,
    ): ?string {
        if (is_file($targetPath)) {
            return $targetPath;
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
            $this->mp3Quality,
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
        string $mp3Quality,
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
                '-q:a',
                $mp3Quality,
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
