<?php

declare(strict_types=1);

namespace App\Tests\Playlists;

use App\Playlists\AudioConverter;
use App\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;

final class AudioConverterTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, float|null}>
     */
    public static function audioBitrateKbpsProvider(): array
    {
        return [
            'abr' => [['abr' => 320], 320.0],
            'abr wins over tbr' => [['abr' => 128, 'tbr' => 256], 128.0],
            'tbr fallback' => [['tbr' => 128.5], 128.5],
            'numeric string abr' => [['abr' => '96'], 96.0],
            'zero abr falls back to tbr' => [['abr' => 0, 'tbr' => 64], 64.0],
            'non-numeric' => [['abr' => 'none'], null],
            'empty json' => [[], null],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, ?string}>
     */
    public static function audioCodecProvider(): array
    {
        return [
            'opus' => [['acodec' => 'opus'], 'opus'],
            'mp4a profile' => [['acodec' => 'mp4a.40.2'], 'mp4a.40.2'],
            'none means absent' => [['acodec' => 'none'], null],
            'empty string' => [['acodec' => ''], null],
            'whitespace only' => [['acodec' => '   '], null],
            'non-string' => [['acodec' => 123], null],
            'missing key' => [[], null],
        ];
    }

    /**
     * @return array<string, array{?string, float, float}>
     */
    public static function estimateOdgProvider(): array
    {
        return [
            'mp3 320 anchor' => ['mp3', 320.0, -0.2],
            'mp3 192 anchor' => ['mp3', 192.0, -1.0],
            'mp3 128 anchor' => ['mp3', 128.0, -2.0],
            'mp3 interpolated 224' => ['mp3', 224.0, -0.75],
            'mp3 below lowest anchor' => ['mp3', 32.0, -3.85],
            'mp3 above top clamps to top anchor' => ['mp3', 400.0, -0.2],
            'zero kbps is worst' => ['mp3', 0.0, -4.0],
            'opus 96 anchor' => ['opus', 96.0, -1.0],
            'opus 128 anchor' => ['opus', 128.0, -0.5],
            'aac via mp4a codec string' => ['mp4a.40.2', 128.0, -1.0],
            'vorbis anchor' => ['vorbis', 128.0, -0.8],
            'lossless flac' => ['flac', 900.0, 0.0],
            'lossless pcm' => ['pcm_s16le', 1411.0, 0.0],
            'unknown codec uses mp3 curve' => ['weird-codec', 192.0, -1.0],
            'null codec uses mp3 curve' => [null, 128.0, -2.0],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, array{title: string, artist: string, album: string, genre: string, comment: string, date: string}}>
     */
    public static function infoJsonProvider(): array
    {
        return [
            'full fields' => [
                [
                    'title' => 'T',
                    'uploader' => 'U',
                    'playlist_title' => 'PL',
                    'genre' => 'G',
                    'description' => 'D',
                    'upload_date' => '20190501',
                ],
                [
                    'title' => 'T',
                    'artist' => 'U',
                    'album' => 'PL',
                    'genre' => 'G',
                    'comment' => 'D',
                    'date' => '20190501',
                ],
            ],
            'artist fallback when no uploader' => [
                ['artist' => 'A'],
                ['title' => '', 'artist' => 'A', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'album fallback when no playlist_title' => [
                ['album' => 'AL'],
                ['title' => '', 'artist' => '', 'album' => 'AL', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'artist wins over uploader' => [
                ['uploader' => 'U', 'artist' => 'A'],
                ['title' => '', 'artist' => 'A', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'creator used when no artist' => [
                ['uploader' => 'U', 'creator' => 'C'],
                ['title' => '', 'artist' => 'C', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'uploader used when neither artist nor creator present' => [
                ['uploader' => 'U'],
                ['title' => '', 'artist' => 'U', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'channel used as last resort' => [
                ['channel' => 'Ch'],
                ['title' => '', 'artist' => 'Ch', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'empty uploader falls through to a valid artist' => [
                ['uploader' => '', 'artist' => 'A'],
                ['title' => '', 'artist' => 'A', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'empty json' => [
                [],
                ['title' => '', 'artist' => '', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'double-escaped unicode in artist is repaired' => [
                ['artist' => "Ch\\u00F4K\\u00F4"],
                ['title' => '', 'artist' => 'ChôKô', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'double-escaped unicode in title is repaired too' => [
                ['title' => "Ka\\u00EFros", 'uploader' => 'U'],
                ['title' => 'Kaïros', 'artist' => 'U', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'plain string with a literal backslash but no unicode escape is untouched' => [
                ['artist' => 'AC\\DC'],
                ['title' => '', 'artist' => 'AC\\DC', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
        ];
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function isBelowMinOdgProvider(): array
    {
        return [
            'exact anchor match at threshold is not below' => [-1.0, -1.0, false],
            'real-world bitrate a hair under a nominal anchor still displays as the threshold' => [
                -1.0008,
                -1.0,
                false,
            ],
            'clearly worse than threshold is below' => [-1.05, -1.0, true],
            'clearly better than threshold is not below' => [-0.9, -1.0, false],
            'both sides carry the same sub-cent noise' => [-1.001, -1.001, false],
            'off by a full cent is still below' => [-1.006, -1.0, true],
        ];
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function isBitrateMismatchProvider(): array
    {
        return [
            'exact match' => [320.0, 320.0, false],
            'stale VBR case (168 vs 320)' => [168.0, 320.0, true],
            'small rounding difference' => [319.6, 320.0, false],
            'just under 10% threshold at a 320 target' => [288.1, 320.0, false],
            'just over 10% threshold at a 320 target' => [287.9, 320.0, true],
            'absolute 8kbps floor kicks in below a low target: just under' => [60.0, 64.0, false],
            'absolute 8kbps floor kicks in below a low target: just over' => [55.0, 64.0, true],
        ];
    }

    /**
     * @return array<string, array{string, float, ?float}>
     */
    public static function minKbpsForOdgProvider(): array
    {
        return [
            'mp3 -1.0 inverts to 192' => ['mp3', -1.0, 192.0],
            'mp3 -2.0 inverts to 128' => ['mp3', -2.0, 128.0],
            'opus -1.0 inverts to 96' => ['opus', -1.0, 96.0],
            'aac -0.5 interpolated' => ['aac', -0.5, 176.0],
            'vorbis -1.0 interpolated' => ['vorbis', -1.0, 118.9],
            'mp3 -0.1 unreachable' => ['mp3', -0.1, null],
            'vorbis -0.1 unreachable' => ['vorbis', -0.1, null],
            'worst threshold accepts anything' => ['mp3', -4.0, 0.0],
            'unknown codec family' => ['weird', -1.0, null],
        ];
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function normalizeCodecProvider(): array
    {
        return [
            'null' => [null, 'unknown'],
            'empty' => ['', 'unknown'],
            'none' => ['none', 'unknown'],
            'mp4a profile' => ['mp4a.40.2', 'aac'],
            'aac uppercase' => ['AAC', 'aac'],
            'mp3' => ['mp3', 'mp3'],
            'mp3float (ffprobe)' => ['mp3float', 'mp3'],
            'mpga' => ['mpga', 'mp3'],
            'opus' => ['opus', 'opus'],
            'vorbis' => ['vorbis', 'vorbis'],
            'flac' => ['flac', 'lossless'],
            'alac' => ['alac', 'lossless'],
            'pcm' => ['pcm_s16le', 'lossless'],
            'unknown' => ['weird-codec', 'unknown'],
        ];
    }

    /**
     * @return array<string, array{int, int|null}>
     */
    public static function targetSampleRateProvider(): array
    {
        return [
            'supported 44.1k untouched' => [44100, null],
            'supported 48k untouched' => [48000, null],
            'supported 96k untouched' => [96000, null],
            '32k -> 44.1k' => [32000, 44100],
            '22.05k -> 44.1k' => [22050, 44100],
            '88.2k -> 96k' => [88200, 96000],
            '176.4k -> 96k' => [176400, 96000],
            '192k -> 96k' => [192000, 96000],
            '45k -> 48k' => [45000, 48000],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function titleFromFilenameProvider(): array
    {
        return [
            'id - title' => ['/lib/original/123 - Track One.opus', '123', 'Track One'],
            'title with dashes' => ['/lib/original/123 - A - B.mp3', '123', 'A - B'],
            'id with regex chars' => ['/lib/original/a.b+c - Track.m4a', 'a.b+c', 'Track'],
            'no id prefix keeps basename' => ['/lib/original/Track Only.wav', '999', 'Track Only'],
        ];
    }

    /**
     * @param array<string, mixed> $info
     */
    #[DataProvider('audioBitrateKbpsProvider')]
    public function testAudioBitrateKbps(array $info, ?float $expected): void
    {
        self::assertSame($expected, AudioConverter::audioBitrateKbps($info));
    }

    /**
     * @param array<string, mixed> $info
     */
    #[DataProvider('audioCodecProvider')]
    public function testAudioCodec(array $info, ?string $expected): void
    {
        self::assertSame($expected, AudioConverter::audioCodec($info));
    }

    public function testBuildFfmpegArgsFlacWithCover(): void
    {
        $args = self::buildFfmpegArgs('flac', cover: '/cover.jpg');

        self::assertContains('flac', $args);
        self::assertSame(['-map', '1:0', '-c:v', 'mjpeg', '-disposition:v:0', 'attached_pic'],
            array_slice($args, 12, 6));
        self::assertNotContains('-ar', $args); // rate null → no resample
    }

    /**
     * @param array<string, string> $tags
     * @return list<string>
     */
    private static function buildFfmpegArgs(
        string $format,
        array $tags = [],
        ?string $cover = null,
        ?int $rate = null,
        string $mp3Quality = '0',
        string $mp3Mode = 'vbr',
        string $mp3Bitrate = '320',
    ): array {
        return AudioConverter::buildFfmpegArgs(
            'ffmpeg',
            $mp3Mode,
            $mp3Quality,
            $mp3Bitrate,
            '/src.wav',
            '/out.'.$format,
            $format,
            $tags,
            $cover,
            $rate
        );
    }

    public function testBuildFfmpegArgsMapsAudioOnlyForWavIgnoringCover(): void
    {
        // Regression: a cover stream in a WAV container makes ffmpeg fail. wav must map audio only.
        $args = self::buildFfmpegArgs('wav', cover: '/cover.jpg', rate: 96000);

        self::assertContains('0:a:0', $args);
        self::assertNotContains('1:0', $args);
        self::assertNotContains('-c:v', $args);
        self::assertNotContains('/cover.jpg', $args);
        self::assertSame(['-map', '0:a:0', '-c:a', 'pcm_s16le'], array_slice($args, 8, 4));
        self::assertSame(['-ar', '96000', '/out.wav'], array_slice($args, -3));
    }

    public function testBuildFfmpegArgsMp3CbrUsesBitrateFlag(): void
    {
        $args = self::buildFfmpegArgs('mp3', cover: null, mp3Mode: 'cbr', mp3Bitrate: '256');

        self::assertNotContains('-q:a', $args);
        self::assertSame(['-map', '0:a:0', '-c:a', 'libmp3lame', '-b:a', '256k', '-id3v2_version', '3'],
            array_slice($args, 8, 8));
    }

    public function testBuildFfmpegArgsMp3WithCoverAndMetadata(): void
    {
        $args = self::buildFfmpegArgs(
            'mp3',
            tags: ['title' => 'T', 'artist' => 'A', 'date' => '2019-05-01'],
            cover: '/cover.jpg',
            rate: 44100,
            mp3Quality: '2',
        );

        self::assertSame([
            'ffmpeg',
            '-y',
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            '/src.wav',
            '-i',
            '/cover.jpg',
            '-map',
            '0:a:0',
            '-map',
            '1:0',
            '-c:v',
            'mjpeg',
            '-disposition:v:0',
            'attached_pic',
            '-c:a',
            'libmp3lame',
            '-q:a',
            '2',
            '-id3v2_version',
            '3',
            '-ar',
            '44100',
            '-metadata',
            'title=T',
            '-metadata',
            'artist=A',
            '-metadata',
            'date=2019',
            '-metadata',
            'year=2019',
            '/out.mp3',
        ], $args);
    }

    public function testBuildFfmpegArgsNoCoverWhenNull(): void
    {
        $args = self::buildFfmpegArgs('mp3', cover: null);

        self::assertNotContains('1:0', $args);
        self::assertNotContains('attached_pic', $args);
        self::assertSame(['-map', '0:a:0', '-c:a', 'libmp3lame', '-q:a', '0', '-id3v2_version', '3'],
            array_slice($args, 8, 8));
    }

    public function testBuildFfmpegArgsOmitsEmptyMetadataAndRate(): void
    {
        $args = self::buildFfmpegArgs('wav', tags: ['title' => '', 'artist' => 'A'], rate: null);

        self::assertNotContains('-ar', $args);
        self::assertNotContains('title=', $args);
        self::assertContains('artist=A', $args);
    }

    public function testEnsureConvertedExistingTargetSkipsNonMp3Format(): void
    {
        [$converter, $fake] = self::makeConverter();
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');

        try {
            self::assertSame($target, $converter('/nonexistent/src.wav', $target, 'wav'));
            self::assertSame([], $fake->commands);
        } finally {
            @unlink($target);
        }
    }

    /**
     * @return array{AudioConverter, FakeProcessRunner}
     */
    private static function makeConverter(
        string $mp3Mode = 'cbr',
        string $mp3Quality = '0',
        string $mp3Bitrate = '320',
        bool $reencodeStaleMp3 = true,
    ): array {
        $fake = new FakeProcessRunner();
        $converter = new AudioConverter(
            $fake,
            'ffmpeg',
            'ffprobe',
            $mp3Mode,
            $mp3Quality,
            $mp3Bitrate,
            $reencodeStaleMp3
        );

        return [$converter, $fake];
    }

    public function testEnsureConvertedExistingTargetSkipsWorkWhenBitrateMatches(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=320000\n");
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');

        try {
            self::assertSame($target, $converter('/nonexistent/src.wav', $target, 'mp3'));
            self::assertCount(1, $fake->commands);
            self::assertStringContainsString('ffprobe', $fake->commands[0]);
            self::assertFileExists($target);
        } finally {
            @unlink($target);
        }
    }

    public function testEnsureConvertedExistingTargetSkipsWorkWhenReencodeDisabled(): void
    {
        [$converter, $fake] = self::makeConverter(reencodeStaleMp3: false);
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');

        try {
            self::assertSame($target, $converter('/nonexistent/src.wav', $target, 'mp3'));
            self::assertSame([], $fake->commands);
        } finally {
            @unlink($target);
        }
    }

    public function testEnsureConvertedFfmpegFailureIsNull(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 0, "44100\n");
        $fake->on('ffmpeg', 1, '');
        $source = tempnam(sys_get_temp_dir(), 'sc_test_src_');

        try {
            self::assertNull($converter($source, '/tmp/out.mp3', 'mp3'));
        } finally {
            @unlink($source);
        }
    }

    public function testEnsureConvertedMissingSourceIsNull(): void
    {
        [$converter, $fake] = self::makeConverter();

        self::assertNull($converter('/nonexistent/src.wav', '/nonexistent/out.mp3', 'mp3'));
        self::assertSame([], $fake->commands);
    }

    public function testEnsureConvertedReencodesStaleMp3OnBitrateMismatch(): void
    {
        [$converter, $fake] = self::makeConverter();
        // first ffprobe call: shouldReencode()'s bitrate probe (stale VBR average, far off the 320 target)
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        // second ffprobe call: probeSampleRate() during the reconversion that follows
        $fake->on('ffprobe', 0, "44100\n");
        $source = tempnam(sys_get_temp_dir(), 'sc_test_src_');
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');

        try {
            self::assertSame($target, $converter($source, $target, 'mp3'));
            self::assertCount(3, $fake->commands);
            self::assertStringContainsString('ffprobe', $fake->commands[0]);
            self::assertStringContainsString('ffprobe', $fake->commands[1]);
            self::assertStringContainsString('ffmpeg', $fake->commands[2]);
            // the stale target was deleted before reconversion (FakeProcessRunner never
            // recreates it, so its absence proves unlink() ran)
            self::assertFileDoesNotExist($target);
            self::assertSame([$target], $converter->getReencodedStaleMp3());
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testEnsureConvertedRunsProbeThenFfmpeg(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 0, "22050\n");
        $source = tempnam(sys_get_temp_dir(), 'sc_test_src_');

        try {
            $result = $converter($source, '/tmp/out.mp3', 'mp3', ['title' => 'T'], null);

            self::assertSame('/tmp/out.mp3', $result);
            self::assertCount(2, $fake->commands);
            self::assertStringContainsString('ffprobe', $fake->commands[0]);
            self::assertStringContainsString('ffmpeg', $fake->commands[1]);
            // 22050 Hz is unsupported by the export target → resampled up to 44100
            self::assertStringContainsString("'-ar' '44100'", $fake->commands[1]);
            self::assertStringContainsString("'/tmp/out.mp3'", $fake->commands[1]);
        } finally {
            @unlink($source);
        }
    }

    #[DataProvider('estimateOdgProvider')]
    public function testEstimateOdg(?string $acodec, float $kbps, float $expected): void
    {
        self::assertEqualsWithDelta($expected, AudioConverter::estimateOdg($acodec, $kbps), 0.0001);
    }

    #[DataProvider('isBelowMinOdgProvider')]
    public function testIsBelowMinOdg(float $odg, float $minOdg, bool $expected): void
    {
        self::assertSame($expected, AudioConverter::isBelowMinOdg($odg, $minOdg));
    }

    #[DataProvider('isBitrateMismatchProvider')]
    public function testIsBitrateMismatch(float $actualKbps, float $configuredKbps, bool $expected): void
    {
        self::assertSame($expected, AudioConverter::isBitrateMismatch($actualKbps, $configuredKbps));
    }

    /**
     * @param array<string, mixed> $json
     * @param array{title: string, artist: string, album: string, genre: string, comment: string, date: string} $expected
     */
    #[DataProvider('infoJsonProvider')]
    public function testMapInfoJsonToTags(array $json, array $expected): void
    {
        self::assertSame($expected, AudioConverter::mapInfoJsonToTags($json));
    }

    #[DataProvider('minKbpsForOdgProvider')]
    public function testMinKbpsForOdg(string $codec, float $minOdg, ?float $expected): void
    {
        $actual = AudioConverter::minKbpsForOdg($codec, $minOdg);
        if ($expected === null) {
            self::assertNull($actual);
        } else {
            self::assertNotNull($actual);
            self::assertEqualsWithDelta($expected, $actual, 0.10001);
        }
    }

    public function testMinKbpsForOdgRoundtripNeverAdmitsWorseQuality(): void
    {
        foreach (['mp3', 'aac', 'opus', 'vorbis'] as $codec) {
            foreach ([-0.5, -1.0, -1.5, -2.0, -3.0] as $threshold) {
                $kbps = AudioConverter::minKbpsForOdg($codec, $threshold);
                if ($kbps === null) {
                    continue;
                }
                self::assertGreaterThanOrEqual(
                    $threshold - 1e-9,
                    AudioConverter::estimateOdg($codec, $kbps),
                    "$codec @ $threshold: minKbpsForOdg result must satisfy the threshold"
                );
            }
        }
    }

    #[DataProvider('normalizeCodecProvider')]
    public function testNormalizeCodec(?string $acodec, string $expected): void
    {
        self::assertSame($expected, AudioConverter::normalizeCodec($acodec));
    }

    public function testOdgCalibrationIsMonotonic(): void
    {
        /** @var array<string, list<array{0: float|int, 1: float}>> $table */
        $table = (new ReflectionClassConstant(AudioConverter::class, 'ODG_CALIBRATION'))->getValue();

        self::assertNotSame([], $table);
        foreach ($table as $codec => $anchors) {
            for ($i = 1, $n = count($anchors); $i < $n; $i++) {
                self::assertGreaterThan(
                    $anchors[$i - 1][0],
                    $anchors[$i][0],
                    "$codec: kbps anchors must be strictly increasing"
                );
                self::assertGreaterThanOrEqual(
                    $anchors[$i - 1][1],
                    $anchors[$i][1],
                    "$codec: ODG anchors must be non-decreasing"
                );
            }
        }
    }

    public function testProbeAudioPropertiesCodecOnlyWhenBitrateUnknown(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('bit_rate', 0, "codec_name=opus\nbit_rate=N/A\n");

        self::assertSame([null, 'opus'], $converter->probeAudioProperties(__FILE__));
    }

    public function testProbeAudioPropertiesFallsBackToFormatBitrate(): void
    {
        [$converter, $fake] = self::makeConverter();
        // stream reports N/A (e.g. some containers) → format line is used
        $fake->on('bit_rate', 0, "codec_name=vorbis\nbit_rate=N/A\nbit_rate=320000\n");

        self::assertSame([320.0, 'vorbis'], $converter->probeAudioProperties(__FILE__));
    }

    public function testProbeAudioPropertiesFfprobeFailureIsNull(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('bit_rate', 1, '');

        self::assertSame([null, null], $converter->probeAudioProperties(__FILE__));
    }

    public function testProbeAudioPropertiesMissingFileSkipsProbe(): void
    {
        [$converter, $fake] = self::makeConverter();

        self::assertSame([null, null], $converter->probeAudioProperties('/nonexistent/file.m4a'));
        self::assertSame([], $fake->commands);
    }

    public function testProbeAudioPropertiesParsesStreamBitrateAndCodec(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('bit_rate', 0, "codec_name=mp3\nbit_rate=128000\n");

        self::assertSame([128.0, 'mp3'], $converter->probeAudioProperties(__FILE__));
        self::assertStringContainsString('stream=codec_name,bit_rate:format=bit_rate', $fake->commands[0]);
        self::assertStringContainsString(escapeshellarg(__FILE__), $fake->commands[0]);
    }

    public function testProbeSampleRateFfprobeFailureIsNull(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 1, '');
        $method = new ReflectionMethod(AudioConverter::class, 'probeSampleRate');

        self::assertNull($method->invoke($converter, __FILE__));
    }

    public function testProbeSampleRateMissingFileSkipsProbe(): void
    {
        [$converter, $fake] = self::makeConverter();
        $method = new ReflectionMethod(AudioConverter::class, 'probeSampleRate');

        self::assertNull($method->invoke($converter, '/nonexistent/file.wav'));
        self::assertSame([], $fake->commands);
    }

    public function testProbeSampleRateNonNumericOutputIsNull(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 0, "N/A\n");
        $method = new ReflectionMethod(AudioConverter::class, 'probeSampleRate');

        self::assertNull($method->invoke($converter, __FILE__));
    }

    public function testProbeSampleRateParsesRate(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 0, "44100\n");
        $method = new ReflectionMethod(AudioConverter::class, 'probeSampleRate');

        self::assertSame(44100, $method->invoke($converter, __FILE__));
        self::assertStringContainsString('stream=sample_rate', $fake->commands[0]);
        self::assertStringContainsString(escapeshellarg(__FILE__), $fake->commands[0]);
    }

    public function testShouldReencodeFalseUnderVbrMode(): void
    {
        [$converter, $fake] = self::makeConverter(mp3Mode: 'vbr');
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        $method = new ReflectionMethod(AudioConverter::class, 'shouldReencode');

        self::assertFalse($method->invoke($converter, 'mp3', '/some/target.mp3'));
        self::assertSame([], $fake->commands);
    }

    public function testShouldReencodeFalseWhenFlagDisabled(): void
    {
        [$converter, $fake] = self::makeConverter(reencodeStaleMp3: false);
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        $method = new ReflectionMethod(AudioConverter::class, 'shouldReencode');

        self::assertFalse($method->invoke($converter, 'mp3', '/some/target.mp3'));
        self::assertSame([], $fake->commands);
    }

    public function testShouldReencodeFalseWhenProbeFails(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 1, '');
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(AudioConverter::class, 'shouldReencode');

        try {
            self::assertFalse($method->invoke($converter, 'mp3', $target));
        } finally {
            @unlink($target);
        }
    }

    public function testShouldReencodeTrueOnMismatchedBitrate(): void
    {
        [$converter, $fake] = self::makeConverter();
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(AudioConverter::class, 'shouldReencode');

        try {
            self::assertTrue($method->invoke($converter, 'mp3', $target));
        } finally {
            @unlink($target);
        }
    }

    #[DataProvider('targetSampleRateProvider')]
    public function testTargetSampleRate(int $src, ?int $expected): void
    {
        self::assertSame($expected, AudioConverter::targetSampleRate($src));
    }

    #[DataProvider('titleFromFilenameProvider')]
    public function testTitleFromFilename(string $srcPath, string $id, string $expected): void
    {
        self::assertSame($expected, AudioConverter::titleFromFilename($srcPath, $id));
    }
}
