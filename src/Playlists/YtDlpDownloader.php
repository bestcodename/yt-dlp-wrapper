<?php

declare(strict_types=1);

namespace App\Playlists;

use App\Process\ProcessRunner;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

final class YtDlpDownloader
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $ytDlpBin,
        private readonly ?string $cookiesFile,
        private readonly string $extractorRetries,
        private readonly ?string $limitRate,
        private readonly string $retrySleep,
        private readonly string $sleepRequests,
        private readonly string $minOdgMode,
        private readonly ?float $minOdg,
    ) {
    }

    /**
     * Runs yt-dlp for one playlist URL (SoundCloud/YouTube/...). New downloads are counted via
     * the DONE: --print hook; already-archived entries emit no output at all, so they are
     * counted by snapshotting the archive before the run. Failed entries are those neither
     * present in the pre-run archive nor confirmed via a DONE: line.
     *
     * @param list<array{id: string, title: string}> $plEntries
     * @return array{int, int, int, list<array{id: string, title: string}>, list<array{id: string, title: string}>}
     *         [newCount, archivedCount, exitCode, failedEntries, filteredEntries]
     */
    public function __invoke(
        string $url,
        string $originalLibDir,
        string $libFilenameTemplate,
        string $archiveDir,
        ProgressBar $overallBar,
        array $plEntries,
        SymfonyStyle $io,
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
            // cover art are re-embedded per format by AudioConverter from the
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
            $io,
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
            DownloadArchive::countArchived($plEntries, $preArchivedIds),
            $exit,
            DownloadArchive::missingEntries($plEntries, $preArchivedIds + $doneIds + $filteredIds),
            array_values(array_filter($plEntries, static fn(array $e) => isset($filteredIds[$e['id']]))),
        ];
    }

    /**
     * yt-dlp format selector, optionally constrained to a minimum estimated ODG. yt-dlp
     * cannot compute ODG, so the threshold is inverted per codec into a minimum bitrate
     * (via AudioConverter::minKbpsForOdg) and expressed as one branch per codec prefix;
     * lossless codecs always pass. Uses fail-closed filters ([abr>=X], not [abr>=?X]) and
     * codec prefixes: formats with unknown bitrate or codec are excluded, so nothing
     * silently falls through the threshold. Pure — unit-testable.
     */
    public static function buildFormatSelector(?float $minOdg): string
    {
        if ($minOdg === null) {
            return 'bestaudio/best';
        }
        // best-codec-first: yt-dlp picks the first branch with a matching format
        $codecPrefixes = ['opus' => ['opus'], 'aac' => ['mp4a', 'aac'], 'vorbis' => ['vorbis'], 'mp3' => ['mp3']];
        $thresholds = [];
        foreach ($codecPrefixes as $codec => $prefixes) {
            $kbps = AudioConverter::minKbpsForOdg($codec, $minOdg);
            if ($kbps === null) {
                continue; // codec cannot reach the threshold at any bitrate
            }
            foreach ($prefixes as $prefix) {
                $thresholds[$prefix] = AudioConverter::formatKbps($kbps);
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
     * Parse a yt-dlp --download-archive file into a set of recorded ids.
     * Each line looks like "<extractor> <id>"; the id is the last token.
     *
     * @return array<string, true>
     */
    public static function loadArchiveIds(string $archiveFile): array
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

    private function runCmd(string $cmd, SymfonyStyle $io, ?callable $onLine = null, ?callable $onErrLine = null): array
    {
        return $this->runner->run(
            $cmd,
            function (string $chunk) use ($onLine, $io): void {
                foreach (preg_split('/\R/u', $chunk) as $line) {
                    if ($line !== '') {
                        $onLine ? $onLine($line) : $io->text($line);
                    }
                }
            },
            function (string $chunk) use ($onErrLine, $io): void {
                foreach (preg_split('/\R/u', $chunk) as $line) {
                    if ($line !== '') {
                        if ($onErrLine !== null) {
                            $onErrLine($line);
                        }
                        $io->getErrorStyle()->text($line);
                    }
                }
            },
            true,
        );
    }

    /**
     * Track id from a yt-dlp "Requested format is not available" stderr line, null otherwise.
     * Pure — unit-testable.
     */
    public static function matchFormatUnavailableId(string $line): ?string
    {
        return preg_match('~ERROR:\s+\[[^]]+]\s+(\S+):\s+Requested format is not available~', $line, $m)
            ? $m[1]
            : null;
    }

    /**
     * @return array{string, string, string, list<array{id: string, title: string}>, list<array{id: string, title: string}>}
     */
    public function getPlaylistIdentityAndEntries(string $url, SymfonyStyle $io): array
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
        [$code, $out] = $this->runCmd($cmd, $io, fn() => null);
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
    public static function isNestedPlaylistEntry(array $e): bool
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
}
