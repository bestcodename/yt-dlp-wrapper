<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Support\FakeProcessRunner;
use App\Tests\Support\TestablePlaylistsSyncCommand;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Flow tests for the playlists:sync execute loop through CommandTester. Runs
 * non-interactively (--input/--out/--playlists-dir) inside a per-test temp workspace; all
 * yt-dlp/ffprobe/ffmpeg invocations go through FakeProcessRunner. DOTENV_PATH is pinned to a
 * workspace .env (PAUSE_BETWEEN=0 disables the between-playlists sleep, FORMATS limits the
 * conversion matrix) so the real environment and any repo .env never leak in.
 */
final class PlaylistsSyncCommandFlowTest extends TestCase
{
    private const PLAYLIST_JSON = '{"title":"My List","id":"pl1","uploader":"DJ",'
    .'"entries":[{"id":"123","title":"Track One"}]}';
    private const SPOTIFY_URL = 'https://open.spotify.com/playlist/pl99';
    private string $configPath;
    private string $workDir;

    public function testDownloadsConvertsAndWritesPlaylist(): void
    {
        // The original is "already downloaded": the download step is faked, so the track file
        // and its sidecars must pre-exist for the conversion loop to pick them up.
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents($originalDir.'/123 - Track One.info.json', '{"title":"Track One","uploader":"DJ"}');
        file_put_contents($originalDir.'/123 - Track One.jpg', 'jpegdata');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options());

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Download: 1 new, 0 already in archive', $display);
        self::assertStringNotContainsString('Failed tracks:', $display);
        self::assertStringContainsString('All done.', $display);

        // FORMATS=original,mp3 → exactly one conversion (mp3), fed by one ffprobe
        self::assertTrue($fake->ran('ffprobe'));
        $ffmpegCmds = array_values(
            array_filter(
                $fake->commands,
                static fn(string $c): bool => str_starts_with($c, "'ffmpeg'")
            )
        );
        self::assertCount(1, $ffmpegCmds);
        self::assertStringContainsString('123 - Track One.mp3', $ffmpegCmds[0]);

