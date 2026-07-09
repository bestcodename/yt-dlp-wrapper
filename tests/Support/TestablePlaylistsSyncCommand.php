<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Command\PlaylistsSyncCommand;
use App\Process\ProcessRunner;

/**
 * PlaylistsSyncCommand with the config path redirected away from
 * config/playlists-sync.json so tests never read or write the real file.
 */
final class TestablePlaylistsSyncCommand extends PlaylistsSyncCommand
{
    public function __construct(
        private readonly string $configPath,
        ?ProcessRunner $runner = null,
        private readonly ?string $legacyConfigPath = null
    ) {
        parent::__construct($runner);
    }

    protected function getConfigPath(): string
    {
        return $this->configPath;
    }

    protected function getLegacyConfigPath(): ?string
    {
        return $this->legacyConfigPath;
    }
}
