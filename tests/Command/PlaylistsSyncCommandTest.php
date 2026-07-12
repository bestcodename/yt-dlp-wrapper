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
