<?php

declare(strict_types=1);

namespace App\Tests\Process;

use App\Process\BinaryChecker;
use App\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BinaryCheckerTest extends TestCase
{
    public function testResolvesViaWhichWhenPresent(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('which ', 0, "/usr/bin/lsblk\n");
        $checker = new BinaryChecker($fake);

        self::assertSame('/usr/bin/lsblk', $checker('lsblk'));
        self::assertSame(["which 'lsblk' 2>/dev/null"], $fake->commands);
    }

    public function testThrowsViaWhichWhenMissing(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('which ', 1, '');
        $checker = new BinaryChecker($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Required binary not found: lsblk/');
        $checker('lsblk');
    }

    public function testThrowsViaWhichWhenOutputEmpty(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('which ', 0, '');
        $checker = new BinaryChecker($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Required binary not found: lsblk/');
        $checker('lsblk');
    }

    public function testVersionArgMissingThrows(): void
    {
        $fake = new FakeProcessRunner();
        $fake->on('missing-bin', 127, '');
        $checker = new BinaryChecker($fake);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing dependency: missing-bin/');
        $checker('missing-bin', '--version');
    }

    public function testVersionArgPresentPasses(): void
    {
        $fake = new FakeProcessRunner();
        $checker = new BinaryChecker($fake);

        self::assertSame('some-bin', $checker('some-bin', '--version'));
        self::assertSame(['some-bin --version 2>&1'], $fake->commands);
    }
}
