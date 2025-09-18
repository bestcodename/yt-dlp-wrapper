<?php

declare(strict_types=1);

/**
 * SoundCloud (and other sites) playlist downloader for yt-dlp + ffmpeg.
 *
 * Best-practice design:
 * - Download each track's original/bestaudio ONCE into a shared library (with info.json + thumbnail).
 * - Use a single download archive to prevent re-fetch across all playlists.
 * - Convert locally to MP3/WAV/FLAC with ffmpeg (no extra network), embedding metadata/artwork.
 * - Generate per-playlist M3U8s that reference files in the shared library (no duplicates on disk).
 *
 * Default usage (with .env in project root or script directory):
 * ddev exec -s web php /var/www/html/SoundCloudPlaylistDownloader.php
 */

ini_set('memory_limit', '-1');
error_reporting(E_ALL);

// Simple .env loader (no external deps)
function loadDotenv(?string $path = null): void
{
    $path ??= getcwd().DIRECTORY_SEPARATOR.'.env';
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($v !== '' && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            $v = substr($v, 1, -1);
        }
        // Basic variable expansion for ${VAR}
        $v = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/i', static function ($m) {
            return getenv($m[1]) !== false ? (string)getenv($m[1]) : '';
        }, $v);
        putenv("$k=$v");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}

// Load .env (prefer DOTENV_PATH if set, else CWD, then script directory)
$dotenvPath = getenv('DOTENV_PATH') ?: null;
loadDotenv($dotenvPath);
if ($dotenvPath === null) {
    $scriptEnv = dirname(__FILE__).DIRECTORY_SEPARATOR.'.env';
    if (is_file($scriptEnv)) {
        loadDotenv($scriptEnv);
    }
}

function e(string $msg): void
{
    fwrite(STDERR, $msg.PHP_EOL);
}

function o(string $msg): void
{
    fwrite(STDOUT, $msg.PHP_EOL);
}

function requireBinary(string $bin, ?string $versionArg = '--version'): void
{
    $cmd = escapeshellcmd($bin).($versionArg ? ' '.$versionArg : '');
    $exit = 0;
    $out = [];
    @exec($cmd.' 2>&1', $out, $exit);
    if ($exit !== 0) {
        throw new RuntimeException("Missing dependency: {$bin}. Please install it and ensure it's in PATH.");
    }
}

// CLI args (override .env if provided)
$options = getopt('', ['input:', 'out:', 'playlists-dir:']);
$inputFileCli = $options['input'] ?? null;
$baseOutDirCli = $options['out'] ?? null;

// Resolve config from CLI -> ENV -> defaults
$inputFile = $inputFileCli ?: (getenv('INPUT_FILE') ?: null);
$baseOutDir = $baseOutDirCli ?: (getenv('OUTPUT_DIR') ?: null);
$YT_DLP_BIN = getenv('YTDLP_BIN') ?: 'yt-dlp';
$FFMPEG_BIN = getenv('FFMPEG_BIN') ?: 'ffmpeg';
$FORMATS_ENV = getenv('FORMATS') ?: null; // e.g., "original,mp3,flac"
$MP3_QUALITY = getenv('MP3_QUALITY') !== false ? (string)getenv('MP3_QUALITY') : '0';
$ALBUM_FROM = getenv('ALBUM_FROM') ?: 'playlist_title|title';
$COOKIES_FILE = getenv('COOKIES_FILE') ?: null;

// Library and archive for deduplication
$LIBRARY_DIR = getenv('LIBRARY_DIR') ?: null;                   // default: $baseOutDir/library
$ARCHIVE_DIR = getenv('ARCHIVE_DIR') ?: null;                   // default: $baseOutDir/.archive
$LIB_FILENAME_TEMPLATE = getenv('LIB_FILENAME_TEMPLATE') ?: '%(id)s - %(title)s';

// Unified playlists directory (all m3u8s written here directly; skip "original")
$PLAYLISTS_DIR = $options['playlists-dir']
    ??
    (getenv('PLAYLISTS_DIR') ?: ($baseOutDir ? rtrim($baseOutDir, DIRECTORY_SEPARATOR).
        DIRECTORY_SEPARATOR.
        'playlists' : null));

