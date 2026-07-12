<?php

declare(strict_types=1);

namespace App\Playlists;

final class DownloadArchive
{
    /**
     * Count how many playlist entries are already present in the archive id set.
     *
     * @param list<array{id: string, title: string}> $entries
     * @param array<string, true> $archivedIds
     */
    public static function countArchived(array $entries, array $archivedIds): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            if (isset($archivedIds[$entry['id']])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Playlist entries whose id is not in the given id set — i.e. tracks that were neither
     * archived before the run nor downloaded during it, which means they failed.
     *
     * @param list<array{id: string, title: string}> $entries
     * @param array<string, true> $okIds
     * @return list<array{id: string, title: string}>
     */
    public static function missingEntries(array $entries, array $okIds): array
    {
        return array_values(
            array_filter(
                $entries,
                static fn(array $entry): bool => !isset($okIds[$entry['id']])
            )
        );
    }
}
