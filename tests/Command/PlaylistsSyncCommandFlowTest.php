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
    .'"entries":[{"id":"123","title":"Track One"},'
    .'{"_type":"url","id":"949270006","title":"Tekno Collection",'
    .'"url":"https://soundcloud.com/dj/sets/tekno"}]}';
    private const SPOTIFY_URL = 'https://open.spotify.com/playlist/pl99';
    private string $configPath;
    private string $workDir;

    public function testAliasCommentOverridesPlaylistFolderName(): void
    {
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "# alias: My Chill Mix\nhttps://soundcloud.com/dj/sets/my-list\n"
        );
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

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->workDir.'/out/playlists/My Chill Mix - mp3.m3u8');
        self::assertFileDoesNotExist($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
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

    public function testAliasWithPerPlaylistLayoutCombineCorrectly(): void
    {
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "# alias: My Chill Mix\nhttps://soundcloud.com/dj/sets/my-list\n"
        );
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

        $exit = $tester->execute(
            $this->options() + ['--playlist-layout' => 'per-playlist'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->workDir.'/out/playlists/My Chill Mix/mp3.m3u8');
        self::assertFileExists($this->workDir.'/out/playlists/My Chill Mix/original.m3u8');
    }

    public function testCliOverridesEnvAndConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['min_odg' => -2, 'min_odg_mode' => 'filter']));
        file_put_contents(
            $this->workDir.'/.env',
            "PAUSE_BETWEEN=0\nFORMATS=original,mp3\nMIN_ODG=-1\nMIN_ODG_MODE=filter\n"
        );
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-0.5', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        // ODG -0.5 inverts to 256 kbps on the mp3 curve
        self::assertTrue($fake->ran('acodec^=mp3][abr>=256]'));
        self::assertFalse($fake->ran('acodec^=mp3][abr>=192]'));
        self::assertFalse($fake->ran('acodec^=mp3][abr>=128]'));
    }

    public function testConfigFileFallbackApplies(): void
    {
        file_put_contents($this->configPath, json_encode([
            'formats' => 'original',
            'min_odg' => -2,
            'min_odg_mode' => 'filter',
        ]));
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        // FORMATS is pinned in the workspace .env — drop it so the config value applies
        file_put_contents($this->workDir.'/.env', "PAUSE_BETWEEN=0\n");
        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran('acodec^=mp3][abr>=128]'));
        // formats=original → no ffmpeg conversion
        self::assertFalse($fake->ran('123 - Track One.mp3'));
    }

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

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Download: 1 new, 0 already in archive', $display);
        self::assertStringNotContainsString('Failed tracks:', $display);
        self::assertStringContainsString(
            'Skipped playlists (not downloaded): - Tekno Collection (949270006)',
            $display
        );
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
        // default MP3 encoding is CBR 320k (VBR bitrate misreports in some DJ software, see CHANGELOG)
        self::assertStringContainsString("'-b:a' '320k'", $ffmpegCmds[0]);
        self::assertStringNotContainsString("'-q:a'", $ffmpegCmds[0]);

        $m3uPath = $this->workDir.'/out/playlists/DJ - My List - mp3.m3u8';
        self::assertFileExists($m3uPath);
        $m3u = (string)file_get_contents($m3uPath);
        self::assertStringContainsString('#EXTM3U', $m3u);
        self::assertStringContainsString('123 - Track One.mp3', $m3u);
        self::assertStringContainsString('Wrote playlist:', $display);

        // FORMATS=original,mp3 (workspace default) → an "original" playlist is written too,
        // referencing the untouched source file (no conversion involved)
        $originalM3uPath = $this->workDir.'/out/playlists/DJ - My List - original.m3u8';
        self::assertFileExists($originalM3uPath);
        $originalM3u = (string)file_get_contents($originalM3uPath);
        self::assertStringContainsString('#EXTM3U', $originalM3u);
        self::assertStringContainsString('123 - Track One.wav', $originalM3u);
    }

    private function display(CommandTester $tester): string
    {
        return (string)preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testEnvOverridesConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['min_odg' => -2, 'min_odg_mode' => 'warn']));
        file_put_contents(
            $this->workDir.'/.env',
            "PAUSE_BETWEEN=0\nFORMATS=original,mp3\nMIN_ODG=-1\nMIN_ODG_MODE=filter\n"
        );
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran('acodec^=mp3][abr>=192]'));
        self::assertFalse($fake->ran('acodec^=mp3][abr>=128]'));
    }

    public function testFormatsCliOverridesEnv(): void
    {
        // workspace .env pins FORMATS=original,mp3 — the CLI option must win
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents($originalDir.'/123 - Track One.info.json', '{"title":"Track One","uploader":"DJ"}');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--formats' => 'original'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFalse($fake->ran('123 - Track One.mp3'));

        // "original" is a real output format: it gets its own playlist, no other format's does
        $m3uPath = $this->workDir.'/out/playlists/DJ - My List - original.m3u8';
        self::assertFileExists($m3uPath);
        self::assertStringContainsString('123 - Track One.wav', (string)file_get_contents($m3uPath));
        self::assertFileDoesNotExist($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
    }

    public function testFormatsCliValueSkipsPrompt(): void
    {
        file_put_contents($this->workDir.'/.env', "PAUSE_BETWEEN=0\n");
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        // --formats given explicitly: no formats input needed, only the (suppressed) min-odg
        // prompts would otherwise fire
        $exit = $tester->execute(
            $this->options()
            + ['--formats' => 'mp3', '--min-odg' => '-4', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringNotContainsString('Output formats', $this->display($tester));
        // CLI values are never persisted — only prompt answers are (matches --min-odg/env elsewhere)
        self::assertArrayNotHasKey('formats', $this->savedConfig());
    }

    /**
     * @return array<string, mixed>
     */
    private function savedConfig(): array
    {
        return json_decode((string)file_get_contents($this->configPath), true) ?? [];
    }

    public function testFormatsPromptFiresEveryRunEvenWhenConfigured(): void
    {
        file_put_contents($this->workDir.'/.env', "PAUSE_BETWEEN=0\n");
        file_put_contents($this->configPath, json_encode(['formats' => 'mp3']));
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        // formats is already configured ('mp3'), but the prompt still fires every run — a new
        // answer overrides and re-persists it, proving it is not suppressed once configured
        $tester->setInputs(['mp3,wav']);
        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-4', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Output formats', $this->display($tester));
        self::assertSame('mp3,wav', $this->savedConfig()['formats'] ?? null);
    }

    public function testFormatsPromptedWhenUnconfigured(): void
    {
        // workspace .env normally pins FORMATS — drop it so nothing configures it
        file_put_contents($this->workDir.'/.env', "PAUSE_BETWEEN=0\n");
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        // suppress the (unrelated) min-odg prompt so only the formats prompt needs an input
        $tester->setInputs(['mp3,wav']);
        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-4', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('mp3,wav', $this->savedConfig()['formats'] ?? null);
        self::assertStringContainsString(
            '(--formats | FORMATS | config: formats)',
            $this->display($tester)
        );

        // second run: formats is now configured ('mp3,wav'), but the prompt still fires and a
        // fresh answer overrides/re-persists it
        $tester2 = $this->makeTester((new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON));
        $tester2->setInputs(['original']);
        $exit2 = $tester2->execute(
            $this->options() + ['--min-odg' => '-4', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );
        self::assertSame(Command::SUCCESS, $exit2);
        self::assertSame('original', $this->savedConfig()['formats'] ?? null);
    }

    public function testGenericCookiesUsedByBothTools(): void
    {
        $cookies = $this->workDir.'/cookies.txt';
        file_put_contents($cookies, "# Netscape HTTP Cookie File\n");
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "https://soundcloud.com/dj/sets/my-list\n".self::SPOTIFY_URL."\n"
        );
        $this->seedSpotifyWorkspace();
        $originalDir = $this->workDir.'/out/library/original';
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents($originalDir.'/123 - Track One.info.json', '{"title":"Track One","uploader":"DJ"}');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--cookies' => $cookies],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran("'--cookies' '$cookies'"));
        self::assertTrue($fake->ran("'--cookie-file' '$cookies'"));
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

    public function testInvalidFormatsFromCliFails(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute(
            $this->options() + ['--formats' => 'mp3,ogg'],
            ['interactive' => false]
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --formats / FORMATS value:', $this->display($tester));
        self::assertStringContainsString('Unknown format(s): ogg', $this->display($tester));
    }

    public function testInvalidFormatsFromConfigFails(): void
    {
        // workspace .env normally pins FORMATS — drop it so the config value applies
        file_put_contents($this->workDir.'/.env', "PAUSE_BETWEEN=0\n");
        file_put_contents($this->configPath, json_encode(['formats' => 'mp3,ogg']));
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --formats / FORMATS value:', $this->display($tester));
    }

    public function testInvalidMinOdgFromConfigFails(): void
    {
        file_put_contents($this->configPath, json_encode(['min_odg' => 'abc']));
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --min-odg / MIN_ODG value: abc', $this->display($tester));
    }

    public function testInvalidMp3BitrateFails(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute($this->options() + ['--mp3-bitrate' => 'abc'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --mp3-bitrate / MP3_BITRATE value: abc', $this->display($tester));
    }

    public function testInvalidMp3ModeFails(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute($this->options() + ['--mp3-mode' => 'bogus'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --mp3-mode / MP3_MODE value: bogus', $this->display($tester));
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
        // save then writes them to the new config path. Empty answer = min-odg off.
        $tester->setInputs(['']);
        $exit = $tester->execute(
            ['--playlists-dir' => $this->workDir.'/out/playlists', '--formats' => 'original,mp3'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->configPath);
        $migrated = json_decode((string)file_get_contents($this->configPath), true);
        self::assertSame($this->workDir.'/playlists.txt', $migrated['input_file'] ?? null);
    }

    public function testLowQualityGroupByCliOverridesEnvAndConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['low_quality_group_by' => 'playlist']));
        file_put_contents(
            $this->workDir.'/.env',
            "PAUSE_BETWEEN=0\nFORMATS=original,mp3\nLOW_QUALITY_GROUP_BY=playlist\n"
        );
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":64,"acodec":"mp3"}'
        );
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-2', '--low-quality-group-by' => 'tier'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        // playlist grouping would emit "DJ - My List:" as an outer group header; tier grouping never does
        self::assertStringNotContainsString('DJ - My List:', $this->display($tester));
    }

    public function testLowQualityGroupByInvalidValueFails(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute(
            $this->options() + ['--low-quality-group-by' => 'bogus'],
            ['interactive' => false]
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString(
            'Invalid --low-quality-group-by / LOW_QUALITY_GROUP_BY value: bogus',
            $this->display($tester)
        );
    }

    public function testLowQualityGroupByNotPromptedWhenMinOdgOff(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        // empty answer keeps min-odg off; low-quality-group-by must not be prompted (any further
        // prompt would silently fall back to its default, so absence of the key proves no prompt fired)
        $tester->setInputs(['']);
        $exit = $tester->execute($this->options() + ['--formats' => 'original,mp3'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertArrayNotHasKey('low_quality_group_by', $this->savedConfig());
    }

    public function testLowQualityGroupByPlaylistOptionGroupsByPlaylist(): void
    {
        $playlistJsonA = '{"title":"List A","id":"pl1","uploader":"DJ","entries":[{"id":"123","title":"Track One"}]}';
        $playlistJsonB = '{"title":"List B","id":"pl2","uploader":"DJ","entries":[{"id":"456","title":"Track Two"}]}';
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "# alias: Beta List\nhttps://soundcloud.com/dj/sets/my-list\n"
            ."# alias: Alpha List\nhttps://soundcloud.com/dj/sets/other-list\n"
        );
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":256,"acodec":"aac"}'
        );
        file_put_contents($originalDir.'/456 - Track Two.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/456 - Track Two.info.json',
            '{"title":"Track Two","uploader":"DJ","abr":32,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\nSEEN:Track Two\nDONE:456\n")
            ->on('my-list', 0, $playlistJsonA)
            ->on('other-list', 0, $playlistJsonB);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-0.1', '--low-quality-group-by' => 'playlist'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Alpha List:', $display);
        self::assertStringContainsString('Beta List:', $display);
        self::assertLessThan(
            strpos($display, 'Beta List:'),
            strpos($display, 'Alpha List:'),
            'playlists must be grouped alphabetically'
        );
    }

    public function testLowQualityGroupByPromptedWhenMinOdgActiveAndUnconfigured(): void
    {
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        $tester->setInputs(['playlist']);
        $exit = $tester->execute(
            $this->options() + ['--formats' => 'original,mp3', '--min-odg' => '-4', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('playlist', $this->savedConfig()['low_quality_group_by'] ?? null);
        self::assertStringContainsString(
            '(--low-quality-group-by | LOW_QUALITY_GROUP_BY | config: low_quality_group_by)',
            $this->display($tester)
        );

        // second run: configured — no prompt, no inputs needed
        $tester2 = $this->makeTester((new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON));
        $exit2 = $tester2->execute(
            $this->options() + ['--formats' => 'original,mp3', '--min-odg' => '-4', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );
        self::assertSame(Command::SUCCESS, $exit2);
    }

    public function testLowQualityGroupByTierWorstFirstByDefault(): void
    {
        $playlistJson = '{"title":"My List","id":"pl1","uploader":"DJ",'
            .'"entries":[{"id":"123","title":"Track One"},{"id":"456","title":"Track Two"}]}';
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":256,"acodec":"aac"}'
        );
        file_put_contents($originalDir.'/456 - Track Two.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/456 - Track Two.info.json',
            '{"title":"Track Two","uploader":"DJ","abr":32,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, $playlistJson)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\nSEEN:Track Two\nDONE:456\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-0.1'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertLessThan(
            strpos($display, 'Track One'),
            strpos($display, 'Track Two'),
            'the worse-tier track (Track Two, est. ODG -3.85) must be listed before the better one'
        );
    }

    public function testMinOdgCliOverridesEnv(): void
    {
        file_put_contents(
            $this->workDir.'/.env',
            "PAUSE_BETWEEN=0\nFORMATS=original,mp3\nMIN_ODG=-2\n"
        );
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-1', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran('acodec^=mp3][abr>=192]'));
        self::assertFalse($fake->ran('acodec^=mp3][abr>=128]'));
    }

    public function testMinOdgEnvVarsApply(): void
    {
        file_put_contents(
            $this->workDir.'/.env',
            "PAUSE_BETWEEN=0\nFORMATS=original,mp3\nMIN_ODG=-2\nMIN_ODG_MODE=filter\n"
        );
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        // ODG -2 inverts to 128 kbps on the mp3 curve
        self::assertTrue($fake->ran('acodec^=mp3][abr>=128]'));
    }

    public function testMinOdgFilterAddsFormatFilter(): void
    {
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-1', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        // per-codec inversion of ODG -1: opus 96 / aac 128 / mp3 192; lossless always passes
        self::assertTrue($fake->ran('bestaudio[acodec^=opus][abr>=96]'));
        self::assertTrue($fake->ran('bestaudio[acodec^=mp4a][abr>=128]'));
        self::assertTrue($fake->ran('bestaudio[acodec^=mp3][abr>=192]'));
        self::assertTrue($fake->ran('bestaudio[acodec^=flac]'));
        self::assertTrue($fake->ran('best[acodec^=mp3][tbr>=192]'));
    }

    public function testMinOdgFilterExcludesLegacyLowQualityFromPlaylists(): void
    {
        // low-quality original already in the library from a run before the threshold existed
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":64,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-2', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString(
            '1 track(s) below est. ODG -2 in the library (excluded from playlists):',
            $display
        );
        // no conversion, no playlist entry — original stays on disk
        self::assertFalse($fake->ran('123 - Track One.mp3'));
        $m3u = (string)file_get_contents($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
        self::assertStringNotContainsString('123 - Track One', $m3u);
        self::assertFileExists($originalDir.'/123 - Track One.wav');
    }

    public function testMinOdgFilterExcludesSpotifyViaProbedBitrate(): void
    {
        file_put_contents($this->workDir.'/playlists.txt', self::SPOTIFY_URL."\n");
        $this->seedSpotifyWorkspace();
        // no .info.json for spotdl tracks → bitrate + codec come from the ffprobe fallback
        $fake = (new FakeProcessRunner())
            ->on('bit_rate', 0, "codec_name=mp3\nbit_rate=128000\n")
            ->on('sample_rate', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-1', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        // spotdl download itself cannot be filtered
        self::assertFalse($fake->ran('abr>='));
        self::assertStringContainsString(
            'Note: the quality filter cannot skip Spotify downloads (spotdl); '
            .'tracks below the threshold are excluded after download.',
            $display
        );
        self::assertStringContainsString(
            '1 track(s) below est. ODG -1 in the library (excluded from playlists):',
            $display
        );
        self::assertStringContainsString('Spot Track (sp123): 128 kbps mp3, est. ODG -2', $display);
        // excluded from conversion and playlist; original m4a stays
        self::assertFalse($fake->ran('sp123 - Spot Track.mp3'));
        $m3u = (string)file_get_contents(
            $this->workDir.'/out/playlists/Spotify - My Spotify List - mp3.m3u8'
        );
        self::assertStringNotContainsString('sp123', $m3u);
        self::assertFileExists($this->workDir.'/out/library/original/sp123 - Spot Track.m4a');
    }

    public function testMinOdgFilterIsCodecAware(): void
    {
        // same 128 kbps bitrate: opus (est. ODG -0.5) passes a -1 threshold, mp3 (est. -2) fails it
        $playlistJson = '{"title":"My List","id":"pl1","uploader":"DJ",'
            .'"entries":[{"id":"123","title":"Track One"},{"id":"456","title":"Track Two"}]}';
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":128,"acodec":"opus"}'
        );
        file_put_contents($originalDir.'/456 - Track Two.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/456 - Track Two.info.json',
            '{"title":"Track Two","uploader":"DJ","abr":128,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, $playlistJson)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\nSEEN:Track Two\nDONE:456\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-1', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Track Two (456): 128 kbps mp3, est. ODG -2', $display);
        self::assertStringNotContainsString('Track One (123): 128 kbps opus', $display);
        $m3u = (string)file_get_contents($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
        self::assertStringContainsString('123 - Track One.mp3', $m3u);
        self::assertStringNotContainsString('456 - Track Two', $m3u);
    }

    public function testMinOdgFilterKeepsTrackWhoseDisplayedOdgMatchesThreshold(): void
    {
        // 127.97 kbps aac is a hair under the 128 kbps/-1.0 calibration anchor: it estimates to
        // ~-1.0008, which rounds to the same "est. ODG -1" shown for the exact anchor and must
        // not be treated as worse than a -1 threshold (see AudioConverter::isBelowMinOdg)
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":127.97,"acodec":"aac"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-1', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringNotContainsString('below est. ODG', $this->display($tester));
        $m3u = (string)file_get_contents($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
        self::assertStringContainsString('123 - Track One.mp3', $m3u);
    }

    public function testMinOdgFilterSkipsUnavailableFormatWithoutFailure(): void
    {
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on(
                '--download-archive',
                1,
                '',
                "ERROR: [soundcloud] 123: Requested format is not available\n"
            );
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-2', '--min-odg-mode' => 'filter'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Skipped (below est. ODG -2): - Track One (123)', $display);
        self::assertStringContainsString('Skipped 1 track(s) below est. ODG -2 (quality filter).', $display);
        self::assertStringNotContainsString('Failed tracks:', $display);
        self::assertStringNotContainsString('The following playlists had errors:', $display);
        self::assertStringContainsString(
            'yt-dlp exited with code 1 (tracks below the quality threshold); continuing.',
            $display
        );
        self::assertStringContainsString('All done.', $display);
    }

    public function testMinOdgInvalidModeFails(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-2', '--min-odg-mode' => 'bogus'],
            ['interactive' => false]
        );

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString(
            'Invalid --min-odg-mode / MIN_ODG_MODE value: bogus',
            $this->display($tester)
        );
    }

    public function testMinOdgInvalidValueFails(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute($this->options() + ['--min-odg' => 'abc'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --min-odg / MIN_ODG value: abc', $this->display($tester));
    }

    public function testMinOdgModeCliSuppressesPrompt(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        // both CLI options given → zero prompts (any prompt would abort on the empty input stream)
        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-2', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertArrayNotHasKey('min_odg', $this->savedConfig());
    }

    public function testMinOdgModePromptDefaultsToSavedValue(): void
    {
        file_put_contents($this->configPath, json_encode(['min_odg' => -2, 'min_odg_mode' => 'filter']));
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        // empty input accepts the saved default (filter)
        $tester->setInputs(['']);
        $exit = $tester->execute($this->options(), ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran('acodec^=mp3][abr>=128]'));
        self::assertSame('filter', $this->savedConfig()['min_odg_mode'] ?? null);
    }

    public function testMinOdgOutOfRangeValueFails(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute($this->options() + ['--min-odg' => '2'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Invalid --min-odg / MIN_ODG value: 2', $this->display($tester));
    }

    public function testMinOdgPromptAcceptsTierOption(): void
    {
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        // tier 2 = Semi-Pro (ODG ≥ -1.0) + mode prompt (filter)
        $tester->setInputs(['2', 'filter']);
        $exit = $tester->execute($this->options() + ['--formats' => 'original,mp3'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Semi-Pro Performance Minimum', $this->display($tester));
        self::assertTrue($fake->ran('acodec^=mp3][abr>=192]'));
        self::assertEquals(-1, $this->savedConfig()['min_odg'] ?? null);
    }

    public function testMinOdgPromptEmptyAnswerPersistsOff(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        // empty answer = off; no mode prompt may follow
        $tester->setInputs(['']);
        $exit = $tester->execute($this->options() + ['--formats' => 'original,mp3'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $config = $this->savedConfig();
        self::assertArrayHasKey('min_odg', $config);
        self::assertNull($config['min_odg']);

        // second run: min_odg is configured (as off) — no prompt, no inputs needed
        $tester2 = $this->makeTester((new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON));
        $exit2 = $tester2->execute($this->options() + ['--formats' => 'original,mp3'], ['interactive' => true]);
        self::assertSame(Command::SUCCESS, $exit2);
    }

    public function testMinOdgPromptSuppressedByConfigKey(): void
    {
        file_put_contents($this->configPath, json_encode(['min_odg' => -2]));
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        // only the mode prompt fires; empty input accepts the default (warn)
        $tester->setInputs(['']);
        $exit = $tester->execute($this->options(), ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(-2, $this->savedConfig()['min_odg'] ?? null);
        self::assertSame('warn', $this->savedConfig()['min_odg_mode'] ?? null);
    }

    public function testMinOdgPromptSuppressedByEnv(): void
    {
        file_put_contents(
            $this->workDir.'/.env',
            "PAUSE_BETWEEN=0\nFORMATS=original,mp3\nMIN_ODG=-2\n"
        );
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        // only the mode prompt fires (min-odg comes from env)
        $tester->setInputs(['warn']);
        $exit = $tester->execute($this->options() + ['--formats' => 'original,mp3'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        // env values are never persisted — only prompt answers are
        self::assertArrayNotHasKey('min_odg', $this->savedConfig());
        self::assertSame('warn', $this->savedConfig()['min_odg_mode'] ?? null);
    }

    public function testMinOdgPromptedWhenUnconfigured(): void
    {
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        // min-odg prompt (custom -1) + mode prompt (filter)
        $tester->setInputs(['-1', 'filter']);
        $exit = $tester->execute($this->options() + ['--formats' => 'original,mp3'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran('acodec^=mp3][abr>=192]'));
        $config = $this->savedConfig();
        self::assertEquals(-1, $config['min_odg'] ?? null);
        self::assertSame('filter', $config['min_odg_mode'] ?? null);
        $display = $this->display($tester);
        self::assertStringContainsString('(--min-odg | MIN_ODG | config: min_odg)', $display);
        self::assertStringContainsString('(--min-odg-mode | MIN_ODG_MODE | config: min_odg_mode)', $display);
    }

    public function testMinOdgWarnDeduplicatesTrackAcrossPlaylists(): void
    {
        // the same library track referenced by two playlists → one evaluation, one warn line
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "https://soundcloud.com/dj/sets/my-list\nhttps://soundcloud.com/dj/sets/other-list\n"
        );
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":64,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('1 track(s) below est. ODG -2 in the library:', $display);
        self::assertSame(1, substr_count($display, '(123): 64 kbps mp3, est. ODG -3.7'));
    }

    public function testMinOdgWarnFallsBackToFilenameTitle(): void
    {
        // no entry title AND no .info.json (pre-sidecar download) → codec/bitrate probed,
        // title recovered from the "{id} - {title}.{ext}" library filename
        $playlistJson = '{"title":"My List","id":"pl1","uploader":"DJ",'
            .'"entries":[{"id":"123","title":""}]}';
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Filename Title.opus', 'opusdata');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, $playlistJson)
            ->on('--download-archive', 0, "SEEN:Filename Title\nDONE:123\n")
            ->on('bit_rate', 0, "codec_name=opus\nbit_rate=61800\n")
            ->on('sample_rate', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString(
            'Filename Title (123): 61.8 kbps opus, est. ODG -2.07',
            $this->display($tester)
        );
    }

    public function testMinOdgWarnFallsBackToInfoJsonTitle(): void
    {
        // playlist entry without a title (e.g. SoundCloud set entries) → info.json title used
        $playlistJson = '{"title":"My List","id":"pl1","uploader":"DJ",'
            .'"entries":[{"id":"123","title":""}]}';
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Sidecar Title","uploader":"DJ","abr":64,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, $playlistJson)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString(
            'Sidecar Title (123): 64 kbps mp3, est. ODG -3.7',
            $this->display($tester)
        );
    }

    public function testMinOdgWarnListsEveryPlaylistForTrackInMultiplePlaylists(): void
    {
        // same library track referenced by two differently-named playlists — the summary must
        // list both, not just the first one encountered (regression: used to dedup to first only)
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "# alias: Playlist A\nhttps://soundcloud.com/dj/sets/my-list\n"
            .'# alias: Playlist B'."\nhttps://soundcloud.com/dj/sets/other-list\n"
        );
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":64,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertSame(1, substr_count($display, '(123): 64 kbps mp3, est. ODG -3.7'));
        self::assertStringContainsString('[Playlist A, Playlist B]', $display);
    }

    public function testMinOdgWarnListsLowQualityTracks(): void
    {
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":64,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        // warn mode leaves the download format untouched
        self::assertTrue($fake->ran("'bestaudio/best'"));
        self::assertFalse($fake->ran('abr>='));
        self::assertStringContainsString('1 track(s) below est. ODG -2 in the library:', $display);
        self::assertStringContainsString('Track One (123): 64 kbps mp3, est. ODG -3.7', $display);
        self::assertStringContainsString('All done.', $display);
        // warn mode keeps the track in the playlist
        $m3u = (string)file_get_contents($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
        self::assertStringContainsString('123 - Track One.mp3', $m3u);
    }

    public function testMinOdgWarnProbesSpotifyBitrate(): void
    {
        file_put_contents($this->workDir.'/playlists.txt', self::SPOTIFY_URL."\n");
        $this->seedSpotifyWorkspace();
        $fake = (new FakeProcessRunner())
            ->on('bit_rate', 0, "codec_name=mp3\nbit_rate=128000\n")
            ->on('sample_rate', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-1'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('Spot Track (sp123): 128 kbps mp3, est. ODG -2', $display);
        // warn mode still converts and playlists the track
        $m3u = (string)file_get_contents(
            $this->workDir.'/out/playlists/Spotify - My Spotify List - mp3.m3u8'
        );
        self::assertStringContainsString('sp123 - Spot Track.mp3', $m3u);
    }

    public function testMinOdgWarnWorksWithOriginalFormatOnly(): void
    {
        file_put_contents($this->workDir.'/.env', "PAUSE_BETWEEN=0\nFORMATS=original\n");
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents(
            $originalDir.'/123 - Track One.info.json',
            '{"title":"Track One","uploader":"DJ","abr":64,"acodec":"mp3"}'
        );

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--min-odg' => '-2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString(
            'Track One (123): 64 kbps mp3, est. ODG -3.7',
            $this->display($tester)
        );
        // preflight runs "ffmpeg -version", but no conversion may happen
        self::assertFalse($fake->ran('123 - Track One.mp3'));
    }

    public function testMissingSpotdlBinaryFailsPreflight(): void
    {
        file_put_contents($this->workDir.'/playlists.txt', self::SPOTIFY_URL."\n");
        $fake = (new FakeProcessRunner())->on('--version', 127);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

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

        $exit = $tester->execute($this->options(), ['interactive' => false]);

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

    public function testMp3BitrateCliOverridesDefault(): void
    {
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents($originalDir.'/123 - Track One.info.json', '{"title":"Track One","uploader":"DJ"}');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options() + ['--mp3-bitrate' => '192'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran("'-b:a' '192k'"));
    }

    public function testMp3ModeCliOverridesDefaultToVbr(): void
    {
        $originalDir = $this->workDir.'/out/library/original';
        mkdir($originalDir, 0777, true);
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents($originalDir.'/123 - Track One.info.json', '{"title":"Track One","uploader":"DJ"}');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--mp3-mode' => 'vbr', '--mp3-quality' => '2'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran("'-q:a' '2'"));
        self::assertFalse($fake->ran("'-b:a'"));
    }

    public function testNonInteractiveNeverPrompts(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        // min-odg stays off, nothing is persisted
        self::assertFalse($fake->ran('abr>='));
        self::assertFileDoesNotExist($this->configPath);
    }

    public function testNonInteractiveRequiresInputAndOut(): void
    {
        $tester = $this->makeTester(new FakeProcessRunner());

        $exit = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('--input and --out are required', $this->display($tester));
    }

    public function testPerToolCookiesOverrideGeneric(): void
    {
        $generic = $this->workDir.'/cookies.txt';
        $spotdl = $this->workDir.'/yt-music-cookies.txt';
        file_put_contents($generic, "# generic\n");
        file_put_contents($spotdl, "# yt music\n");
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "https://soundcloud.com/dj/sets/my-list\n".self::SPOTIFY_URL."\n"
        );
        $this->seedSpotifyWorkspace();
        $originalDir = $this->workDir.'/out/library/original';
        file_put_contents($originalDir.'/123 - Track One.wav', 'RIFFdata');
        file_put_contents($originalDir.'/123 - Track One.info.json', '{"title":"Track One","uploader":"DJ"}');

        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n")
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute(
            $this->options() + ['--cookies' => $generic, '--spotdl-cookies' => $spotdl],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        // yt-dlp keeps the generic file, spotdl gets its specific one
        self::assertTrue($fake->ran("'--cookies' '$generic'"));
        self::assertTrue($fake->ran("'--cookie-file' '$spotdl'"));
        self::assertFalse($fake->ran("'--cookie-file' '$generic'"));
    }

    public function testPlainCommentDirectlyAboveUrlHasNoEffectOnFolderName(): void
    {
        // Regression guard: config/playlists.txt uses plain "# Ciul"/"# Tek"-style comments
        // directly above single URLs as human-only section headers — these must never be
        // reinterpreted as aliases now that the "# alias: ..." marker exists.
        file_put_contents(
            $this->workDir.'/playlists.txt',
            "# Ciul\nhttps://soundcloud.com/dj/sets/my-list\n"
        );
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

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->workDir.'/out/playlists/DJ - My List - mp3.m3u8');
    }

    public function testPlaylistFetchFailureIsReportedAndContinues(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 1, '');
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('failed to fetch playlist info', $display);
        self::assertStringContainsString('The following playlists had errors:', $display);
        self::assertStringContainsString('All done.', $display);
        self::assertFalse($fake->ran('--download-archive'));
    }

    public function testPlaylistLayoutPerFormatGroupsAllPlaylistsInOneDirectory(): void
    {
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

        $exit = $tester->execute(
            $this->options() + ['--playlist-layout' => 'per-format'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFileExists($this->workDir.'/out/playlists/mp3/DJ - My List.m3u8');
        self::assertFileExists($this->workDir.'/out/playlists/original/DJ - My List.m3u8');
    }

    public function testPlaylistLayoutPerPlaylistGroupsAllFormatsInOneDirectory(): void
    {
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

        $exit = $tester->execute(
            $this->options() + ['--playlist-layout' => 'per-playlist'],
            ['interactive' => false]
        );

        self::assertSame(Command::SUCCESS, $exit);
        $mp3Path = $this->workDir.'/out/playlists/DJ - My List/mp3.m3u8';
        $originalPath = $this->workDir.'/out/playlists/DJ - My List/original.m3u8';
        self::assertFileExists($mp3Path);
        self::assertFileExists($originalPath);
        // one directory level deeper than flat mode, so the relative track path gains an extra ../
        self::assertStringContainsString(
            '../../library/mp3/123 - Track One.mp3',
            (string)file_get_contents($mp3Path)
        );
    }

    public function testPlaylistLayoutPromptSuppressedByConfigKey(): void
    {
        file_put_contents($this->configPath, json_encode(['playlist_layout' => 'per-playlist']));
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        // no playlist-layout input needed — only the (suppressed) min-odg prompts would otherwise fire
        $exit = $tester->execute(
            $this->options() + ['--min-odg' => '-4', '--min-odg-mode' => 'warn'],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('per-playlist', $this->savedConfig()['playlist_layout'] ?? null);
    }

    public function testPlaylistLayoutPromptedWhenUnconfigured(): void
    {
        $fake = (new FakeProcessRunner())
            ->on("'-J'", 0, self::PLAYLIST_JSON)
            ->on('--download-archive', 0, "SEEN:Track One\nDONE:123\n");
        $tester = $this->makeTester($fake);

        // suppress the unrelated formats/min-odg/low-quality-group-by prompts; only the
        // playlist-layout prompt needs an input
        $tester->setInputs(['per-format']);
        $exit = $tester->execute(
            $this->options() + [
                '--formats' => 'original,mp3',
                '--min-odg' => '-4',
                '--min-odg-mode' => 'warn',
                '--low-quality-group-by' => 'tier',
            ],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('per-format', $this->savedConfig()['playlist_layout'] ?? null);
        self::assertStringContainsString(
            '(--playlist-layout | PLAYLIST_LAYOUT | config: playlist_layout)',
            $this->display($tester)
        );

        // second run: playlist_layout is configured — no prompt, no inputs needed
        $tester2 = $this->makeTester((new FakeProcessRunner())->on("'-J'", 0, self::PLAYLIST_JSON));
        $exit2 = $tester2->execute(
            $this->options() + [
                '--formats' => 'original,mp3',
                '--min-odg' => '-4',
                '--min-odg-mode' => 'warn',
                '--low-quality-group-by' => 'tier',
            ],
            ['interactive' => true]
        );

        self::assertSame(Command::SUCCESS, $exit2);
    }

    public function testSpotdlCookiesEnvAppliesToSpotdlOnly(): void
    {
        $spotdl = $this->workDir.'/yt-music-cookies.txt';
        file_put_contents($spotdl, "# yt music\n");
        file_put_contents($this->workDir.'/playlists.txt', self::SPOTIFY_URL."\n");
        $this->seedSpotifyWorkspace();
        putenv('SPOTDL_COOKIE_FILE='.$spotdl);

        $fake = (new FakeProcessRunner())->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($fake->ran("'--cookie-file' '$spotdl'"));
        self::assertFalse($fake->ran("'--cookies' '$spotdl'"));
    }

    public function testSpotdlFailureWarnsAndContinues(): void
    {
        file_put_contents($this->workDir.'/playlists.txt', self::SPOTIFY_URL."\n");
        $this->seedSpotifyWorkspace();
        $fake = (new FakeProcessRunner())
            ->on("'--bitrate'", 3)
            ->on('ffprobe', 0, "44100\n");
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

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

        $exit = $tester->execute($this->options(), ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $this->display($tester);
        self::assertStringContainsString('yt-dlp exited with code 5 for originals; continuing.', $display);
        self::assertStringContainsString('Download: 0 new, 0 already in archive, 1 failed', $display);
        self::assertStringContainsString('Failed tracks: - Track One (123)', $display);
        self::assertStringContainsString(
            'Skipped playlists (not downloaded): - Tekno Collection (949270006)',
            $display
        );
        self::assertStringContainsString('The following playlists had errors:', $display);
    }

    public function testYtdlpOnlyInputNeverInvokesSpotdl(): void
    {
        $fake = (new FakeProcessRunner())->on("'-J'", 1);
        $tester = $this->makeTester($fake);

        $exit = $tester->execute($this->options(), ['interactive' => false]);

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
        $envKeys = [
            'DOTENV_PATH',
            'PAUSE_BETWEEN',
            'FORMATS',
            'MIN_ODG',
            'MIN_ODG_MODE',
            'LOW_QUALITY_GROUP_BY',
            'PLAYLISTS_DIR',
            'COOKIES_FILE',
            'YTDLP_COOKIE_FILE',
            'SPOTDL_COOKIE_FILE',
        ];
        foreach ($envKeys as $key) {
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