// Retry/backoff and throttling (tune via .env)
$EXTRACTOR_RETRIES = getenv('EXTRACTOR_RETRIES') ?: '10';       // number or "infinite"
$RETRY_SLEEP = getenv('RETRY_SLEEP') ?: 'exp=2:10:120';         // exponential backoff
$SLEEP_REQUESTS_RAW = getenv('SLEEP_REQUESTS') ?: '2';          // "2" or a range like "1-3"
$SLEEP_REQUESTS = (static function (string $raw): string {
    $raw = trim($raw);
    if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[-:]\s*(\d+(?:\.\d+)?)\s*$/', $raw, $m)) {
        $min = (float)$m[1];
        $max = (float)$m[2];
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        $val = $min + (mt_rand() / mt_getrandmax()) * max(0.0, $max - $min);

        return sprintf('%.3f', $val);
    }
    if (is_numeric($raw)) {
        return (string)(float)$raw;
    }

    return '2';
})(
    $SLEEP_REQUESTS_RAW,
);
$LIMIT_RATE = getenv('LIMIT_RATE') ?: null;                     // e.g. "1M" or "500K" (optional)
$PAUSE_BETWEEN = (int)(getenv('PAUSE_BETWEEN') ?: '2');        // seconds to pause between playlists

if (!$inputFile || !$baseOutDir) {
    e('Usage: php SoundCloudPlaylistDownloader.php --input playlists.txt --out ./downloads');
    e('Or configure via .env: INPUT_FILE=..., OUTPUT_DIR=...');
    exit(1);
}
if (!is_file($inputFile)) {
    e("Input file not found: {$inputFile}");
    exit(1);
}
if (!is_dir($baseOutDir)) {
    if (!@mkdir($baseOutDir, 0777, true) && !is_dir($baseOutDir)) {
        e("Failed to create output directory: {$baseOutDir}");
        exit(1);
    }
}
if ($COOKIES_FILE !== null && !is_file($COOKIES_FILE)) {
    e("Cookies file not found: {$COOKIES_FILE}");
    exit(1);
}

// Defaults for library and archive
$LIBRARY_DIR = $LIBRARY_DIR ?: ($baseOutDir.DIRECTORY_SEPARATOR.'library');
$ARCHIVE_DIR = $ARCHIVE_DIR ?: ($baseOutDir.DIRECTORY_SEPARATOR.'.archive');
if (!is_dir($LIBRARY_DIR) && !@mkdir($LIBRARY_DIR, 0777, true)) {
    e("Failed to create library directory: {$LIBRARY_DIR}");
    exit(1);
}
if (!is_dir($ARCHIVE_DIR) && !@mkdir($ARCHIVE_DIR, 0777, true)) {
    e("Failed to create archive directory: {$ARCHIVE_DIR}");
    exit(1);
}
$originalLibDir = $LIBRARY_DIR.DIRECTORY_SEPARATOR.'original';
if (!is_dir($originalLibDir) && !@mkdir($originalLibDir, 0777, true)) {
    e("Failed to create directory: {$originalLibDir}");
    exit(1);
}
// Ensure unified playlists dir exists
if ($PLAYLISTS_DIR && !is_dir($PLAYLISTS_DIR) && !@mkdir($PLAYLISTS_DIR, 0777, true)) {
    e("Failed to create playlists directory: {$PLAYLISTS_DIR}");
    exit(1);
}

// Verify dependencies
try {
    requireBinary($YT_DLP_BIN, '--version');
    requireBinary($FFMPEG_BIN, '-version');
} catch (Throwable $t) {
    e($t->getMessage());
    exit(1);
}

/**
 * Exec a command, stream live output, return [exitCode, allStdout].
 */
