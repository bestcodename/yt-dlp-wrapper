<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Console\PromptHint;
use PHPUnit\Framework\TestCase;

final class PromptHintTest extends TestCase
{
    public function testBuildReturnsEmptyStringWhenNothingGiven(): void
    {
        self::assertSame('', PromptHint::build());
    }

    public function testBuildWithAllThreeParts(): void
    {
        self::assertSame(
            ' <comment>(--input, -i | INPUT_FILE | config: input_file)</comment>',
            PromptHint::build('--input, -i', 'INPUT_FILE', 'input_file')
        );
    }

    public function testBuildWithCliOptionAndEnvVar(): void
    {
        self::assertSame(
            ' <comment>(--update | USB_UPDATE)</comment>',
            PromptHint::build('--update', 'USB_UPDATE')
        );
    }

    public function testBuildWithCliOptionOnly(): void
    {
        self::assertSame(' <comment>(--formats)</comment>', PromptHint::build('--formats'));
    }

    public function testBuildWithConfigKeyOnly(): void
    {
        self::assertSame(' <comment>(config: input_file)</comment>', PromptHint::build(null, null, 'input_file'));
    }

    public function testYesFlagReturnsFixedHint(): void
    {
        self::assertSame(' <comment>(--yes, -y | USB_YES)</comment>', PromptHint::yesFlag());
    }
}
