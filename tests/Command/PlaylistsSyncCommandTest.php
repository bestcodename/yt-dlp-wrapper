<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PlaylistsSyncCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;

final class PlaylistsSyncCommandTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function buildM3uPathProvider(): array
    {
        return [
            'flat default' => [
                '/out/playlists',
                'flat',
                'DJ - My List',
                'mp3',
                '/out/playlists/DJ - My List - mp3.m3u8',
            ],
            'flat with trailing separator on playlistsDir' => [
                '/out/playlists/',
                'flat',
                'DJ - My List',
                'mp3',
                '/out/playlists/DJ - My List - mp3.m3u8',
            ],
            'per-playlist' => [
                '/out/playlists',
                'per-playlist',
                'DJ - My List',
                'mp3',
                '/out/playlists/DJ - My List/mp3.m3u8',
            ],
            'per-format' => [
                '/out/playlists',
                'per-format',
                'DJ - My List',
                'mp3',
                '/out/playlists/mp3/DJ - My List.m3u8',
            ],
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
            '"all" keyword expands to every format' => ['all', 'original,mp3,wav,flac'],
            '"all" is case-insensitive' => ['ALL', 'original,mp3,wav,flac'],
            '"all" mixed with other entries still expands to every format' => ['mp3,all', 'original,mp3,wav,flac'],
            '"all" with surrounding whitespace' => [' all ', 'original,mp3,wav,flac'],
        ];
    }

    /**
     * @return array<string, array{list<string>, list<array{url: string, alias: ?string}>}>
     */
    public static function parseInputFileEntriesProvider(): array
    {
        return [
            'plain url only' => [
                ["https://soundcloud.com/x/sets/y\n"],
                [['url' => 'https://soundcloud.com/x/sets/y', 'alias' => null]],
            ],
            'blank-line-separated plain comment header has no effect' => [
                ["# DJ Sets\n", "\n", "https://soundcloud.com/x/sets/a\n", "https://soundcloud.com/x/sets/b\n"],
                [
                    ['url' => 'https://soundcloud.com/x/sets/a', 'alias' => null],
                    ['url' => 'https://soundcloud.com/x/sets/b', 'alias' => null],
                ],
            ],
            'plain comment directly above a url has no effect (regression: config/playlists.txt)' => [
                ["# Ciul\n", "https://open.spotify.com/playlist/2BcbNwFxuykzGNKDoY4c4H\n"],
                [['url' => 'https://open.spotify.com/playlist/2BcbNwFxuykzGNKDoY4c4H', 'alias' => null]],
            ],
            'alias directly above a url applies' => [
                ["# alias: My Chill Mix\n", "https://soundcloud.com/x/sets/tek\n"],
                [['url' => 'https://soundcloud.com/x/sets/tek', 'alias' => 'My Chill Mix']],
            ],
            'alias with extra whitespace and mixed case' => [
                ["#   Alias:   My Mix  \n", "https://soundcloud.com/x/sets/tek\n"],
                [['url' => 'https://soundcloud.com/x/sets/tek', 'alias' => 'My Mix']],
            ],
            'alias uppercase no spaces' => [
                ["#ALIAS:X\n", "https://soundcloud.com/x/sets/tek\n"],
                [['url' => 'https://soundcloud.com/x/sets/tek', 'alias' => 'X']],
            ],
            'alias separated from url by a blank line does not apply' => [
                ["# alias: My Mix\n", "\n", "https://soundcloud.com/x/sets/tek\n"],
                [['url' => 'https://soundcloud.com/x/sets/tek', 'alias' => null]],
            ],
            'alias separated from url by another comment does not apply' => [
                ["# alias: My Mix\n", "# just a note\n", "https://soundcloud.com/x/sets/tek\n"],
                [['url' => 'https://soundcloud.com/x/sets/tek', 'alias' => null]],
            ],
            'two consecutive alias comments: last one wins' => [
                ["# alias: First\n", "# alias: Second\n", "https://soundcloud.com/x/sets/tek\n"],
                [['url' => 'https://soundcloud.com/x/sets/tek', 'alias' => 'Second']],
            ],
            'empty alias name is ignored' => [
                ["# alias:\n", "https://soundcloud.com/x/sets/tek\n"],
                [['url' => 'https://soundcloud.com/x/sets/tek', 'alias' => null]],
            ],
            'only the aliased url gets the alias' => [
                [
                    "https://soundcloud.com/x/sets/a\n",
                    "# alias: B Alias\n",
                    "https://soundcloud.com/x/sets/b\n",
                ],
                [
                    ['url' => 'https://soundcloud.com/x/sets/a', 'alias' => null],
                    ['url' => 'https://soundcloud.com/x/sets/b', 'alias' => 'B Alias'],
                ],
            ],
            'trailing alias comment with no following url is dropped' => [
                ["https://soundcloud.com/x/sets/a\n", "# alias: Orphan\n"],
                [['url' => 'https://soundcloud.com/x/sets/a', 'alias' => null]],
            ],
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

    #[DataProvider('buildM3uPathProvider')]
    public function testBuildM3uPath(
        string $playlistsDir,
        string $layout,
        string $plFolder,
        string $fmt,
        string $expected
    ): void {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'buildM3uPath');

        self::assertSame($expected, $method->invoke(null, $playlistsDir, $layout, $plFolder, $fmt));
    }

    #[DataProvider('classifySourceUrlProvider')]
    public function testClassifySourceUrl(string $url, string $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::classifySourceUrl($url));
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

    #[DataProvider('parseInputFileEntriesProvider')]
    public function testParseInputFileEntries(array $lines, array $expected): void
    {
        self::assertSame($expected, PlaylistsSyncCommand::parseInputFileEntries($lines));
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

    #[DataProvider('relativeFromPartsProvider')]
    public function testRelativeFromParts(string $fromDir, string $to, string $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'relativeFromParts');

        self::assertSame($expected, $method->invoke(null, $fromDir, $to));
    }

    #[DataProvider('sleepRequestsProvider')]
    public function testResolveSleepRequests(string $raw, float $min, float $max): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'resolveSleepRequests');
        $result = (float)$method->invoke(new PlaylistsSyncCommand(), $raw);

        self::assertGreaterThanOrEqual($min, $result);
        self::assertLessThanOrEqual($max, $result);
    }

    #[DataProvider('safeNameProvider')]
    public function testSafeName(string $input, string $expected): void
    {
        $method = new ReflectionMethod(PlaylistsSyncCommand::class, 'safeName');
        self::assertSame($expected, $method->invoke(null, $input));
    }

}
