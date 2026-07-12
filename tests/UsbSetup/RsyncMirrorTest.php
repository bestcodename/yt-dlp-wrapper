<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\Tests\Support\FakeProcessRunner;
use App\UsbSetup\RsyncMirror;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RsyncMirrorTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool, bool, string}>
     */
    public static function buildRsyncCommandProvider(): array
    {
        $base = "rsync -rt --modify-window=1 --max-size=4090m --exclude='System Volume Information' "
            ."--exclude='.Trash-*' --exclude='.Trashes' --exclude='FOUND.[0-9][0-9][0-9]'";

        return [
            'initial copy' => [
                '/tmp/src',
                '/tmp/dst',
                false,
                false,
                $base." --inplace --outbuf=L --info=progress2 '/tmp/src/' '/tmp/dst/'",
            ],
            'update dry-run preview' => [
                '/tmp/src',
                '/tmp/dst',
                true,
                true,
                $base." --delete --dry-run --itemize-changes '/tmp/src/' '/tmp/dst/'",
            ],
            'update real run with delete' => [
                '/tmp/src',
                '/tmp/dst',
                true,
                false,
                $base." --delete --inplace --outbuf=L --info=progress2 '/tmp/src/' '/tmp/dst/'",
            ],
            'trailing slashes normalized' => [
                '/tmp/src/',
                '/tmp/dst/',
                false,
                false,
                $base." --inplace --outbuf=L --info=progress2 '/tmp/src/' '/tmp/dst/'",
            ],
        ];
    }

    /**
     * @return array<string, array{string, array{deleted: string[], added: string[], changed: string[]}}>
     */
    public static function summarizeRsyncItemizedProvider(): array
    {
        $mixed = "*deleting software/old.exe\n"
            ."*deleting software/olddir/\n"
            .">f+++++++++ new.iso\n"
            .">f.st...... persistence.dat\n"
            ."cd+++++++++ software/newdir/\n"
            ."\n"
            ."sent 1,234 bytes  received 56 bytes  2,580.00 bytes/sec\n"
            ."total size is 9,999  speedup is 7.75 (DRY RUN)\n";

        return [
            'mixed itemized output' => [
                $mixed,
                [
                    'deleted' => ['software/old.exe', 'software/olddir/'],
                    'added' => ['new.iso', 'software/newdir/'],
                    'changed' => ['persistence.dat'],
                ],
            ],
            'empty output' => [
                '',
                ['deleted' => [], 'added' => [], 'changed' => []],
            ],
            'stats footer only' => [
                "sent 60 bytes  received 12 bytes  144.00 bytes/sec\ntotal size is 0  speedup is 0.00 (DRY RUN)\n",
                ['deleted' => [], 'added' => [], 'changed' => []],
            ],
        ];
    }

    #[DataProvider('buildRsyncCommandProvider')]
    public function testBuildRsyncCommand(
        string $src,
        string $dst,
        bool $delete,
        bool $dryRun,
        string $expected
    ): void {
        self::assertSame($expected, RsyncMirror::buildRsyncCommand($src, $dst, $delete, $dryRun));
    }

    public function testMirrorDryRunFailureThrows(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('--dry-run', 12, '');
        $mirror = new RsyncMirror($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dry run failed/');
        $mirror(
            '/tmp/src',
            '/tmp/dst',
            true,
            false,
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );
    }

    private static function interactiveInput(string $answers): ArrayInput
    {
        $input = new ArrayInput([]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
        $input->setInteractive(true);

        return $input;
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    public function testMirrorRsyncFailureThrows(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('rsync', 23, '');
        $mirror = new RsyncMirror($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rsync failed \(exit 23\)/');
        $mirror(
            '/tmp/src',
            '/tmp/dst',
            false,
            false,
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );
    }

    public function testMirrorWithDeleteConfirmedRunsRealRsync(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('--dry-run', 0, "*deleting foo\n");
        $mirror = new RsyncMirror($fake);

        $result = $mirror(
            '/tmp/src',
            '/tmp/dst',
            true,
            false,
            self::interactiveInput("yes\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertTrue($result);
        self::assertCount(2, $fake->commands);
        self::assertStringNotContainsString('--dry-run', $fake->commands[1]);
        self::assertStringContainsString('--delete', $fake->commands[1]);
    }

    public function testMirrorWithDeleteDeclinedStopsBeforeRealRsync(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('--dry-run', 0, "*deleting foo\n");
        $mirror = new RsyncMirror($fake);

        $result = $mirror(
            '/tmp/src',
            '/tmp/dst',
            true,
            false,
            self::interactiveInput("no\n"),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertFalse($result);
        self::assertCount(1, $fake->commands);
        self::assertStringContainsString('--dry-run', $fake->commands[0]);
    }

    public function testMirrorWithDeleteSkipConfirmSkipsPrompt(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('--dry-run', 0, "*deleting foo\n");
        $mirror = new RsyncMirror($fake);

        $result = $mirror(
            '/tmp/src',
            '/tmp/dst',
            true,
            true,
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertTrue($result);
        self::assertCount(2, $fake->commands);
    }

    public function testMirrorWithoutDeleteRunsSingleRsync(): void
    {
        $fake = new FakeProcessRunner();
        $mirror = new RsyncMirror($fake);

        $result = $mirror(
            '/tmp/src',
            '/tmp/dst',
            false,
            false,
            self::interactiveInput(''),
            new BufferedOutput(),
            new QuestionHelper(),
            self::io()
        );

        self::assertTrue($result);
        $expected = RsyncMirror::buildRsyncCommand('/tmp/src', '/tmp/dst', false, false);
        self::assertSame([$expected], $fake->commands);
    }

    /**
     * @param array{deleted: string[], added: string[], changed: string[]} $expected
     */
    #[DataProvider('summarizeRsyncItemizedProvider')]
    public function testSummarizeRsyncItemized(string $out, array $expected): void
    {
        self::assertSame($expected, RsyncMirror::summarizeRsyncItemized($out));
    }
}
