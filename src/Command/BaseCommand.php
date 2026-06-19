<?php

declare(strict_types=1);

namespace App\Command;

use JsonException;
use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command
{
    protected function loadConfig(): array
    {
        $result = [];
        $path = $this->getConfigPath();
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

    protected function saveConfig(array $config): void
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->getConfigPath(), $json."\n");
    }
}
