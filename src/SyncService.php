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
        [$idToRow, $freeSlots] = $this->loadIndex();
        $next = $this->sheets->toSheetValues($item);
        $current = $idToRow[$item->bitrixId] ?? null;

        if ($current !== null) {
            if ($current['values'] === $next) {
                return 'unchanged';
            }
            $this->sheets->batchUpdateRows([
                ['rowNumber' => $current['rowNumber'], 'values' => $next],
            ]);
            return 'updated';
        }

        if ($freeSlots === []) {
            throw new \RuntimeException(
                'No free rows on "' . $this->config->sheetName . '" between '
                . $this->config->dataStartRow . ' and ' . $this->config->dataEndRow
            );
        }

        $this->sheets->batchUpdateRows([
            ['rowNumber' => $freeSlots[0]['rowNumber'], 'values' => $next],
        ]);
        return 'added';
    }

    public function markDeleted(int $bitrixId): string
    {
        [$idToRow] = $this->loadIndex();
        $current = $idToRow[$bitrixId] ?? null;
        if ($current === null) {
            return 'missing';
        }
        $parsed = $this->sheets->parseSheetValues($current['values']);
        if (($parsed['status'] ?? '') === $this->config->deletedStatus) {
            return 'unchanged';
        }
        $marked = $current['values'];
        $marked[8] = $this->config->deletedStatus;
        $this->sheets->batchUpdateRows([
            ['rowNumber' => $current['rowNumber'], 'values' => $marked],
        ]);
        return 'markedDeleted';
    }

    /** @return array{0: array<int, array{rowNumber:int, values:list<string>}>, 1: list<array{rowNumber:int, values:list<string>}>} */
    private function loadIndex(): array
    {
        $this->sheets->ensureHeader();
        $existing = $this->sheets->readDataRows();
        $idToRow = [];
        $freeSlots = [];

        foreach ($existing as $row) {
            $parsed = $this->sheets->parseSheetValues($row['values']);
            if ($parsed['bitrixId'] !== '') {
                $bitrixId = (int) $parsed['bitrixId'];
                if ($bitrixId > 0) {
                    $idToRow[$bitrixId] = $row;
                }
                continue;
            }
            $title = trim((string) ($row['values'][1] ?? ''));
            if ($title === '') {
                $freeSlots[] = $row;
            }
        }

        return [$idToRow, $freeSlots];
    }
}
