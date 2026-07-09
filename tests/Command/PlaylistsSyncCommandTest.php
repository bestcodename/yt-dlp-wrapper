<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PlaylistsSyncCommand;
use App\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class PlaylistsSyncCommandTest extends TestCase
{
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
     * @return array<string, array{list<array<string, mixed>>, string, array{string, string, string, list<array{id: string, title: string}>}}>
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
                ],
            ],
            'single track falls back to track name' => [
                [array_merge($songs[0], ['list_name' => null, 'list_url' => null])],
                'https://open.spotify.com/track/sp123',
                ['Spot Track', 'sp123', 'Spotify', [['id' => 'sp123', 'title' => 'Spot Track']]],
            ],
            'missing song_id falls back to url tail' => [
                [array_merge($songs[0], ['song_id' => null])],
                $playlistUrl,
                ['My Spotify List', 'pl99', 'Spotify', [['id' => 'sp123', 'title' => 'Spot Track']]],
            ],
            'song without id or url is skipped' => [
                [array_merge($songs[0], ['song_id' => null, 'url' => null]), $songs[1]],
                $playlistUrl,
                ['My Spotify List', 'pl99', 'Spotify', [['id' => 'sp456', 'title' => 'Other Track']]],
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
    ): array {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'buildFfmpegArgs');

        /** @var list<string> $args */
        $args = $method->invoke(
            null,
            'ffmpeg',
            $mp3Quality,
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

    public function testEnsureConvertedExistingTargetSkipsWork(): void
    {
        [$command, $fake] = self::makeCommand();
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
                ['My Spotify List', 'pl99', 'Spotify', [['id' => 'sp123', 'title' => 'Spot Track']]],
                $result
            );
            self::assertTrue($fake->ran("'save'"));
            self::assertTrue($fake->ran($saveFile));
        } finally {
            @unlink($saveFile);
            @rmdir($archiveDir);
        }
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

    #[DataProvider('targetSampleRateProvider')]
    public function testTargetSampleRate(int $src, ?int $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::targetSampleRate($src));
    }
}
