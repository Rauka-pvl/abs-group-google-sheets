<?php

declare(strict_types=1);

namespace App;

final class SyncService
{
    public function __construct(
        private readonly SheetsClient $sheets,
        private readonly Config $config,
    ) {
    }

    public function upsert(AccountingRow $item): string
    {
        $targetSheet = $item->sheetName;
        if ($targetSheet === null || $targetSheet === '') {
            // No department / unknown mapping → remove from all sheets
            $removed = $this->deleteEverywhere($item->bitrixId);
            return $removed > 0 ? 'removed_no_department' : 'skipped_no_department';
        }

        $locations = $this->findEverywhere($item->bitrixId);
        $removedFrom = [];
        foreach ($locations as $sheetName => $row) {
            if ($sheetName === $targetSheet) {
                continue;
            }
            $this->sheets->clearRow($sheetName, $row['rowNumber']);
            $removedFrom[] = $sheetName;
        }

        [$idToRow, $freeSlots] = $this->loadIndex($targetSheet);
        $next = $this->sheets->toSheetValues($item);
        $current = $idToRow[$item->bitrixId] ?? null;

        if ($current !== null) {
            if ($current['values'] === $next && $removedFrom === []) {
                return 'unchanged';
            }
            $this->sheets->batchUpdateRows($targetSheet, [
                ['rowNumber' => $current['rowNumber'], 'values' => $next],
            ]);
            return $removedFrom === [] ? 'updated' : 'moved:' . implode(',', $removedFrom) . '->' . $targetSheet;
        }

        if ($freeSlots === []) {
            $layout = $this->sheets->resolveLayout($targetSheet);
            throw new \RuntimeException(
                'No free rows on "' . $targetSheet . '" between '
                . $layout['dataStartRow'] . ' and ' . $layout['dataEndRow']
            );
        }

        $this->sheets->batchUpdateRows($targetSheet, [
            ['rowNumber' => $freeSlots[0]['rowNumber'], 'values' => $next],
        ]);

        return $removedFrom === []
            ? 'added:' . $targetSheet
            : 'moved:' . implode(',', $removedFrom) . '->' . $targetSheet;
    }

    public function deleteEverywhere(int $bitrixId): int
    {
        $locations = $this->findEverywhere($bitrixId);
        foreach ($locations as $sheetName => $row) {
            $this->sheets->clearRow($sheetName, $row['rowNumber']);
        }
        return count($locations);
    }

    /** @return array<string, array{rowNumber:int, values:list<string>}> */
    private function findEverywhere(int $bitrixId): array
    {
        $found = [];
        foreach ($this->config->managedSheets as $sheetName) {
            try {
                $existing = $this->sheets->readDataRows($sheetName);
            } catch (\Throwable) {
                continue;
            }
            foreach ($existing as $row) {
                $parsed = $this->sheets->parseSheetValues($row['values']);
                if ((int) $parsed['bitrixId'] === $bitrixId) {
                    $found[$sheetName] = $row;
                    break;
                }
            }
        }
        return $found;
    }

    /** @return array{0: array<int, array{rowNumber:int, values:list<string>}>, 1: list<array{rowNumber:int, values:list<string>}>} */
    private function loadIndex(string $sheetName): array
    {
        $this->sheets->ensureHeader($sheetName);
        $layout = $this->sheets->resolveLayout($sheetName);
        $existing = $this->sheets->readDataRows($sheetName);
        $idToRow = [];
        $occupied = [];

        foreach ($existing as $row) {
            $parsed = $this->sheets->parseSheetValues($row['values']);
            if ($parsed['bitrixId'] !== '') {
                $id = (int) $parsed['bitrixId'];
                if ($id > 0) {
                    $idToRow[$id] = $row;
                    $occupied[$row['rowNumber']] = true;
                }
                continue;
            }
            $title = trim((string) ($row['values'][1] ?? ''));
            if ($title !== '') {
                $occupied[$row['rowNumber']] = true;
            }
        }

        $freeSlots = [];
        for ($n = $layout['dataStartRow']; $n <= $layout['dataEndRow']; $n++) {
            if (!isset($occupied[$n])) {
                $freeSlots[] = [
                    'rowNumber' => $n,
                    'values' => array_fill(0, 12, ''),
                ];
            }
        }

        return [$idToRow, $freeSlots];
    }
}
