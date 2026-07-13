<?php

declare(strict_types=1);

namespace App\Tests\Playlists;

use App\Playlists\YtDlpDownloader;
use App\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class YtDlpDownloaderTest extends TestCase
{
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

    public function testBuildFormatSelectorInvertsThresholdPerCodec(): void
    {
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
            YtDlpDownloader::buildFormatSelector(-1.0)
        );
    }

    public function testBuildFormatSelectorNoThreshold(): void
    {
        self::assertSame('bestaudio/best', YtDlpDownloader::buildFormatSelector(null));
    }

    public function testBuildFormatSelectorOmitsCodecsThatCannotReachThreshold(): void
    {
        $selector = YtDlpDownloader::buildFormatSelector(-0.1);

        // mp3 (top anchor -0.2) and vorbis (-0.15) cannot reach -0.1 at any bitrate → fail-closed omission
        self::assertStringNotContainsString('mp3', $selector);
        self::assertStringNotContainsString('vorbis', $selector);
        self::assertStringContainsString('bestaudio[acodec^=opus][abr>=', $selector);
        self::assertStringContainsString('bestaudio[acodec^=mp4a][abr>=', $selector);
        self::assertStringContainsString('bestaudio[acodec^=flac]', $selector);
    }

    public function testGetPlaylistIdentityAndEntriesFailureThrows(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('-J', 1, '');
        $downloader = self::makeDownloader($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to query playlist info/');
        $downloader->getPlaylistIdentityAndEntries('https://soundcloud.com/dj/sets/my-list', self::io());
    }

    public function testGetPlaylistIdentityAndEntriesInvalidJsonThrows(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('-J', 0, 'not json');
        $downloader = self::makeDownloader($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON from yt-dlp/');
        $downloader->getPlaylistIdentityAndEntries('https://soundcloud.com/dj/sets/my-list', self::io());
    }

    public function testGetPlaylistIdentityAndEntriesParsesAndSkipsNested(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('-J', 0, json_encode([
            'title' => 'My Set',
            'id' => 'set1',
            'uploader' => 'dj',
            'entries' => [
                ['id' => '123', 'title' => 'Track One'],
                ['_type' => 'playlist', 'id' => 'nested', 'title' => 'Nested Set'],
            ],
        ]));
        $downloader = self::makeDownloader($fake);

        $result = $downloader->getPlaylistIdentityAndEntries('https://soundcloud.com/dj/sets/my-list', self::io());

        self::assertSame(
            [
                'My Set',
                'set1',
                'dj',
                [['id' => '123', 'title' => 'Track One']],
                [['id' => 'nested', 'title' => 'Nested Set']],
            ],
            $result
        );
    }

    public function testGetPlaylistIdentityAndEntriesPassesJsRuntimes(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('-J', 0, json_encode(['title' => 'Set', 'id' => 's1', 'uploader' => 'dj', 'entries' => []]));
        $downloader = self::makeDownloader($fake);

        $downloader->getPlaylistIdentityAndEntries('https://soundcloud.com/dj/sets/my-list', self::io());

        self::assertTrue($fake->ran('--js-runtimes'));
        self::assertTrue($fake->ran("'node'"));
    }

    private static function makeDownloader(FakeProcessRunner $runner): YtDlpDownloader
    {
        return new YtDlpDownloader($runner, 'yt-dlp', null, '3', null, '1', '0', 'warn', null, 'node');
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('nestedPlaylistEntryProvider')]
    public function testIsNestedPlaylistEntry(array $entry, bool $expected): void
    {
        self::assertSame($expected, YtDlpDownloader::isNestedPlaylistEntry($entry));
    }

    public function testLoadArchiveIdsMissingFileIsEmpty(): void
    {
        self::assertSame([], YtDlpDownloader::loadArchiveIds('/nonexistent/archive/original.txt'));
    }

    public function testLoadArchiveIdsParsesLastToken(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arch');
        file_put_contents(
            $file,
            "soundcloud 30523920\nsoundcloud 25970122\n\n  youtube dQw4w9WgXcQ  \n"
        );

        try {
            self::assertSame(
                ['30523920' => true, '25970122' => true, 'dQw4w9WgXcQ' => true],
                YtDlpDownloader::loadArchiveIds($file)
            );
        } finally {
            unlink($file);
        }
    }

    #[DataProvider('formatUnavailableLineProvider')]
    public function testMatchFormatUnavailableId(string $line, ?string $expected): void
    {
        self::assertSame($expected, YtDlpDownloader::matchFormatUnavailableId($line));
    }
}