        $m3uPath = $this->workDir.'/out/playlists/DJ - My List - mp3.m3u8';
        self::assertFileExists($m3uPath);
        $m3u = (string)file_get_contents($m3uPath);
        self::assertStringContainsString('#EXTM3U', $m3u);
        self::assertStringContainsString('123 - Track One.mp3', $m3u);
        self::assertStringContainsString('Wrote playlist:', $display);
    }

    private function makeTester(FakeProcessRunner $fake, ?string $legacyConfigPath = null): CommandTester
    {
        $app = new Application();
        $command = new TestablePlaylistsSyncCommand($this->configPath, $fake, $legacyConfigPath);
        $app->addCommand($command);

        return new CommandTester($command);
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        return [
            '--input' => $this->workDir.'/playlists.txt',
            '--out' => $this->workDir.'/out',
            '--playlists-dir' => $this->workDir.'/out/playlists',
        ];
    }

    private function display(CommandTester $tester): string
    {
        return (string)preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testLegacyConfigFallbackMigratesOnSave(): void
    {
        $legacy = $this->workDir.'/legacy-config.json';
        file_put_contents($legacy, json_encode([
            'input_file' => $this->workDir.'/playlists.txt',
            'output_dir' => $this->workDir.'/out',
        ]));
        $fake = (new FakeProcessRunner())->on("'-J'", 1);
        $tester = $this->makeTester($fake, $legacy);

        // no --input/--out: values must come from the legacy config; the interactive
        // save then writes them to the new config path
        $exit = $tester->execute(
            ['--playlists-dir' => $this->workDir.'/out/playlists'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->configPath);
        $migrated = json_decode((string)file_get_contents($this->configPath), true);
        self::assertSame($this->workDir.'/playlists.txt', $migrated['input_file'] ?? null);
    }

    public function testMissingSpotdlBinaryFailsPreflight(): void
    {
        file_put_contents($this->workDir.'/playlists.txt', self::SPOTIFY_URL."\n");
        $fake = (new FakeProcessRunner())->on('--version', 127);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options());

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Missing dependency: spotdl', $this->display($tester));
    }

    public function testMixedSourcesRouteToBothDownloaders(): void
    {
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "https://soundcloud.com/dj/sets/my-list\n".self::SPOTIFY_URL."\n"
        );
        $this->seedSpotifyWorkspace();
        // soundcloud original + sidecars, exactly like the happy-path test
        $originalDir = $this->workDir.'/out/library/original';
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents($originalDir.'/123 - Track One.info.json', '{"title":"Track One","uploader":"DJ"}');
        file_put_contents($originalDir.'/123 - Track One.jpg', 'jpegdata');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options());

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);

        // spotdl side: save + download commands, archive-diff summary, conversion, m3u
        self::assertTrue($fake->ran("'save'"));
        self::assertTrue($fake->ran("'--format' 'm4a' '--bitrate' 'disable'"));
        self::assertTrue($fake->ran("spotify.txt'"));
        self::assertStringContainsString('Download: 0 new, 1 already in archive', $display);
        self::assertTrue($fake->ran('sp123 - Spot Track.mp3'));
        $spotifyM3u = $this->workDir.'/out/playlists/Spotify - My Spotify List - mp3.m3u8';
        self::assertFileExists($spotifyM3u);
        self::assertStringContainsString('sp123 - Spot Track.mp3', (string)file_get_contents($spotifyM3u));

        // yt-dlp side unchanged
        self::assertStringContainsString('Download: 1 new, 0 already in archive', $display);
        self::assertFileExists($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
        self::assertStringContainsString('All done.', $display);
    }

    /** Pre-creates the artifacts spotdl would produce: save file, archive entry, original m4a. */
    private function seedSpotifyWorkspace(): void
    {
        $archiveDir = $this->workDir.'/out/.archive';
        mkdir($archiveDir, 0777, true);
        file_put_contents(
            $archiveDir.'/spotify-save-'.md5(self::SPOTIFY_URL).'.spotdl',
            json_encode([
                [
                    'name' => 'Spot Track',
                    'song_id' => 'sp123',
                    'url' => 'https://open.spotify.com/track/sp123',
                    'list_name' => 'My Spotify List',
                ],
            ])
        );
        file_put_contents($archiveDir.'/spotify.txt', "https://open.spotify.com/track/sp123\n");

        $originalDir = $this->workDir.'/out/library/original';
        @mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/sp123 - Spot Track.m4a', 'm4adata');
    }

    public function testNonInteractiveRequiresInputAndOut(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('--input and --out are required', $this->display($tester));
    }

    public function testPlaylistFetchFailureIsReportedAndContinues(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 1, '');
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options());

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('failed to fetch playlist info', $display);
        self::assertStringContainsString('The following playlists had errors:', $display);
        self::assertStringContainsString('All done.', $display);
        self::assertFalse($fake->ran('--download-archive'));
    }

    public function testSpotdlFailureWarnsAndContinues(): void
    {
        file_put_contents($this->workDir.'/playlists.txt', self::SPOTIFY_URL."\n");
        $this->seedSpotifyWorkspace();
        $fake = (new FakeProcessRunner())
            ->on("'--bitrate'", 3)
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options());

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('spotdl exited with code 3 for originals; continuing.', $display);
        self::assertStringContainsString('The following playlists had errors:', $display);
    }

    public function testYtDlpFailureWarnsAndContinues(): void
    {
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 5, '');
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options());

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('yt-dlp exited with code 5 for originals; continuing.', $display);
        self::assertStringContainsString('Download: 0 new, 0 already in archive, 1 failed', $display);
        self::assertStringContainsString('Failed tracks: - Track One (123)', $display);
        self::assertStringContainsString('The following playlists had errors:', $display);
    }

    public function testYtdlpOnlyInputNeverInvokesSpotdl(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 1);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options());

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFalse($fake->ran('spotdl'));
    }

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/sc_flow_test_'.uniqid('', true);
        mkdir($this->workDir.'/out', 0777, true);
        $this->configPath = $this->workDir.'/config.json';

        file_put_contents($this->workDir.'/.env', "PAUSE_BETWEEN=0\nFORMATS=original,mp3\n");
        putenv('DOTENV_PATH='.$this->workDir.'/.env');

        file_put_contents($this->workDir.'/playlists.txt', "https://soundcloud.com/dj/sets/my-list\n");
    }

    protected function tearDown(): void
    {
        foreach (['DOTENV_PATH', 'PAUSE_BETWEEN', 'FORMATS'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->workDir);
    }
}
