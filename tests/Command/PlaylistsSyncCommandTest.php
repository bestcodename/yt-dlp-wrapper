<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PlaylistsSyncCommand;
use App\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PlaylistsSyncCommandTest extends TestCase
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
     * @return array<string, array{string, string}>
     */
    public static function classifySourceUrlProvider(): array
    {
        return [
            'spotify playlist' => ['https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M', 'spotify'],
            'spotify album' => ['https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy', 'spotify'],
            'spotify track' => ['https://open.spotify.com/track/11dFghVXANMlKmJXsNCbNl', 'spotify'],
            'spotify with si query' => ['https://open.spotify.com/playlist/37i9dQZF1DX?si=abc123', 'spotify'],
            'spotify intl segment' => ['https://open.spotify.com/intl-de/track/11dFghVXANMlKmJXsNCbNl', 'spotify'],
            'spotify uri' => ['spotify:playlist:37i9dQZF1DXcBWIGoYBM5M', 'spotify'],
            'plain http spotify' => ['http://open.spotify.com/playlist/37i9dQZF1DX', 'spotify'],
            'spotify artist is not downloadable' => ['https://open.spotify.com/artist/0OdUWJ0sBjDrqHygGUXeCF', 'ytdlp'],
            'soundcloud' => ['https://soundcloud.com/dj/sets/my-list', 'ytdlp'],
            'youtube' => ['https://www.youtube.com/playlist?list=PL123', 'ytdlp'],
            'garbage' => ['not-a-url', 'ytdlp'],
            'empty' => ['', 'ytdlp'],
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
     * @return array<string, array{string, string|null}>
     */
    public static function formatUnavailableLineProvider(): array
    {
        return [
            'soundcloud entry' => [
                'ERROR: [soundcloud] 123456: Requested format is not available. '
                .'Use --list-formats for a list of available formats',
                '123456',
            ],
            'youtube entry' => [
                'ERROR: [youtube] dQw4w9WgXcQ: Requested format is not available',
                'dQw4w9WgXcQ',
            ],
            'other error' => ['ERROR: [soundcloud] 123456: Unable to download JSON metadata', null],
            'plain warning' => ['WARNING: unable to obtain file audio codec with ffprobe', null],
            'unrelated text' => ['Requested format is not available', null],
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
            'uploader wins over artist' => [
                ['uploader' => 'U', 'artist' => 'A'],
                ['title' => '', 'artist' => 'U', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
            'empty json' => [
                [],
                ['title' => '', 'artist' => '', 'album' => '', 'genre' => '', 'comment' => '', 'date' => ''],
            ],
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
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function nestedPlaylistEntryProvider(): array
    {
        return [
            'type playlist' => [['_type' => 'playlist', 'id' => '1', 'title' => 'Set'], true],
            'soundcloud set url' => [
                [
                    '_type' => 'url',
                    'id' => '949270006',
                    'title' => 'Tekno Collection',
                    'url' => 'https://soundcloud.com/dj/sets/tekno',
                ],
                true,
            ],
            'playlist ie_key' => [['ie_key' => 'YoutubeTab', 'id' => 'PL1', 'title' => 'Mix'], true],
            'plain soundcloud track' => [
                [
                    '_type' => 'url',
                    'ie_key' => 'Soundcloud',
                    'id' => '123',
                    'title' => 'Track',
                    'url' => 'https://soundcloud.com/dj/track-one',
                ],
                false,
            ],
            'entry without url' => [['id' => '123', 'title' => 'Track'], false],
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
     * @return array<string, array{?string}>
     */
    public static function parseFormatsAnswerInvalidProvider(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'only commas' => [',,'],
            'unknown format' => ['mp3,ogg'],
            'typo' => ['orig'],
        ];
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function parseFormatsAnswerProvider(): array
    {
        return [
            'all four' => ['original,mp3,wav,flac', 'original,mp3,wav,flac'],
            'single' => ['mp3', 'mp3'],
            'spaces around entries' => [' mp3 , wav ', 'mp3,wav'],
            'dedups repeats' => ['mp3,mp3,wav', 'mp3,wav'],
            'trailing comma' => ['mp3,wav,', 'mp3,wav'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parseMinOdgAnswerInvalidProvider(): array
    {
        return [
            'letters' => ['abc'],
            'positive kbps-style value' => ['128'],
            'positive small' => ['5'],
            'below scale' => ['-4.5'],
            'trailing junk' => ['-1x'],
        ];
    }

    /**
     * @return array<string, array{?string, ?float}>
     */
    public static function parseMinOdgAnswerProvider(): array
    {
        return [
            'empty = off' => ['', null],
            'null = off' => [null, null],
            'dash = off' => ['-', null],
            'off keyword' => ['off', null],
            'off keyword uppercase' => ['OFF', null],
            'tier 1 pro' => ['1', -0.2],
            'tier 2 semi-pro' => ['2', -1.0],
            'tier 3 preview' => ['3', -2.0],
            'tier 4 off' => ['4', null],
            'custom odg' => ['-1.5', -1.5],
            'custom odg with spaces' => ['  -0.5  ', -0.5],
            'unicode minus' => ['−1.5', -1.5],
            'zero = lossless only' => ['0', 0.0],
            'lower bound' => ['-4', -4.0],
        ];
    }

    /**
     * @return array<string, array{list<array<string, mixed>>, string, array{string, string, string, list<array{id: string, title: string}>, list<array{id: string, title: string}>}}>
     */
    public static function parseSpotdlSaveDataProvider(): array
    {
        $playlistUrl = 'https://open.spotify.com/playlist/pl99';
        $songs = [
            [
                'name' => 'Spot Track',
                'artists' => ['Some Artist'],
                'artist' => 'Some Artist',
                'song_id' => 'sp123',
                'url' => 'https://open.spotify.com/track/sp123',
                'list_name' => 'My Spotify List',
                'list_url' => $playlistUrl,
                'list_position' => 1,
                'list_length' => 2,
            ],
            [
                'name' => 'Other Track',
                'artists' => ['Other Artist'],
                'artist' => 'Other Artist',
                'song_id' => 'sp456',
                'url' => 'https://open.spotify.com/track/sp456',
                'list_name' => 'My Spotify List',
                'list_url' => $playlistUrl,
                'list_position' => 2,
                'list_length' => 2,
            ],
        ];

        return [
            'playlist' => [
                $songs,
                $playlistUrl,
                [
                    'My Spotify List',
                    'pl99',
                    'Spotify',
                    [['id' => 'sp123', 'title' => 'Spot Track'], ['id' => 'sp456', 'title' => 'Other Track']],
                    [],
                ],
            ],
            'single track falls back to track name' => [
                [array_merge($songs[0], ['list_name' => null, 'list_url' => null])],
                'https://open.spotify.com/track/sp123',
                ['Spot Track', 'sp123', 'Spotify', [['id' => 'sp123', 'title' => 'Spot Track']], []],
            ],
            'missing song_id falls back to url tail' => [
                [array_merge($songs[0], ['song_id' => null])],
                $playlistUrl,
                ['My Spotify List', 'pl99', 'Spotify', [['id' => 'sp123', 'title' => 'Spot Track']], []],
            ],
            'song without id or url is skipped' => [
                [array_merge($songs[0], ['song_id' => null, 'url' => null]), $songs[1]],
                $playlistUrl,
                ['My Spotify List', 'pl99', 'Spotify', [['id' => 'sp456', 'title' => 'Other Track']], []],
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function relativeFromPartsProvider(): array
    {
        return [
            'sibling dirs' => ['/a/b/playlists', '/a/b/library/mp3/x.mp3', '../library/mp3/x.mp3'],
            'target nested under from' => ['/a/b', '/a/b/c/x.mp3', 'c/x.mp3'],
            'target above from' => ['/a/b/c', '/a/x.mp3', '../../x.mp3'],
            'identical dir' => ['/a/b', '/a/b', ''],
            'no common prefix' => ['/x/y', '/a/b.mp3', '../../a/b.mp3'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function safeNameProvider(): array
    {
        return [
            'strips slashes' => ['Artist / Title', 'Artist _ Title'],
            'collapses whitespace' => ['a    b', 'a b'],
            'trims edges' => ['  hello  ', 'hello'],
            'keeps unicode letters' => ['Björk – Jóga', 'Björk _ Jóga'],
        ];
    }

    /**
     * @return array<string, array{string, float, float}>
     */
    public static function sleepRequestsProvider(): array
    {
        return [
            'fixed numeric' => ['3', 3.0, 3.0],
            'range stays within bounds' => ['2-5', 2.0, 5.0],
            'reversed range normalizes' => ['5-2', 2.0, 5.0],
            'colon range' => ['1:4', 1.0, 4.0],
            'garbage falls back to 2' => ['nonsense', 2.0, 2.0],
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
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'audioBitrateKbps');

        self::assertSame($expected, $method->invoke(null, $info));
    }

    /**
     * @param array<string, mixed> $info
     */
    #[DataProvider('audioCodecProvider')]
    public function testAudioCodec(array $info, ?string $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'audioCodec');

        self::assertSame($expected, $method->invoke(null, $info));
    }

    public function testBarOutputForcesConsoleOutputOntoStdout(): void
    {
        $output = new ConsoleOutput();
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'barOutput');

        $result = $method->invoke(null, $output);

        self::assertNotSame($output, $result);
        self::assertInstanceOf(StreamOutput::class, $result);
        self::assertSame($output->getVerbosity(), $result->getVerbosity());
        self::assertSame($output->isDecorated(), $result->isDecorated());
    }

    /**
     * ProgressBar silently redirects to getErrorOutput() for any ConsoleOutputInterface (see
     * barOutput() docblock) — that's the real console app path, where barOutput() must return a
     * distinct stream forced onto stdout. Test doubles (BufferedOutput, StreamOutput as used by
     * CommandTester) are not ConsoleOutputInterface, so ProgressBar never redirects for them —
     * barOutput() must return the exact same instance there, unchanged.
     */
    public function testBarOutputPassesThroughNonConsoleOutput(): void
    {
        $output = new BufferedOutput();
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'barOutput');

        self::assertSame($output, $method->invoke(null, $output));
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
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'buildFfmpegArgs');

        /** @var list<string> $args */
        $args = $method->invoke(
            null,
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

        return $args;
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

    public function testBuildFormatSelectorInvertsThresholdPerCodec(): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'buildFormatSelector');

        self::assertSame(
            'bestaudio[acodec^=flac]/bestaudio[acodec^=alac]/bestaudio[acodec^=pcm]'
            .'/bestaudio[acodec^=opus][abr>=96]/bestaudio[acodec^=mp4a][abr>=128]/bestaudio[acodec^=aac][abr>=128]'
            .'/bestaudio[acodec^=vorbis][abr>=118.9]/bestaudio[acodec^=mp3][abr>=192]'
            .'/bestaudio[acodec^=opus][tbr>=96]/bestaudio[acodec^=mp4a][tbr>=128]/bestaudio[acodec^=aac][tbr>=128]'
            .'/bestaudio[acodec^=vorbis][tbr>=118.9]/bestaudio[acodec^=mp3][tbr>=192]'
            .'/best[acodec^=flac]/best[acodec^=alac]/best[acodec^=pcm]'
            .'/best[acodec^=opus][abr>=96]/best[acodec^=mp4a][abr>=128]/best[acodec^=aac][abr>=128]'
            .'/best[acodec^=vorbis][abr>=118.9]/best[acodec^=mp3][abr>=192]'
            .'/best[acodec^=opus][tbr>=96]/best[acodec^=mp4a][tbr>=128]/best[acodec^=aac][tbr>=128]'
            .'/best[acodec^=vorbis][tbr>=118.9]/best[acodec^=mp3][tbr>=192]',
            $method->invoke(null, -1.0)
        );
    }

    public function testBuildFormatSelectorNoThreshold(): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'buildFormatSelector');

        self::assertSame('bestaudio/best', $method->invoke(null, null));
    }

    public function testBuildFormatSelectorOmitsCodecsThatCannotReachThreshold(): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'buildFormatSelector');

        /** @var string $selector */
        $selector = $method->invoke(null, -0.1);

        // mp3 (top anchor -0.2) and vorbis (-0.15) cannot reach -0.1 at any bitrate → fail-closed omission
        self::assertStringNotContainsString('mp3', $selector);
        self::assertStringNotContainsString('vorbis', $selector);
        self::assertStringContainsString('bestaudio[acodec^=opus][abr>=', $selector);
        self::assertStringContainsString('bestaudio[acodec^=mp4a][abr>=', $selector);
        self::assertStringContainsString('bestaudio[acodec^=flac]', $selector);
    }

    public function testBuildSpotdlDownloadCmd(): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'buildSpotdlDownloadCmd');

        $withCookie = $method->invoke(
            null,
            'spotdl',
            'https://open.spotify.com/playlist/pl99',
            '/lib/original',
            '/arch/spotify.txt',
            '/cfg/spotdl-cookies.txt'
        );
        self::assertSame([
            'spotdl',
            "'download'",
            "'https://open.spotify.com/playlist/pl99'",
            "'--output'",
            "'/lib/original/{track-id} - {title}.{output-ext}'",
            "'--format'",
            "'m4a'",
            "'--bitrate'",
            "'disable'",
            "'--archive'",
            "'/arch/spotify.txt'",
            "'--cookie-file'",
            "'/cfg/spotdl-cookies.txt'",
        ], $withCookie);

        $withoutCookie = $method->invoke(
            null,
            'spotdl',
            'https://open.spotify.com/playlist/pl99',
            '/lib/original',
            '/arch/spotify.txt',
            null
        );
        self::assertNotContains("'--cookie-file'", $withoutCookie);
    }

    #[DataProvider('classifySourceUrlProvider')]
    public function testClassifySourceUrl(string $url, string $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::classifySourceUrl($url));
    }

    public function testCountArchivedCountsOnlyKnownIds(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
            ['id' => '25970122', 'title' => 'B'],
            ['id' => '999275440', 'title' => 'C'],
        ];
        $archivedIds = ['30523920' => true, '25970122' => true, 'unrelated' => true];

        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'countArchived');

        self::assertSame(2, $method->invoke(null, $entries, $archivedIds));
    }

    public function testCountArchivedEmptyArchiveIsZero(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
        ];

        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'countArchived');

        self::assertSame(0, $method->invoke(null, $entries, []));
    }

    public function testEnsureConvertedExistingTargetSkipsNonMp3Format(): void
    {
        [$command, $fake] = self::makeCommand();
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'ensureConverted');

        try {
            self::assertSame($target, $method->invoke($command, '/nonexistent/src.wav', $target, 'wav'));
            self::assertSame([], $fake->commands);
        } finally {
            @unlink($target);
        }
    }

    public function testEnsureConvertedExistingTargetSkipsWorkWhenBitrateMatches(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=320000\n");
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'ensureConverted');

        try {
            self::assertSame($target, $method->invoke($command, '/nonexistent/src.wav', $target, 'mp3'));
            self::assertCount(1, $fake->commands);
            self::assertStringContainsString('ffprobe', $fake->commands[0]);
            self::assertFileExists($target);
        } finally {
            @unlink($target);
        }
    }

    public function testEnsureConvertedExistingTargetSkipsWorkWhenReencodeDisabled(): void
    {
        [$command, $fake] = self::makeCommand();
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'reencodeStaleMp3'))->setValue($command, false);
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'ensureConverted');

        try {
            self::assertSame($target, $method->invoke($command, '/nonexistent/src.wav', $target, 'mp3'));
            self::assertSame([], $fake->commands);
        } finally {
            @unlink($target);
        }
    }

    /**
     * @return array{PlaylistsSyncCommand, FakeProcessRunner}
     */
    private static function makeCommand(): array
    {
        $fake = new FakeProcessRunner();
        $command = new PlaylistsSyncCommand($fake);
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'ffprobeBin'))->setValue($command, 'ffprobe');
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'ffmpegBin'))->setValue($command, 'ffmpeg');
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'mp3Quality'))->setValue($command, '0');
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'mp3Mode'))->setValue($command, 'cbr');
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'mp3Bitrate'))->setValue($command, '320');
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'reencodeStaleMp3'))->setValue($command, true);
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'io'))->setValue($command, $io);

        return [$command, $fake];
    }

    public function testEnsureConvertedFfmpegFailureIsNull(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 0, "44100\n");
        $fake->on('ffmpeg', 1, '');
        $source = tempnam(sys_get_temp_dir(), 'sc_test_src_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'ensureConverted');

        try {
            self::assertNull($method->invoke($command, $source, '/tmp/out.mp3', 'mp3'));
        } finally {
            @unlink($source);
        }
    }

    public function testEnsureConvertedMissingSourceIsNull(): void
    {
        [$command, $fake] = self::makeCommand();
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'ensureConverted');

        self::assertNull($method->invoke($command, '/nonexistent/src.wav', '/nonexistent/out.mp3', 'mp3'));
        self::assertSame([], $fake->commands);
    }

    public function testEnsureConvertedReencodesStaleMp3OnBitrateMismatch(): void
    {
        [$command, $fake] = self::makeCommand();
        // first ffprobe call: shouldReencode()'s bitrate probe (stale VBR average, far off the 320 target)
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        // second ffprobe call: probeSampleRate() during the reconversion that follows
        $fake->on('ffprobe', 0, "44100\n");
        $source = tempnam(sys_get_temp_dir(), 'sc_test_src_');
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'ensureConverted');

        try {
            self::assertSame($target, $method->invoke($command, $source, $target, 'mp3'));
            self::assertCount(3, $fake->commands);
            self::assertStringContainsString('ffprobe', $fake->commands[0]);
            self::assertStringContainsString('ffprobe', $fake->commands[1]);
            self::assertStringContainsString('ffmpeg', $fake->commands[2]);
            // the stale target was deleted before reconversion (FakeProcessRunner never
            // recreates it, so its absence proves unlink() ran)
            self::assertFileDoesNotExist($target);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testEnsureConvertedRunsProbeThenFfmpeg(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 0, "22050\n");
        $source = tempnam(sys_get_temp_dir(), 'sc_test_src_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'ensureConverted');

        try {
            $result = $method->invoke($command, $source, '/tmp/out.mp3', 'mp3', ['title' => 'T'], null);

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
        self::assertEqualsWithDelta($expected, PlaylistsSyncCommand::estimateOdg($acodec, $kbps), 0.0001);
    }

    public function testGetSpotifyPlaylistInfoFailureThrows(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on("'save'", 1, '');
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'spotdlBin'))->setValue($command, 'spotdl');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'getSpotifyPlaylistIdentityAndEntries');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to query Spotify playlist info/');
        $method->invoke($command, 'https://open.spotify.com/playlist/pl99', sys_get_temp_dir());
    }

    public function testGetSpotifyPlaylistInfoMissingSaveFileThrows(): void
    {
        [$command, $fake] = self::makeCommand();
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'spotdlBin'))->setValue($command, 'spotdl');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'getSpotifyPlaylistIdentityAndEntries');

        // exit 0 (default fake rule) but no save file was created
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to query Spotify playlist info/');
        $method->invoke($command, 'https://open.spotify.com/playlist/missing', sys_get_temp_dir());
    }

    public function testGetSpotifyPlaylistInfoReadsSaveFile(): void
    {
        [$command, $fake] = self::makeCommand();
        $archiveDir = sys_get_temp_dir().'/sc_test_spotdl_'.uniqid('', true);
        mkdir($archiveDir, 0777, true);
        $url = 'https://open.spotify.com/playlist/pl99';
        $saveFile = $archiveDir.'/spotify-save-'.md5($url).'.spotdl';
        file_put_contents($saveFile, json_encode([
            [
                'name' => 'Spot Track',
                'song_id' => 'sp123',
                'url' => 'https://open.spotify.com/track/sp123',
                'list_name' => 'My Spotify List',
            ],
        ]));
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'spotdlBin'))->setValue($command, 'spotdl');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'getSpotifyPlaylistIdentityAndEntries');

        try {
            $result = $method->invoke($command, $url, $archiveDir);

            self::assertSame(
                ['My Spotify List', 'pl99', 'Spotify', [['id' => 'sp123', 'title' => 'Spot Track']], []],
                $result
            );
            self::assertTrue($fake->ran("'save'"));
            self::assertTrue($fake->ran($saveFile));
        } finally {
            @unlink($saveFile);
            @rmdir($archiveDir);
        }
    }

    #[DataProvider('isBitrateMismatchProvider')]
    public function testIsBitrateMismatch(float $actualKbps, float $configuredKbps, bool $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::isBitrateMismatch($actualKbps, $configuredKbps));
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('nestedPlaylistEntryProvider')]
    public function testIsNestedPlaylistEntry(array $entry, bool $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'isNestedPlaylistEntry');

        self::assertSame($expected, $method->invoke(null, $entry));
    }

    public function testLoadArchiveIdsMissingFileIsEmpty(): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'loadArchiveIds');

        self::assertSame([], $method->invoke(null, '/nonexistent/archive/original.txt'));
    }

    public function testLoadArchiveIdsParsesLastToken(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arch');
        file_put_contents(
            $file,
            "soundcloud 30523920\nsoundcloud 25970122\n\n  youtube dQw4w9WgXcQ  \n"
        );

        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'loadArchiveIds');
        /** @var array<string, true> $ids */
        $ids = $method->invoke(null, $file);
        unlink($file);

        self::assertSame(
            ['30523920' => true, '25970122' => true, 'dQw4w9WgXcQ' => true],
            $ids
        );
    }

    public function testLoadSpotdlArchiveIds(): void
    {
        $archive = tempnam(sys_get_temp_dir(), 'sc_test_archive_');
        file_put_contents(
            $archive,
            "https://open.spotify.com/track/sp123\n\nhttps://open.spotify.com/track/sp456?si=x\n"
        );
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'loadSpotdlArchiveIds');

        try {
            self::assertSame(['sp123' => true, 'sp456' => true], $method->invoke(null, $archive));
            self::assertSame([], $method->invoke(null, '/nonexistent/archive.txt'));
        } finally {
            @unlink($archive);
        }
    }

    /**
     * @param array<string, mixed> $json
     * @param array{title: string, artist: string, album: string, genre: string, comment: string, date: string} $expected
     */
    #[DataProvider('infoJsonProvider')]
    public function testMapInfoJsonToTags(array $json, array $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'mapInfoJsonToTags');

        self::assertSame($expected, $method->invoke(null, $json));
    }

    #[DataProvider('formatUnavailableLineProvider')]
    public function testMatchFormatUnavailableId(string $line, ?string $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'matchFormatUnavailableId');

        self::assertSame($expected, $method->invoke(null, $line));
    }

    #[DataProvider('minKbpsForOdgProvider')]
    public function testMinKbpsForOdg(string $codec, float $minOdg, ?float $expected): void
    {
        $actual = PlaylistsSyncCommand::minKbpsForOdg($codec, $minOdg);
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
                $kbps = PlaylistsSyncCommand::minKbpsForOdg($codec, $threshold);
                if ($kbps === null) {
                    continue;
                }
                self::assertGreaterThanOrEqual(
                    $threshold - 1e-9,
                    PlaylistsSyncCommand::estimateOdg($codec, $kbps),
                    "$codec @ $threshold: minKbpsForOdg result must satisfy the threshold"
                );
            }
        }
    }

    #[DataProvider('normalizeCodecProvider')]
    public function testNormalizeCodec(?string $acodec, string $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::normalizeCodec($acodec));
    }

    public function testOdgCalibrationIsMonotonic(): void
    {
        /** @var array<string, list<array{0: float|int, 1: float}>> $table */
        $table = (new ReflectionClassConstant(PlaylistsSyncCommand::class, 'ODG_CALIBRATION'))->getValue();

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

    #[DataProvider('parseFormatsAnswerProvider')]
    public function testParseFormatsAnswer(?string $answer, string $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::parseFormatsAnswer($answer));
    }

    #[DataProvider('parseFormatsAnswerInvalidProvider')]
    public function testParseFormatsAnswerInvalidThrows(?string $answer): void
    {
        $this->expectException(RuntimeException::class);
        PlaylistsSyncCommand::parseFormatsAnswer($answer);
    }

    #[DataProvider('parseMinOdgAnswerProvider')]
    public function testParseMinOdgAnswer(?string $answer, ?float $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::parseMinOdgAnswer($answer));
    }

    #[DataProvider('parseMinOdgAnswerInvalidProvider')]
    public function testParseMinOdgAnswerInvalidThrows(string $answer): void
    {
        $this->expectException(RuntimeException::class);
        PlaylistsSyncCommand::parseMinOdgAnswer($answer);
    }

    /**
     * @param list<array<string, mixed>> $songs
     * @param array{string, string, string, list<array{id: string, title: string}>} $expected
     */
    #[DataProvider('parseSpotdlSaveDataProvider')]
    public function testParseSpotdlSaveData(array $songs, string $url, array $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'parseSpotdlSaveData');

        self::assertSame($expected, $method->invoke(null, $songs, $url));
    }

    public function testParseSpotdlSaveDataEmptyThrows(): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'parseSpotdlSaveData');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to query Spotify playlist info/');
        $method->invoke(null, [], 'https://open.spotify.com/playlist/pl99');
    }

    public function testProbeAudioPropertiesCodecOnlyWhenBitrateUnknown(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('bit_rate', 0, "codec_name=opus\nbit_rate=N/A\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeAudioProperties');

        self::assertSame([null, 'opus'], $method->invoke($command, __FILE__));
    }

    public function testProbeAudioPropertiesFallsBackToFormatBitrate(): void
    {
        [$command, $fake] = self::makeCommand();
        // stream reports N/A (e.g. some containers) → format line is used
        $fake->on('bit_rate', 0, "codec_name=vorbis\nbit_rate=N/A\nbit_rate=320000\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeAudioProperties');

        self::assertSame([320.0, 'vorbis'], $method->invoke($command, __FILE__));
    }

    public function testProbeAudioPropertiesFfprobeFailureIsNull(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('bit_rate', 1, '');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeAudioProperties');

        self::assertSame([null, null], $method->invoke($command, __FILE__));
    }

    public function testProbeAudioPropertiesMissingFileSkipsProbe(): void
    {
        [$command, $fake] = self::makeCommand();
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeAudioProperties');

        self::assertSame([null, null], $method->invoke($command, '/nonexistent/file.m4a'));
        self::assertSame([], $fake->commands);
    }

    public function testProbeAudioPropertiesParsesStreamBitrateAndCodec(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('bit_rate', 0, "codec_name=mp3\nbit_rate=128000\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeAudioProperties');

        self::assertSame([128.0, 'mp3'], $method->invoke($command, __FILE__));
        self::assertStringContainsString('stream=codec_name,bit_rate:format=bit_rate', $fake->commands[0]);
        self::assertStringContainsString(escapeshellarg(__FILE__), $fake->commands[0]);
    }

    public function testProbeSampleRateFfprobeFailureIsNull(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 1, '');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeSampleRate');

        self::assertNull($method->invoke($command, __FILE__));
    }

    public function testProbeSampleRateMissingFileSkipsProbe(): void
    {
        [$command, $fake] = self::makeCommand();
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeSampleRate');

        self::assertNull($method->invoke($command, '/nonexistent/file.wav'));
        self::assertSame([], $fake->commands);
    }

    public function testProbeSampleRateNonNumericOutputIsNull(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 0, "N/A\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeSampleRate');

        self::assertNull($method->invoke($command, __FILE__));
    }

    public function testProbeSampleRateParsesRate(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 0, "44100\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'probeSampleRate');

        self::assertSame(44100, $method->invoke($command, __FILE__));
        self::assertStringContainsString('stream=sample_rate', $fake->commands[0]);
        self::assertStringContainsString(escapeshellarg(__FILE__), $fake->commands[0]);
    }

    #[DataProvider('relativeFromPartsProvider')]
    public function testRelativeFromParts(string $fromDir, string $to, string $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'relativeFromParts');

        self::assertSame($expected, $method->invoke(null, $fromDir, $to));
    }

    public function testRequireBinaryMissingThrows(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('missing-bin', 127, '');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'requireBinary');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing dependency: missing-bin/');
        $method->invoke($command, 'missing-bin', '--version');
    }

    public function testRequireBinaryPresentPasses(): void
    {
        [$command, $fake] = self::makeCommand();
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'requireBinary');

        $method->invoke($command, 'some-bin', '--version');

        self::assertSame(['some-bin --version 2>&1'], $fake->commands);
    }

    #[DataProvider('sleepRequestsProvider')]
    public function testResolveSleepRequests(string $raw, float $min, float $max): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'resolveSleepRequests');
        $result = (float)$method->invoke(new PlaylistsSyncCommand(), $raw);

        self::assertGreaterThanOrEqual($min, $result);
        self::assertLessThanOrEqual($max, $result);
    }

    public function testRunCmdSplitsChunksIntoLines(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('some-tool', 0, "line1\nline2\n\nline3\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'runCmd');

        $lines = [];
        [$exit, $stdout] = $method->invoke(
            $command,
            'some-tool --arg',
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }
        );

        self::assertSame(0, $exit);
        self::assertSame("line1\nline2\n\nline3\n", $stdout);
        self::assertSame(['line1', 'line2', 'line3'], $lines);
    }

    #[DataProvider('safeNameProvider')]
    public function testSafeName(string $input, string $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'safeName');
        self::assertSame($expected, $method->invoke(null, $input));
    }

    public function testShouldReencodeFalseUnderVbrMode(): void
    {
        [$command, $fake] = self::makeCommand();
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'mp3Mode'))->setValue($command, 'vbr');
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'shouldReencode');

        self::assertFalse($method->invoke($command, 'mp3', '/some/target.mp3'));
        self::assertSame([], $fake->commands);
    }

    public function testShouldReencodeFalseWhenFlagDisabled(): void
    {
        [$command, $fake] = self::makeCommand();
        (new ReflectionProperty(PlaylistsSyncCommand::class, 'reencodeStaleMp3'))->setValue($command, false);
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'shouldReencode');

        self::assertFalse($method->invoke($command, 'mp3', '/some/target.mp3'));
        self::assertSame([], $fake->commands);
    }

    public function testShouldReencodeFalseWhenProbeFails(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 1, '');
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'shouldReencode');

        try {
            self::assertFalse($method->invoke($command, 'mp3', $target));
        } finally {
            @unlink($target);
        }
    }

    public function testShouldReencodeTrueOnMismatchedBitrate(): void
    {
        [$command, $fake] = self::makeCommand();
        $fake->on('ffprobe', 0, "codec_name=mp3\nbit_rate=168428\n");
        $target = tempnam(sys_get_temp_dir(), 'sc_test_target_');
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'shouldReencode');

        try {
            self::assertTrue($method->invoke($command, 'mp3', $target));
        } finally {
            @unlink($target);
        }
    }

    #[DataProvider('targetSampleRateProvider')]
    public function testTargetSampleRate(int $src, ?int $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::targetSampleRate($src));
    }

    #[DataProvider('titleFromFilenameProvider')]
    public function testTitleFromFilename(string $srcPath, string $id, string $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'titleFromFilename');

        self::assertSame($expected, $method->invoke(null, $srcPath, $id));
    }
}