function run(string $cmd, ?callable $onLine = null): array
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to start process: {$cmd}");
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
                if ($line !== '' && $onLine) {
                    $onLine($line);
                }
            }
        }
        if ($err !== false && $err !== '') {
            foreach (preg_split('/\R/u', $err) as $line) {
                if ($line !== '') {
                    e($line);
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

/**
 * Portable relative path from one absolute path to another.
 */
function relativePath(string $from, string $to): string
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

/**
 * Read basic tags from yt-dlp .info.json (if present).
 */
function readTagsFromInfoJson(string $infoJsonPath): array
{
    if (!is_file($infoJsonPath)) {
        return [];
    }
    $j = json_decode((string)file_get_contents($infoJsonPath), true);
    if (!is_array($j)) {
        return [];
    }

    return [
        'title' => (string)($j['title'] ?? ''),
        'artist' => (string)($j['uploader'] ?? ($j['artist'] ?? '')),
        'album' => (string)($j['playlist_title'] ?? ($j['album'] ?? '')),
        'genre' => (string)($j['genre'] ?? ''),
        'comment' => (string)($j['description'] ?? ''),
        'date' => (string)($j['upload_date'] ?? ''), // YYYYMMDD
    ];
}

/**
 * Convert source to a target format in the library, if missing.
 * Returns absolute path to target file on success or if it already exists; null on failure.
 */
function ensureConverted(
    string $ffmpegBin,
    string $sourcePath,
    string $targetPath,
    string $format,                // 'mp3'|'wav'|'flac'
    array $tags = [],
    ?string $coverPath = null,
    string $mp3Quality = '0',
): ?string {
    if (is_file($targetPath)) {
        return $targetPath;
    }
    if (!is_file($sourcePath)) {
        return null;
    }

    $cmd = [$ffmpegBin, '-y', '-nostdin', '-hide_banner', '-loglevel', 'warning'];
    $cmd = array_merge($cmd, ['-i', $sourcePath]);

    // Cover only for mp3/flac
    $hasCover = $coverPath && is_file($coverPath) && in_array($format, ['mp3', 'flac'], true);
    if ($hasCover) {
        $cmd = array_merge($cmd, ['-i', $coverPath]);
    }

    // Map and codecs
    if ($format === 'mp3') {
        $cmd = array_merge($cmd, ['-map', '0:a:0']);
        if ($hasCover) {
            $cmd = array_merge($cmd, ['-map', '1:0', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic']);
        }
        $cmd = array_merge($cmd, ['-c:a', 'libmp3lame', '-q:a', $mp3Quality, '-id3v2_version', '3']);
    } elseif ($format === 'flac') {
        $cmd = array_merge($cmd, ['-map', '0:a:0']);
        if ($hasCover) {
            $cmd = array_merge($cmd, ['-map', '1:0', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic']);
        }
        $cmd = array_merge($cmd, ['-c:a', 'flac']);
    } elseif ($format === 'wav') {
        $cmd = array_merge($cmd, ['-map', '0:a:0', '-c:a', 'pcm_s16le']);
    } else {
        return null;
    }

    // Metadata
    $metaMap = [
        'title' => 'title',
        'artist' => 'artist',
        'album' => 'album',
        'genre' => 'genre',
        'comment' => 'comment',
    ];
    foreach ($metaMap as $k => $ff) {
        if (!empty($tags[$k])) {
            $cmd = array_merge($cmd, ['-metadata', $ff.'='.$tags[$k]]);
        }
    }
    // Date/year if available (extract year from YYYYMMDD)
    if (!empty($tags['date'])) {
        $year = preg_match('/^\d{4}/', $tags['date'], $m) ? $m[0] : $tags['date'];
        $cmd = array_merge($cmd, ['-metadata', 'date='.$year, '-metadata', 'year='.$year]);
    }

    // Output
    $cmd[] = $targetPath;

    // Run
    $cmdStr = implode(
        ' ',
        array_map(static fn($p) => is_string($p) && !str_contains($p, ' ') ? $p : escapeshellarg((string)$p), $cmd),
    );
    [$exit] = run($cmdStr);

    return $exit === 0 ? $targetPath : null;
}

/**
 * Fetch playlist JSON once and return identity + entries (ids and titles).
 */
function getPlaylistIdentityAndEntries(string $url): array
{
    global $YT_DLP_BIN, $COOKIES_FILE, $EXTRACTOR_RETRIES, $RETRY_SLEEP, $SLEEP_REQUESTS, $LIMIT_RATE;

    $bin = escapeshellcmd($YT_DLP_BIN);
    $args = ['-J', '--flat-playlist'];
    $args[] = '--extractor-retries';
    $args[] = $EXTRACTOR_RETRIES;
    $args[] = '--retry-sleep';
    $args[] = $RETRY_SLEEP;
    $args[] = '--sleep-requests';
    $args[] = $SLEEP_REQUESTS;
    if ($LIMIT_RATE) {
        $args[] = '--limit-rate';
        $args[] = $LIMIT_RATE;
    }
    if ($COOKIES_FILE) {
        $args[] = '--cookies';
        $args[] = $COOKIES_FILE;
    }
    $args[] = $url;

    $cmd = $bin.' '.implode(' ', array_map('escapeshellarg', $args));
    [$code, $out] = run($cmd);
    if ($code !== 0) {
        throw new RuntimeException("Failed to query playlist info for URL: {$url}");
    }
    $json = json_decode($out, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from yt-dlp for URL: {$url}");
    }

    $title = $json['title'] ?? 'Playlist';
    $id = $json['id'] ?? md5($url);
    $uploader = $json['uploader'] ?? ($json['channel'] ?? 'SoundCloud');

    $entries = [];
    if (!empty($json['entries']) && is_array($json['entries'])) {
        foreach ($json['entries'] as $e) {
            $tid = (string)($e['id'] ?? '');
            $ttitle = (string)($e['title'] ?? '');
            if ($tid !== '') {
                $entries[] = ['id' => $tid, 'title' => $ttitle];
            }
        }
    }

    return [$title, $id, $uploader, $entries];
}

// Read URLs
$urls = array_values(array_filter(array_map('trim', file($inputFile)), fn($l) => $l !== '' && $l[0] !== '#'));
if (!$urls) {
    e("No URLs found in {$inputFile}");
    exit(1);
}

o('Starting downloads...');
foreach ($urls as $urlIdx => $url) {
    o(str_repeat('-', 60));
    o(sprintf('[%d/%d] Processing: %s', $urlIdx + 1, count($urls), $url));

    // Identify playlist + entries (ids, titles)
    try {
        [$plTitle, $plId, $plUploader, $plEntries] = getPlaylistIdentityAndEntries($url);
    } catch (Throwable $t) {
        e($t->getMessage());
        continue;
    }

    $plFolder = safeName(sprintf('%s - %s [%s]', $plUploader, $plTitle, $plId));
    o("Playlist: {$plFolder}");

    // Ensure per-playlist dir exists (kept for organization; playlists will be written to $PLAYLISTS_DIR)
    $playlistDir = rtrim($baseOutDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$plFolder;
    if (!is_dir($playlistDir) && !@mkdir($playlistDir, 0777, true)) {
        e("Failed to create directory: {$playlistDir}");
        continue;
    }

    // Anti-429 and sidecar options for ORIGINAL ONLY
    $commonArgs = [
        '--no-overwrites',
        '--continue',
        '--ignore-errors',
        '--no-abort-on-error',
        '--yes-playlist',
        '--add-metadata',
        '--extractor-retries',
        $EXTRACTOR_RETRIES,
        '--retry-sleep',
        $RETRY_SLEEP,
        '--sleep-requests',
        $SLEEP_REQUESTS,
        '--write-info-json',
        '--write-thumbnail',
        '--convert-thumbnails',
        'jpg',
    ];
    if ($LIMIT_RATE) {
        $commonArgs[] = '--limit-rate';
        $commonArgs[] = $LIMIT_RATE;
    }

    // Always download original/bestaudio into library/original using a single archive
    $archiveFile = $ARCHIVE_DIR.DIRECTORY_SEPARATOR.'original.txt';
    $originalOutTpl = $originalLibDir.DIRECTORY_SEPARATOR.$LIB_FILENAME_TEMPLATE.'.%(ext)s';
    $originalOutTpl = str_replace(DIRECTORY_SEPARATOR, '/', $originalOutTpl);
    $dlArgs = ['-f', 'bestaudio/best'];
    if ($COOKIES_FILE) {
        $dlArgs[] = '--cookies';
        $dlArgs[] = $COOKIES_FILE;
    }

    $ytCmd = [
        escapeshellcmd($YT_DLP_BIN),
        ...array_map('escapeshellarg', $commonArgs),
        ...array_map('escapeshellarg', ['--download-archive', $archiveFile]),
        ...array_map('escapeshellarg', ['--output', $originalOutTpl]),
        ...array_map('escapeshellarg', $dlArgs),
        // Print both expected template and final path (for archive/skipped cases)
        '--print',
        'filename',
        '--print',
        'after_move:filepath',
        escapeshellarg($url),
    ];
    $ytCmdStr = implode(' ', $ytCmd);

    o('Downloading originals (single pass)...');
    // We rely on entries to build playlist even if yt-dlp prints nothing due to archive skips.
    [$exit] = run($ytCmdStr);
    if ($exit !== 0) {
        e("yt-dlp exited with code {$exit} for originals; continuing.");
    }

    // Determine requested formats
    $formatsRequested = $FORMATS_ENV ? array_filter(array_map('trim', explode(',', $FORMATS_ENV))) : [
        'original',
        'mp3',
        'wav',
        'flac',
    ];
    $wantOriginal = in_array('original', $formatsRequested, true);
    $wantMp3 = in_array('mp3', $formatsRequested, true);
    $wantWav = in_array('wav', $formatsRequested, true);
    $wantFlac = in_array('flac', $formatsRequested, true);

    // Ensure per-format library dirs
    $formatDirs = [
        'mp3' => $LIBRARY_DIR.DIRECTORY_SEPARATOR.'mp3',
        'wav' => $LIBRARY_DIR.DIRECTORY_SEPARATOR.'wav',
        'flac' => $LIBRARY_DIR.DIRECTORY_SEPARATOR.'flac',
    ];
    foreach ($formatDirs as $d) {
        if (!is_dir($d) && !@mkdir($d, 0777, true)) {
            e("Failed to create directory: {$d}");
            continue 2;
        }
    }

    // Prepare M3U collectors
    $m3uEntries = [
        'original' => [],
        'mp3' => [],
        'wav' => [],
        'flac' => [],
    ];

    // For each entry id, locate existing original file: "<id> - *.ext"
    foreach ($plEntries as $entry) {
        $tid = $entry['id'];
        $pattern = $originalLibDir.DIRECTORY_SEPARATOR.$tid.' - '.'*.*';
        $matches = glob($pattern, GLOB_NOSORT);
        if (!$matches || !is_file($matches[0])) {
            // Not found yet (maybe failed download); skip
            continue;
        }
        $srcPath = str_replace('\\', '/', realpath($matches[0]) ?: $matches[0]);

        // Sidecars
        $infoJson = preg_replace('/\.\w+$/', '.info.json', $srcPath);
        $coverJpg = preg_replace('/\.\w+$/', '.jpg', $srcPath);
        $tags = is_file($infoJson) ? readTagsFromInfoJson($infoJson) : [];

        // M3U: original (collected but we won't write an original.m3u8)
        if ($wantOriginal) {
            $m3uEntries['original'][] = $srcPath;
        }

        // Convert locally if needed
        if ($wantMp3) {
            $mp3Path = $formatDirs['mp3'].DIRECTORY_SEPARATOR.pathinfo($srcPath, PATHINFO_FILENAME).'.mp3';
            $made = ensureConverted(
                $FFMPEG_BIN,
                $srcPath,
                $mp3Path,
                'mp3',
                $tags,
                is_file($coverJpg) ? $coverJpg : null,
                $MP3_QUALITY,
            );
            if ($made) {
                $m3uEntries['mp3'][] = str_replace('\\', '/', realpath($made) ?: $made);
            }
        }
        if ($wantWav) {
            $wavPath = $formatDirs['wav'].DIRECTORY_SEPARATOR.pathinfo($srcPath, PATHINFO_FILENAME).'.wav';
            $made = ensureConverted($FFMPEG_BIN, $srcPath, $wavPath, 'wav', $tags, null, $MP3_QUALITY);
            if ($made) {
                $m3uEntries['wav'][] = str_replace('\\', '/', realpath($made) ?: $made);
            }
        }
        if ($wantFlac) {
            $flacPath = $formatDirs['flac'].DIRECTORY_SEPARATOR.pathinfo($srcPath, PATHINFO_FILENAME).'.flac';
            $made = ensureConverted(
                $FFMPEG_BIN,
                $srcPath,
                $flacPath,
                'flac',
                $tags,
                is_file($coverJpg) ? $coverJpg : null,
                $MP3_QUALITY,
            );
            if ($made) {
                $m3uEntries['flac'][] = str_replace('\\', '/', realpath($made) ?: $made);
            }
        }
    }

    // Write per-format M3Us directly into unified playlists directory, skip "original"
    foreach (['mp3', 'wav', 'flac'] as $fmt) {
        if (!in_array($fmt, $formatsRequested, true)) {
            continue;
        }
        $list = $m3uEntries[$fmt];
        natsort($list);
        $list = array_values($list);

        $m3uDir = $PLAYLISTS_DIR ?: $playlistDir;
        $m3uPath = rtrim($m3uDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR."{$plFolder} - {$fmt}.m3u8";

        $m3u = "#EXTM3U\n";
        foreach ($list as $abs) {
            $rel = relativePath($m3uDir, $abs);
            $rel = str_replace('\\', '/', $rel);
            $m3u .= $rel."\n";
        }
        file_put_contents($m3uPath, $m3u);
        o("Wrote playlist: {$m3uPath} (".count($list).' entries)');
    }

    o("Completed: {$plFolder}");
    if ($PAUSE_BETWEEN > 0) {
        sleep($PAUSE_BETWEEN);
    }
}

o(str_repeat('=', 60));
o('All done.');

/**
 * Sanitize a string for filesystem paths.
 */
function safeName(string $name): string
{
    $name = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $name);
    $name = preg_replace('/\s+/', ' ', $name);

    return trim((string)$name);
}
