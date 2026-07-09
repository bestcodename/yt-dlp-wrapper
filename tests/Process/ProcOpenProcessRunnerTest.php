<?php

declare(strict_types=1);

namespace App\Tests\Process;

use App\Process\ProcOpenProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcOpenProcessRunnerTest extends TestCase
{
    public function testLargeOutputDrainsWithoutDeadlock(): void
    {
        [$exit, $stdout] = (new ProcOpenProcessRunner())->run('seq 1 20000');

        self::assertSame(0, $exit);
        self::assertStringContainsString("1\n", $stdout);
        self::assertStringContainsString("20000\n", $stdout);
        self::assertSame(20000, substr_count($stdout, "\n"));
    }

    public function testNullCallbacksDiscardOutputSilently(): void
    {
        [$exit, $stdout] = (new ProcOpenProcessRunner())->run("sh -c 'echo out; echo err >&2'");

        self::assertSame(0, $exit);
        self::assertSame("out\n", $stdout);
    }

    public function testOnStdoutChunksConcatenateToReturnedStdout(): void
    {
        $streamed = '';
        [, $stdout] = (new ProcOpenProcessRunner())->run(
            "sh -c 'printf a; printf b; printf c'",
            static function (string $chunk) use (&$streamed): void {
                $streamed .= $chunk;
            }
        );

        self::assertSame($stdout, $streamed);
        self::assertSame('abc', $stdout);
    }

    public function testReturnsExitCodeAndStdout(): void
    {
        [$exit, $stdout] = (new ProcOpenProcessRunner())->run("sh -c 'printf foo; exit 3'");

        self::assertSame(3, $exit);
        self::assertSame('foo', $stdout);
    }

    public function testStderrGoesToOnStderrOnly(): void
    {
        $stderr = '';
        [$exit, $stdout] = (new ProcOpenProcessRunner())->run(
            "sh -c 'echo out; echo err >&2'",
            null,
            static function (string $chunk) use (&$stderr): void {
                $stderr .= $chunk;
            }
        );

        self::assertSame(0, $exit);
        self::assertSame("out\n", $stdout);
        self::assertStringContainsString('err', $stderr);
        self::assertStringNotContainsString('err', $stdout);
    }
}
