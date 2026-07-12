<?php

declare(strict_types=1);

namespace App\Tests\UsbSetup;

use App\UsbSetup\IsoPayloadManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsoPayloadManagerTest extends TestCase
{
    /**
     * @return array<string, array{?string, int, array<array{source: string, relative: string}>,
     *     array<string, int>, int}>
     */
    public static function estimateConfigurationPayloadBytesProvider(): array
    {
        $sizes = ['/iso/debian.iso' => 3000000000, '/cache/a.exe' => 500000000, '/local/b.zip' => 250000000];
        $software = [
            ['source' => '/cache/a.exe', 'relative' => 'a.exe'],
            ['source' => '/local/b.zip', 'relative' => 'sub/b.zip'],
        ];

        return [
            'iso + persistence + software' => [
                '/iso/debian.iso',
                2048,
                $software,
                $sizes,
                3000000000 + 2048 * 1048576 + 750000000,
            ],
            'no iso: persistence not counted' => [
                null,
                2048,
                $software,
                $sizes,
                750000000,
            ],
            'iso only' => [
                '/iso/debian.iso',
                4090,
                [],
                $sizes,
                3000000000 + 4090 * 1048576,
            ],
            'nothing queued' => [null, 2048, [], $sizes, 0],
        ];
    }

    /**
     * @param array<array{source: string, relative: string}> $softwareFiles
     * @param array<string, int> $sizes
     */
    #[DataProvider('estimateConfigurationPayloadBytesProvider')]
    public function testEstimateConfigurationPayloadBytes(
        ?string $debianIso,
        int $persistenceMib,
        array $softwareFiles,
        array $sizes,
        int $expected
    ): void {
        $fileSize = static fn(string $path): int => $sizes[$path];

        self::assertSame(
            $expected,
            IsoPayloadManager::estimateConfigurationPayloadBytes($debianIso, $persistenceMib, $softwareFiles, $fileSize)
        );
    }
}
