<?php

declare(strict_types=1);

namespace App\Tests\Playlists;

use App\Playlists\SpotdlDownloader;
use App\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SpotdlDownloaderTest extends TestCase
{
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

    public function testBuildSpotdlDownloadCmd(): void
    {
        $withCookie = SpotdlDownloader::buildSpotdlDownloadCmd(
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

        $withoutCookie = SpotdlDownloader::buildSpotdlDownloadCmd(
            'spotdl',
            'https://open.spotify.com/playlist/pl99',
            '/lib/original',
            '/arch/spotify.txt',
            null
        );
        self::assertNotContains("'--cookie-file'", $withoutCookie);
    }

    public function testGetPlaylistIdentityAndEntriesFailureThrows(): void
    {
        [$downloader, $fake] = self::makeDownloader();
        $fake->on("'save'", 1, '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to query Spotify playlist info/');
        $downloader->getPlaylistIdentityAndEntries(
            'https://open.spotify.com/playlist/pl99',
            sys_get_temp_dir(),
            self::io()
        );
    }

    /**
     * @return array{SpotdlDownloader, FakeProcessRunner}
     */
    private static function makeDownloader(): array
    {
        $fake = new FakeProcessRunner();

        return [new SpotdlDownloader($fake, 'spotdl', null), $fake];
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    public function testGetPlaylistIdentityAndEntriesMissingSaveFileThrows(): void
    {
        [$downloader] = self::makeDownloader();

        // exit 0 (default fake rule) but no save file was created
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to query Spotify playlist info/');
        $downloader->getPlaylistIdentityAndEntries(
            'https://open.spotify.com/playlist/missing',
            sys_get_temp_dir(),
            self::io()
        );
    }

    public function testGetPlaylistIdentityAndEntriesReadsSaveFile(): void
    {
        [$downloader, $fake] = self::makeDownloader();
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

        try {
            $result = $downloader->getPlaylistIdentityAndEntries($url, $archiveDir, self::io());

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

    public function testLoadSpotdlArchiveIds(): void
    {
        $archive = tempnam(sys_get_temp_dir(), 'sc_test_archive_');
        file_put_contents(
            $archive,
            "https://open.spotify.com/track/sp123\n\nhttps://open.spotify.com/track/sp456?si=x\n"
        );

        try {
            self::assertSame(['sp123' => true, 'sp456' => true], SpotdlDownloader::loadSpotdlArchiveIds($archive));
            self::assertSame([], SpotdlDownloader::loadSpotdlArchiveIds('/nonexistent/archive.txt'));
        } finally {
            @unlink($archive);
        }
    }

    /**
     * @param list<array<string, mixed>> $songs
     * @param array{string, string, string, list<array{id: string, title: string}>} $expected
     */
    #[DataProvider('parseSpotdlSaveDataProvider')]
    public function testParseSpotdlSaveData(array $songs, string $url, array $expected): void
    {
        self::assertSame($expected, SpotdlDownloader::parseSpotdlSaveData($songs, $url));
    }

    public function testParseSpotdlSaveDataEmptyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to query Spotify playlist info/');
        SpotdlDownloader::parseSpotdlSaveData([], 'https://open.spotify.com/playlist/pl99');
    }
}
