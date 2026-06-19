<?php

declare(strict_types=1);

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class SoundCloudDownloadCommand extends BaseCommand
{
    private ?string $cookiesFile;
    private string $extractorRetries;
    private string $ffmpegBin;
    private SymfonyStyle $io;
    private ?string $limitRate;
    private string $mp3Quality;
    private int $pauseBetween;
    private string $retrySleep;
    private string $sleepRequests;
    private string $ytDlpBin;

    protected function configure(): void
    {
        $this
            ->setName('soundcloud:download')
            ->setDescription('Download SoundCloud playlists via yt-dlp and convert to MP3/WAV/FLAC.')
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
        $this->ffmpegBin = getenv('FFMPEG_BIN') ?: 'ffmpeg';
        $this->mp3Quality = getenv('MP3_QUALITY') !== false ? (string)getenv('MP3_QUALITY') : '0';
        $formatsStr = getenv('FORMATS') ?: 'original,mp3,wav,flac';

        $cookiesPath = dirname(__DIR__, 2).'/config/cookies.txt';
        $this->cookiesFile = is_file($cookiesPath) ? $cookiesPath : null;

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

        try {
            $this->requireBinary($this->ytDlpBin, '--version');
            $this->requireBinary($this->ffmpegBin, '-version');
        } catch (RuntimeException $e) {
            $this->io->error($e->getMessage());

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

        $formatsRequested = array_values(array_filter(array_map('trim', explode(',', $formatsStr))));

        $formatDirs = [
            'mp3' => $libraryDir.DIRECTORY_SEPARATOR.'mp3',
            'wav' => $libraryDir.DIRECTORY_SEPARATOR.'wav',
            'flac' => $libraryDir.DIRECTORY_SEPARATOR.'flac',
        ];

        $this->io->text('Starting downloads...');

        foreach ($urls as $urlIdx => $url) {
            $this->io->section(sprintf('[%d/%d] %s', $urlIdx + 1, count($urls), $url));

            try {
                [$plTitle, , $plUploader, $plEntries] = $this->getPlaylistIdentityAndEntries($url);
            } catch (RuntimeException $e) {
                $this->io->error($e->getMessage());
                continue;
            }

            $plFolder = self::safeName(sprintf('%s - %s', $plUploader, $plTitle));
            $this->io->text("Playlist: $plFolder");

            $commonArgs = [
                '--no-overwrites',
                '--continue',
                '--ignore-errors',
                '--no-abort-on-error',
                '--yes-playlist',
                '--add-metadata',
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
                'filename',
                '--print',
                'after_move:filepath',
                escapeshellarg($url),
            ];

            $this->io->text('Downloading originals...');
            [$exit] = $this->runCmd(implode(' ', $ytCmd));
            if ($exit !== 0) {
                $this->io->warning("yt-dlp exited with code $exit for originals; continuing.");
            }

            foreach ($formatDirs as $d) {
                if (!is_dir($d) && !@mkdir($d, 0777, true) && !is_dir($d)) {
                    $this->io->error("Failed to create directory: $d");
                    continue 2;
                }
            }

            $m3uEntries = ['original' => [], 'mp3' => [], 'wav' => [], 'flac' => []];

            foreach ($plEntries as $entry) {
                $matches = glob($originalLibDir.DIRECTORY_SEPARATOR.$entry['id'].' - *.*', GLOB_NOSORT);
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
        $exit = 0;
        $out = [];
        @exec($cmd.' 2>&1', $out, $exit);
        if ($exit !== 0) {
            throw new RuntimeException("Missing dependency: $bin. Please install it and ensure it's in PATH.");
        }
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

    private function runCmd(string $cmd, ?callable $onLine = null): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("Failed to start process: $cmd");
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        while (true) {
            $status = proc_get_status($proc);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if ($out !== false && $out !== '') {
                $stdout .= $out;
                foreach (preg_split('/\R/u', $out) as $line) {
                    if ($line !== '') {
                        $onLine ? $onLine($line) : $this->io->text($line);
                    }
                }
            }
            if ($err !== false && $err !== '') {
                foreach (preg_split('/\R/u', $err) as $line) {
                    if ($line !== '') {
                        $this->io->getErrorStyle()->text($line);
                    }
                }
            }
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        $exitCode = proc_close($proc);

        return [$exitCode, $stdout];
    }

    private static function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim((string)$name);
    }

    private static function readTagsFromInfoJson(string $path): array
    {
        $j = json_decode((string)file_get_contents($path), true);
        if (!is_array($j)) {
            return [];
        }

        return [
            'title' => (string)($j['title'] ?? ''),
            'artist' => (string)($j['uploader'] ?? ($j['artist'] ?? '')),
            'album' => (string)($j['playlist_title'] ?? ($j['album'] ?? '')),
            'genre' => (string)($j['genre'] ?? ''),
            'comment' => (string)($j['description'] ?? ''),
            'date' => (string)($j['upload_date'] ?? ''),
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

        $cmd = [$this->ffmpegBin, '-y', '-nostdin', '-hide_banner', '-loglevel', 'warning', '-i', $sourcePath];
        $hasCover = $coverPath && is_file($coverPath) && in_array($format, ['mp3', 'flac'], true);
        if ($hasCover) {
            $cmd[] = '-i';
            $cmd[] = $coverPath;
        }

        match ($format) {
            'mp3' => $cmd = array_merge($cmd, [
                '-map',
                '0:a:0',
                ...($hasCover ? ['-map', '1:0', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic'] : []),
                '-c:a',
                'libmp3lame',
                '-q:a',
                $this->mp3Quality,
                '-id3v2_version',
                '3',
            ]),
            'flac' => $cmd = array_merge($cmd, [
                '-map',
                '0:a:0',
                ...($hasCover ? ['-map', '1:0', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic'] : []),
                '-c:a',
                'flac',
            ]),
            'wav' => $cmd = array_merge($cmd, ['-map', '0:a:0', '-c:a', 'pcm_s16le']),
            default => null,
        };
        if ($format === null) {
            return null;
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

        $cmdStr = implode(' ', array_map(static fn($p) => escapeshellarg((string)$p), $cmd));
        [$exit] = $this->runCmd($cmdStr, fn() => null);

        return $exit === 0 ? $targetPath : null;
    }

    private static function relativePath(string $from, string $to): string
    {
        $from = str_replace('\\', '/', realpath($from) ?: $from);
        $to = str_replace('\\', '/', realpath($to) ?: $to);
        $fromParts = explode('/', rtrim(is_dir($from) ? $from : dirname($from), '/'));
        $toParts = explode('/', $to);
        while (count($fromParts) && count($toParts) && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromParts)).implode('/', $toParts);
    }

    protected function getConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/soundcloud-download.json';
    }
}
