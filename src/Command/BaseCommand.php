<?php

declare(strict_types=1);

namespace App\Command;

use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

abstract class BaseCommand extends Command
{
    /**
     * Lenient boolean for CLI/env values: 1/true/yes/y/on (case-insensitive) are true,
     * everything else is false.
     */
    protected static function parseBoolLike(?string $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * Choice question whose default is a saved value (string) or index (int);
     * an unknown or absent saved value falls back to index 0.
     */
    protected function askChoice(
        InputInterface $input,
        OutputInterface $output,
        string $label,
        array $choices,
        string|int|null $default = null,
        string $suffix = '',
    ): string {
        $idx = is_int($default) ? $default : 0;
        if (is_string($default)) {
            $found = array_search($default, $choices, true);
            $idx = $found !== false ? $found : 0;
        }
        $q = new ChoiceQuestion(
            "<question>$label:</question> [<info>{$choices[$idx]}</info>]".$suffix,
            $choices,
            $idx
        );

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return (string)$helper->ask($input, $output, $q);
    }

    /**
     * Confirmation question respecting a skip-confirm flag: when $skipConfirm
     * is true the question is not asked and $skipValue is returned.
     */
    protected function askConfirmation(
        InputInterface $input,
        OutputInterface $output,
        string $prompt,
        bool $default,
        bool $skipConfirm = false,
        bool $skipValue = true,
        string $trueAnswerRegex = '/^y/i',
    ): bool {
        if ($skipConfirm) {
            return $skipValue;
        }
        $q = new ConfirmationQuestion($prompt, $default, $trueAnswerRegex);

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return (bool)$helper->ask($input, $output, $q);
    }

    /**
     * Free-text question with optional saved default and validator.
     * Renders "$label [<info>$default</info>]: " (default shown only when non-empty).
     */
    protected function askText(
        InputInterface $input,
        OutputInterface $output,
        string $label,
        ?string $default = null,
        ?callable $validator = null,
    ): mixed {
        $prompt = $label
            .($default !== null && $default !== '' ? " [<info>$default</info>]" : '')
            .': ';
        $q = new Question($prompt, $default);
        if ($validator !== null) {
            $q->setValidator($validator);
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return $helper->ask($input, $output, $q);
    }

    protected function loadConfig(): array
    {
        return $this->loadConfigFrom($this->getConfigPath());
    }

    protected function loadConfigFrom(string $path): array
    {
        $result = [];
        if (is_file($path)) {
            try {
                $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $result = is_array($data) ? $data : [];
            } catch (JsonException) {
                // malformed config — return empty
            }
        }

        return $result;
    }

    abstract protected function getConfigPath(): string;

    /**
     * Minimal .env loader: DOTENV_PATH override, then cwd, then repo root — first file wins.
     * Supports quoted values and ${VAR} interpolation from the current environment.
     */
    protected function loadDotenv(): void
    {
        $candidates = [
            getenv('DOTENV_PATH') ?: null,
            getcwd().DIRECTORY_SEPARATOR.'.env',
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env',
        ];
        foreach (array_filter($candidates) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if ($v !== '' && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with(
                                $v,
                                "'"
                            )))) {
                    $v = substr($v, 1, -1);
                }
                $v = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/i', static function ($m) {
                    return getenv($m[1]) !== false ? (string)getenv($m[1]) : '';
                }, $v);
                putenv("$k=$v");
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
            break;
        }
    }

    /**
     * Layered parameter resolution: CLI option → env var → config key → default.
     * Empty-string env/config values count as unset.
     */
    protected function resolveParam(
        ?string $cliValue,
        string $envVar,
        array $config,
        string $configKey,
        ?string $default = null,
    ): ?string {
        if ($cliValue !== null) {
            return $cliValue;
        }
        $env = getenv($envVar);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (isset($config[$configKey]) && $config[$configKey] !== '') {
            return (string)$config[$configKey];
        }

        return $default;
    }

    protected function saveConfig(array $config): void
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->getConfigPath(), $json."\n");
    }
}
