<?php

declare(strict_types=1);

namespace App\Tests\Playlists;

use App\Playlists\DownloadArchive;
use PHPUnit\Framework\TestCase;

final class DownloadArchiveTest extends TestCase
{
    public function testCountArchivedCountsOnlyKnownIds(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
            ['id' => '25970122', 'title' => 'B'],
            ['id' => '999275440', 'title' => 'C'],
        ];
        $archivedIds = ['30523920' => true, '25970122' => true, 'unrelated' => true];

        self::assertSame(2, DownloadArchive::countArchived($entries, $archivedIds));
    }

    public function testCountArchivedEmptyArchiveIsZero(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
        ];

        self::assertSame(0, DownloadArchive::countArchived($entries, []));
    }

    public function testMissingEntriesAllOkIsEmpty(): void
    {
        $entries = [['id' => '30523920', 'title' => 'A']];

        self::assertSame([], DownloadArchive::missingEntries($entries, ['30523920' => true]));
    }

    public function testMissingEntriesReturnsUnmatchedOnly(): void
    {
        $entries = [
            ['id' => '30523920', 'title' => 'A'],
            ['id' => '25970122', 'title' => 'B'],
            ['id' => '999275440', 'title' => 'C'],
        ];
        $okIds = ['30523920' => true, '999275440' => true];

        self::assertSame(
            [['id' => '25970122', 'title' => 'B']],
            DownloadArchive::missingEntries($entries, $okIds)
        );
    }
}
