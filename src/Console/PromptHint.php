<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Renders the "how to skip this prompt next time" hint appended inline to interactive
 * questions, pointing at the CLI option / env var / config key that resolves the same value.
 */
final class PromptHint
{
    /** Shared hint for plain yes/no safety confirmations — only --yes/-y (USB_YES) skips these. */
    public static function yesFlag(): string
    {
        return self::build('--yes, -y', 'USB_YES');
    }

    public static function build(?string $cliOption = null, ?string $envVar = null, ?string $configKey = null): string
    {
        $parts = array_filter([$cliOption, $envVar, $configKey !== null ? "config: $configKey" : null]);

        return $parts === [] ? '' : ' <comment>('.implode(' | ', $parts).')</comment>';
    }
}
