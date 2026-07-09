<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Command\UsbSetupCommand;
use App\Process\ProcessRunner;
use RuntimeException;

/**
 * UsbSetupCommand with the environment seams overridden for tests: config path redirected away
 * from config/usb-setup.json, root check bypassed, and partition polling replaced by a lookup
 * so tests never wait on file_exists().
 */
final class TestableUsbSetupCommand extends UsbSetupCommand
{
    /** @var list<string> partitions waitForPartition treats as present */
    public array $existingPartitions = [];

    public function __construct(private readonly string $configPath, ?ProcessRunner $runner = null)
    {
        parent::__construct($runner);
    }

    protected function getConfigPath(): string
    {
        return $this->configPath;
    }

    protected function isRoot(): bool
    {
        return true;
    }

    protected function waitForPartition(string $part, int $timeoutSec = 10): void
    {
        if (!in_array($part, $this->existingPartitions, true)) {
            throw new RuntimeException("Partition {$part} did not appear within {$timeoutSec}s.");
        }
    }
}
