<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SoundCloudDownloadCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SoundCloudDownloadCommandTest extends TestCase
{
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
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'buildFfmpegArgs');

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
            'warning',
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

    public function testCountArchivedCountsOnlyKnownIds(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
            ['id' => '25970122', 'title' => 'B'],
            ['id' => '999275440', 'title' => 'C'],
        ];
        $archivedIds = ['30523920' => true, '25970122' => true, 'unrelated' => true];

        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'countArchived');

        self::assertSame(2, $method->invoke(null, $entries, $archivedIds));
    }

    public function testCountArchivedEmptyArchiveIsZero(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
        ];

        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'countArchived');

        self::assertSame(0, $method->invoke(null, $entries, []));
    }

    public function testLoadArchiveIdsMissingFileIsEmpty(): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'loadArchiveIds');

        self::assertSame([], $method->invoke(null, '/nonexistent/archive/original.txt'));
    }

    public function testLoadArchiveIdsParsesLastToken(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arch');
        file_put_contents(
            $file,
            "soundcloud 30523920\nsoundcloud 25970122\n\n  youtube dQw4w9WgXcQ  \n"
        );

        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'loadArchiveIds');
        /** @var array<string, true> $ids */
        $ids = $method->invoke(null, $file);
        unlink($file);

        self::assertSame(
            ['30523920' => true, '25970122' => true, 'dQw4w9WgXcQ' => true],
            $ids
        );
    }

    /**
     * @param array<string, mixed> $json
     * @param array{title: string, artist: string, album: string, genre: string, comment: string, date: string} $expected
     */
    #[DataProvider('infoJsonProvider')]
    public function testMapInfoJsonToTags(array $json, array $expected): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'mapInfoJsonToTags');

        self::assertSame($expected, $method->invoke(null, $json));
    }

    #[DataProvider('relativeFromPartsProvider')]
    public function testRelativeFromParts(string $fromDir, string $to, string $expected): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'relativeFromParts');

        self::assertSame($expected, $method->invoke(null, $fromDir, $to));
    }

    #[DataProvider('sleepRequestsProvider')]
    public function testResolveSleepRequests(string $raw, float $min, float $max): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'resolveSleepRequests');
        $result = (float)$method->invoke(new SoundCloudDownloadCommand(), $raw);

        self::assertGreaterThanOrEqual($min, $result);
        self::assertLessThanOrEqual($max, $result);
    }

    #[DataProvider('safeNameProvider')]
    public function testSafeName(string $input, string $expected): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'safeName');
        self::assertSame($expected, $method->invoke(null, $input));
    }

    #[DataProvider('targetSampleRateProvider')]
    public function testTargetSampleRate(int $src, ?int $expected): void
    {
        self::assertSame($expected, SoundCloudDownloadCommand::targetSampleRate($src));
    }
}
