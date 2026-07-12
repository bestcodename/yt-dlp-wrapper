<?php

declare(strict_types=1);

namespace App\Playlists;

use App\Process\ProcessRunner;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SpotdlDownloader
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $spotdlBin,
        private readonly ?string $spotdlCookieFile,
    ) {
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
    public function __invoke(
        string $url,
        string $originalLibDir,
        string $archiveDir,
        ProgressBar $overallBar,
        array $plEntries,
        SymfonyStyle $io,
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
        [$exit] = $this->runCmd(implode(' ', $cmd), $io, fn() => null);
        $post = self::loadSpotdlArchiveIds($archiveFile);
        $overallBar->advance(count($plEntries));

        return [
            max(0, count($post) - count($pre)),
            DownloadArchive::countArchived($plEntries, $pre),
            $exit,
            DownloadArchive::missingEntries($plEntries, $post),
            // spotdl has no per-format bitrate filter; the quality filter never applies here.
            [],
        ];
    }

    /**
     * Parse a spotdl --archive file (one song URL per line) into a set of Spotify track IDs.
     *
     * @return array<string, true>
     */
    public static function loadSpotdlArchiveIds(string $archiveFile): array
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
     * Last path segment of a Spotify URL (query stripped) or the last colon part of a
     * spotify:...:ID URI — the track/playlist ID. Pure — unit-testable.
     */
    public static function spotifyIdFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $tail = is_string($path) ? basename($path) : '';
        if ($tail === '' || str_contains($tail, ':')) {
            $parts = explode(':', rtrim($url, ':'));
            $tail = (string)end($parts);
        }

        return $tail;
    }

    /**
     * Builds the spotdl download command. The output template is fixed to '{track-id} - {title}'
     * so the conversion loop's `{id} - *.*` glob finds the files (LIB_FILENAME_TEMPLATE applies
     * to yt-dlp sources only); m4a + '--bitrate disable' yields the best quality spotdl offers
     * (256k with YT Music Premium cookies). Pure — unit-testable.
     *
     * @return list<string> fully escaped command parts
     */
    public static function buildSpotdlDownloadCmd(
        string $spotdlBin,
        string $url,
        string $originalLibDir,
        string $archiveFile,
        ?string $cookieFile,
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
     * Spotify pendant to YtDlpDownloader::getPlaylistIdentityAndEntries(): `spotdl save` writes
     * the playlist metadata to a JSON file, which is then mapped onto the same identity tuple.
     *
     * @return array{string, string, string, list<array{id: string, title: string}>, list<array{id: string, title: string}>}
     */
    public function getPlaylistIdentityAndEntries(string $url, string $archiveDir, SymfonyStyle $io): array
    {
        $saveFile = self::spotdlSaveFilePath($archiveDir, $url);
        $cmd = implode(' ', [
            escapeshellcmd($this->spotdlBin),
            escapeshellarg('save'),
            escapeshellarg($url),
            escapeshellarg('--save-file'),
            escapeshellarg($saveFile),
        ]);
        [$exit] = $this->runCmd($cmd, $io, fn() => null);
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
    public static function spotdlSaveFilePath(string $archiveDir, string $url): string
    {
        return $archiveDir.DIRECTORY_SEPARATOR.'spotify-save-'.md5($url).'.spotdl';
    }

    /**
     * Maps decoded .spotdl save data (JSON array of Song dicts written by `spotdl save`) to the
     * [title, id, uploader, entries, skippedPlaylists] tuple YtDlpDownloader's identity method
     * yields. The save file carries no playlist-owner field, so the uploader is always
     * 'Spotify'; it also never contains nested playlists, so skippedPlaylists is always empty.
     * Pure — unit-testable.
     *
     * @param list<array<string, mixed>> $songs
     * @return array{string, string, string, list<array{id: string, title: string}>, list<array{id: string, title: string}>}
     */
    public static function parseSpotdlSaveData(array $songs, string $url): array
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
}
