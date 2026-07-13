<?php

declare(strict_types=1);

namespace App\Playlists;

use App\Process\ProcessRunner;

final class AudioConverter
{
    private const LOSSLESS_CODECS = ['flac', 'alac', 'wav', 'aiff', 'ape', 'wavpack', 'tak', 'tta'];

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

    /** @var list<string> target paths reencoded this run; reported in a summary, never inline (see __invoke) */
    private array $reencodedStaleMp3 = [];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $ffmpegBin,
        private readonly string $ffprobeBin,
        private readonly string $mp3Mode,
        private readonly string $mp3Quality,
        private readonly string $mp3Bitrate,
        private readonly bool $reencodeStaleMp3,
    ) {
    }

    /**
     * Audio bitrate in kbps from a decoded .info.json, falling back to total bitrate.
     * Pure — unit-testable.
     *
     * @param array<string, mixed> $info
     */
    public static function audioBitrateKbps(array $info): ?float
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
    public static function audioCodec(array $info): ?string
    {
        $v = $info['acodec'] ?? null;

        return is_string($v) && trim($v) !== '' && strcasecmp(trim($v), 'none') !== 0 ? trim($v) : null;
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

    /** Kbps value for display / format selectors: "128" not "128.0". Pure — unit-testable. */
    public static function formatKbps(float $kbps): string
    {
        return rtrim(rtrim(sprintf('%.1f', $kbps), '0'), '.');
    }

    /** ODG value for display: "-1.5", "-0.2", "0". Pure — unit-testable. */
    public static function formatOdg(float $odg): string
    {
        $s = rtrim(rtrim(sprintf('%.2f', $odg), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    /**
     * Whether an estimated ODG counts as below a configured minimum, for the --min-odg warn/filter
     * decision. Compares at the same 2-decimal precision as formatOdg()'s display (and the
     * calibration anchors, which are all exact at 1-2 decimals): a real-world bitrate a hair below
     * a nominal anchor (e.g. a 128 kbps AAC stream actually measured at 127.97 kbps) estimates to
     * an ODG like -1.0008 which is genuinely `< -1.0` yet is indistinguishable from the threshold
     * once rounded for display — without rounding first, such tracks get silently filtered even
     * though they visibly show "est. ODG -1" against a "-1.0" threshold, contradicting the
     * documented inclusive "ODG ≥ min" semantics (see MIN_ODG_TIERS). Pure — unit-testable.
     */
    public static function isBelowMinOdg(float $odg, float $minOdg): bool
    {
        return round($odg, 2) < round($minOdg, 2);
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

    public static function readTagsFromInfoJson(string $path): array
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
    public static function mapInfoJsonToTags(array $json): array
    {
        $tags = [
            'title' => (string)($json['title'] ?? ''),
            'artist' => self::resolveArtistTag($json),
            'album' => (string)($json['playlist_title'] ?? ($json['album'] ?? '')),
            'genre' => (string)($json['genre'] ?? ''),
            'comment' => (string)($json['description'] ?? ''),
            'date' => (string)($json['upload_date'] ?? ''),
        ];

        return array_map(self::repairDoubleEscapedUnicode(...), $tags);
    }

    /**
     * Artist tag priority: explicit music-metadata fields ("artist", "creator" — populated by
     * yt-dlp when a video is Content-ID-matched to a real song) win over the generic
     * uploader/channel name, since a channel is often a label or reposting account, not the
     * performing artist. Falls back to uploader/channel when no such field exists (the common
     * case for SoundCloud and plain YouTube videos). A present-but-empty field is treated as
     * absent so it doesn't block a later, valid one. Pure — unit-testable.
     *
     * @param array<string, mixed> $json
     */
    private static function resolveArtistTag(array $json): string
    {
        foreach (['artist', 'creator', 'uploader', 'channel'] as $key) {
            $v = trim((string)($json[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * Track title recovered from a "{id} - {title}.{ext}" library filename, for entries
     * without a title in the playlist metadata or an .info.json sidecar. Pure — unit-testable.
     */
    public static function titleFromFilename(string $srcPath, string $id): string
    {
        $base = pathinfo($srcPath, PATHINFO_FILENAME);

        return trim((string)preg_replace('/^'.preg_quote($id, '/').'\s*-\s*/', '', $base));
    }

    /**
     * Repairs a double-escaped unicode sequence occasionally present in yt-dlp's SoundCloud
     * metadata: some fields (seen so far: "artist") come through as literally e.g. `ChôKô`
     * — the info.json's OWN JSON escaping decodes correctly, but the source data underneath
     * already contained an extra backslash, so the "ô" that should have become "ô" survives
     * as six literal characters instead. A plain string with no such sequence is returned
     * unchanged. Pure — unit-testable.
     */
    private static function repairDoubleEscapedUnicode(string $value): string
    {
        return (string)preg_replace_callback(
            '/\\\\u([0-9A-Fa-f]{4})/',
            static fn(array $m): string => mb_chr((int)hexdec($m[1]), 'UTF-8'),
            $value
        );
    }

    /**
     * @param array<string, string> $tags
     */
    public function __invoke(
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
            // Never write via io here: this runs mid-loop while overallBar/convBar are actively
            // redrawing, and under a non-decorated output (e.g. `ddev exec`, no pty) ProgressBar's
            // redraw doesn't end with a newline, so an inline message would land glued onto the
            // bar's still-open line. Collected and reported in the end-of-run summary instead.
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
        [$exit] = $this->runCmd($cmdStr);

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
     * Audio bitrate (kbps) and codec name read from the file itself, for tracks without a
     * usable .info.json bitrate (spotdl writes no sidecar). Prefers the audio stream's
     * bit_rate, falls back to the container's (streams in some containers report N/A).
     * Keyed output (default=nw=1) keeps the codec and bitrate lines unambiguous.
     *
     * @return array{0: ?float, 1: ?string} [kbps, codec_name]
     */
    public function probeAudioProperties(string $path): array
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
        [$exit, $out] = $this->runCmd($cmd);
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

    private function runCmd(string $cmd): array
    {
        return $this->runner->run($cmd, fn() => null);
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
        [$exit, $out] = $this->runCmd($cmd);
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
    public static function buildFfmpegArgs(
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

    /**
     * @return list<string>
     */
    public function getReencodedStaleMp3(): array
    {
        return $this->reencodedStaleMp3;
    }
}
