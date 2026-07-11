<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BaseCommand;
use PHPUnit\Framework\TestCase;

final class BaseCommandTest extends TestCase
{
    private string $configPath;

    public function testEnsureDirectoryCreatesMissingDirectory(): void
    {
        $dir = sys_get_temp_dir().'/basecmd_test_'.uniqid('', true).'/nested';
        $cmd = $this->makeCommand();

        try {
            self::assertTrue($cmd->makeDir($dir, 0755));
            self::assertDirectoryExists($dir);
        } finally {
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }

    public function testEnsureDirectoryReturnsTrueWhenAlreadyExists(): void
    {
        $dir = sys_get_temp_dir();

        self::assertTrue($this->makeCommand()->makeDir($dir));
    }

    public function testLoadConfigMalformedJsonReturnsEmpty(): void
    {
        file_put_contents($this->configPath, '{not json');

        self::assertSame([], $this->makeCommand()->load());
    }

    public function testLoadConfigMissingFileReturnsEmpty(): void
    {
        @unlink($this->configPath);

        self::assertSame([], $this->makeCommand()->load());
    }

    private function makeCommand(): BaseCommand
    {
        return new class($this->configPath) extends BaseCommand {
            public function __construct(private readonly string $configPath)
            {
                parent::__construct(name: 'test:config');
            }

            protected function getConfigPath(): string
            {
                return $this->configPath;
            }

            /** @return array<string, mixed> */
            public function load(): array
            {
                return $this->loadConfig();
            }

            /** @param array<string, mixed> $config */
            public function save(array $config): void
            {
                $this->saveConfig($config);
            }

            /** @param array<string, mixed> $updates */
            public function update(array $updates): void
            {
                $this->updateConfig($updates);
            }

            public function makeDir(string $dir, int $mode = 0755): bool
            {
                return $this->ensureDirectory($dir, $mode);
            }
        };
    }

    public function testLoadConfigNonObjectJsonReturnsEmpty(): void
    {
        file_put_contents($this->configPath, '"just a string"');

        self::assertSame([], $this->makeCommand()->load());
    }

    public function testSaveOverwritesPreviousContent(): void
    {
        $cmd = $this->makeCommand();
        $cmd->save(['device' => '/dev/sda', 'device_name' => 'usb Verbatim STORE N GO']);

        // Fresh-load-then-merge is how callers must persist partial updates — a stale snapshot
        // merged over a newer file loses keys written in between (the device_name clobber bug).
        $cmd->save(array_merge($cmd->load(), ['device' => '/dev/sdb']));

        self::assertSame(
            ['device' => '/dev/sdb', 'device_name' => 'usb Verbatim STORE N GO'],
            $cmd->load()
        );
    }

    public function testSaveThenLoadRoundtrip(): void
    {
        $cmd = $this->makeCommand();
        $config = ['device' => '/dev/sdb', 'persistence_mib' => 4090, 'install_ventoy' => true];

        $cmd->save($config);

        self::assertSame($config, $cmd->load());
    }

    public function testUpdateConfigMergesOntoFreshLoad(): void
    {
        $cmd = $this->makeCommand();
        $cmd->save(['device' => '/dev/sda', 'device_name' => 'usb Verbatim STORE N GO']);

        $cmd->update(['device' => '/dev/sdb']);

        self::assertSame(
            ['device' => '/dev/sdb', 'device_name' => 'usb Verbatim STORE N GO'],
            $cmd->load()
        );
    }

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'basecmd_test_').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
    }
}
