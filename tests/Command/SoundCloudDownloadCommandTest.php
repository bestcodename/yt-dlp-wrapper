<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SoundCloudDownloadCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SoundCloudDownloadCommandTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function safeNameProvider(): array
    {
        return [
            'strips slashes' => ['Artist / Title', 'Artist _ Title'],
            'collapses whitespace' => ['a    b', 'a b'],
            'trims edges' => ['  hello  ', 'hello'],
            'keeps unicode letters' => ['Björk – Jóga', 'Björk _ Jóga'],
        ];
    }

    /**
     * @return array<string, array{string, float, float}>
     */
    public static function sleepRequestsProvider(): array
    {
        return [
            'fixed numeric' => ['3', 3.0, 3.0],
            'range stays within bounds' => ['2-5', 2.0, 5.0],
            'reversed range normalizes' => ['5-2', 2.0, 5.0],
            'colon range' => ['1:4', 1.0, 4.0],
            'garbage falls back to 2' => ['nonsense', 2.0, 2.0],
        ];
    }

    /**
     * @return array<string, array{int, int|null}>
     */
    public static function targetSampleRateProvider(): array
    {
        return [
            'supported 44.1k untouched' => [44100, null],
            'supported 48k untouched' => [48000, null],
            'supported 96k untouched' => [96000, null],
            '32k -> 44.1k' => [32000, 44100],
            '22.05k -> 44.1k' => [22050, 44100],
            '88.2k -> 96k' => [88200, 96000],
            '176.4k -> 96k' => [176400, 96000],
            '192k -> 96k' => [192000, 96000],
            '45k -> 48k' => [45000, 48000],
        ];
    }

    public function testCountArchivedCountsOnlyKnownIds(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
            ['id' => '25970122', 'title' => 'B'],
            ['id' => '999275440', 'title' => 'C'],
        ];
        $archivedIds = ['30523920' => true, '25970122' => true, 'unrelated' => true];

        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'countArchived');

        self::assertSame(2, $method->invoke(null, $entries, $archivedIds));
    }

    public function testCountArchivedEmptyArchiveIsZero(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
        ];

        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'countArchived');

        self::assertSame(0, $method->invoke(null, $entries, []));
    }

    public function testLoadArchiveIdsMissingFileIsEmpty(): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'loadArchiveIds');

        self::assertSame([], $method->invoke(null, '/nonexistent/archive/original.txt'));
    }

    public function testLoadArchiveIdsParsesLastToken(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arch');
        file_put_contents(
            $file,
            "soundcloud 30523920\nsoundcloud 25970122\n\n  youtube dQw4w9WgXcQ  \n"
        );

        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'loadArchiveIds');
        /** @var array<string, true> $ids */
        $ids = $method->invoke(null, $file);
        unlink($file);

        self::assertSame(
            ['30523920' => true, '25970122' => true, 'dQw4w9WgXcQ' => true],
            $ids
        );
    }

    #[DataProvider('sleepRequestsProvider')]
    public function testResolveSleepRequests(string $raw, float $min, float $max): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'resolveSleepRequests');
        $result = (float)$method->invoke(new SoundCloudDownloadCommand(), $raw);

        self::assertGreaterThanOrEqual($min, $result);
        self::assertLessThanOrEqual($max, $result);
    }

    #[DataProvider('safeNameProvider')]
    public function testSafeName(string $input, string $expected): void
    {
        $method = new ReflectionMethod(SoundCloudDownloadCommand::class, 'safeName');
        self::assertSame($expected, $method->invoke(null, $input));
    }

    #[DataProvider('targetSampleRateProvider')]
    public function testTargetSampleRate(int $src, ?int $expected): void
    {
        self::assertSame($expected, SoundCloudDownloadCommand::targetSampleRate($src));
    }
}
