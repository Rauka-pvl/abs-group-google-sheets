<?php

declare(strict_types=1);

namespace App;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ValueRange;

final class SheetsClient
{
    public const BUSINESS_HEADERS = [
        'Наименование',
        'Статья затрат',
        'Название поставщика',
        'Сумма',
        'Дата',
        'Комментарии',
        'Статус',
    ];

    /** J / K / L */
    public const TECH_HEADERS = ['Bitrix ID', 'Воронка', 'Отделение'];

    private const COL_COUNT = 12; // A..L

    private ?Sheets $service = null;

    /** @var array<string, array{headerRow:int, dataStartRow:int, dataEndRow:int}> */
    private array $layoutCache = [];

    public function __construct(private readonly Config $config)
    {
    }

    public function toSheetValues(AccountingRow $row): array
    {
        return [
            '',
            $row->title,
            '',
            $row->costArticle,
            $row->supplier,
            $row->amount,
            $row->date,
            $row->comments,
            $row->status,
            (string) $row->bitrixId,
            $row->categoryName,
            $row->departmentName,
        ];
    }

    /** @param list<string> $values */
    public function parseSheetValues(array $values): array
    {
        while (count($values) < self::COL_COUNT) {
            $values[] = '';
        }
        return [
            'title' => $values[1],
            'costArticle' => $values[3],
            'supplier' => $values[4],
            'amount' => $values[5],
            'date' => $values[6],
            'comments' => $values[7],
            'status' => $values[8],
            'bitrixId' => trim((string) $values[9]),
            'categoryName' => $values[10],
            'departmentName' => $values[11],
        ];
    }

    /** @return array{headerRow:int, dataStartRow:int, dataEndRow:int} */
    public function resolveLayout(string $sheetName): array
    {
        if (isset($this->layoutCache[$sheetName])) {
            return $this->layoutCache[$sheetName];
        }

        $response = $this->api()->spreadsheets_values->get(
            $this->config->spreadsheetId,
            $this->range($sheetName, 'A1:L40')
        );
        $rows = $response->getValues() ?? [];

        $headerRow = null;
        foreach ($rows as $index => $row) {
            $b = trim((string) ($row[1] ?? ''));
            if ($b === 'Наименование') {
                $headerRow = $index + 1;
                break;
            }
        }

        if ($headerRow === null) {
            $headerRow = $this->config->headerRow;
        }

        $layout = [
            'headerRow' => $headerRow,
            'dataStartRow' => $headerRow + 1,
            'dataEndRow' => $headerRow + 80,
        ];
        $this->layoutCache[$sheetName] = $layout;
        return $layout;
    }

    public function ensureHeader(string $sheetName): void
    {
        $layout = $this->resolveLayout($sheetName);
        $headerRow = $layout['headerRow'];

        $response = $this->api()->spreadsheets_values->get(
            $this->config->spreadsheetId,
            $this->range($sheetName, "A{$headerRow}:L{$headerRow}")
        );
        $header = $response->getValues()[0] ?? [];
        while (count($header) < self::COL_COUNT) {
            $header[] = '';
        }

        $isEmpty = trim(implode('', $header)) === '';
        if ($isEmpty) {
            $full = [
                '',
                self::BUSINESS_HEADERS[0],
                '',
                self::BUSINESS_HEADERS[1],
                self::BUSINESS_HEADERS[2],
                self::BUSINESS_HEADERS[3],
                self::BUSINESS_HEADERS[4],
                self::BUSINESS_HEADERS[5],
                self::BUSINESS_HEADERS[6],
                self::TECH_HEADERS[0],
                self::TECH_HEADERS[1],
                self::TECH_HEADERS[2],
            ];
            $body = new ValueRange(['values' => [$full]]);
            $this->api()->spreadsheets_values->update(
                $this->config->spreadsheetId,
                $this->range($sheetName, "A{$headerRow}:L{$headerRow}"),
                $body,
                ['valueInputOption' => 'RAW']
            );
            return;
        }

        $commentHeader = trim((string) ($header[7] ?? ''));
        $checks = [
            1 => ['Наименование'],
            3 => ['Статья затрат'],
            4 => ['Название поставщика'],
            5 => ['Сумма'],
            6 => ['Дата'],
            8 => ['Статус'],
        ];
        foreach ($checks as $index => $allowed) {
            $actual = trim((string) ($header[$index] ?? ''));
            if (!in_array($actual, $allowed, true)) {
                throw new \RuntimeException(
                    'Sheet "' . $sheetName . "\" row {$headerRow} headers do not match. "
                    . "Col " . chr(65 + $index) . " got \"{$actual}\""
                );
            }
        }
        if (!in_array($commentHeader, ['Комментарии', 'Комментарий'], true)) {
            throw new \RuntimeException(
                'Sheet "' . $sheetName . "\" row {$headerRow}: expected Комментарии/Комментарий, got \"{$commentHeader}\""
            );
        }

        $needTech = trim((string) ($header[9] ?? '')) !== self::TECH_HEADERS[0]
            || trim((string) ($header[10] ?? '')) !== self::TECH_HEADERS[1]
            || trim((string) ($header[11] ?? '')) !== self::TECH_HEADERS[2];
        if ($needTech) {
            $body = new ValueRange(['values' => [self::TECH_HEADERS]]);
            $this->api()->spreadsheets_values->update(
                $this->config->spreadsheetId,
                $this->range($sheetName, "J{$headerRow}:L{$headerRow}"),
                $body,
                ['valueInputOption' => 'RAW']
            );
        }
    }

    /** @return list<array{rowNumber:int, values:list<string>}> */
    public function readDataRows(string $sheetName): array
    {
        $layout = $this->resolveLayout($sheetName);
        $start = $layout['dataStartRow'];
        $end = $layout['dataEndRow'];
        $response = $this->api()->spreadsheets_values->get(
            $this->config->spreadsheetId,
            $this->range($sheetName, "A{$start}:L{$end}")
        );
        $rows = [];
        foreach ($response->getValues() ?? [] as $index => $row) {
            $normalized = array_map(static fn ($c) => (string) ($c ?? ''), $row);
            while (count($normalized) < self::COL_COUNT) {
                $normalized[] = '';
            }
            $rows[] = [
                'rowNumber' => $start + (int) $index,
                'values' => array_slice($normalized, 0, self::COL_COUNT),
            ];
        }
        return $rows;
    }

    /** @param list<array{rowNumber:int, values:list<string>}> $updates */
    public function batchUpdateRows(string $sheetName, array $updates): void
    {
        if ($updates === []) {
            return;
        }
        $data = [];
        foreach ($updates as $update) {
            $data[] = new ValueRange([
                'range' => $this->range($sheetName, 'A' . $update['rowNumber'] . ':L' . $update['rowNumber']),
                'values' => [$update['values']],
            ]);
        }
        $body = new BatchUpdateValuesRequest([
            'valueInputOption' => 'RAW',
            'data' => $data,
        ]);
        $this->api()->spreadsheets_values->batchUpdate($this->config->spreadsheetId, $body);
    }

    public function clearRow(string $sheetName, int $rowNumber): void
    {
        $empty = array_fill(0, self::COL_COUNT, '');
        $this->batchUpdateRows($sheetName, [
            ['rowNumber' => $rowNumber, 'values' => $empty],
        ]);
    }

    private function api(): Sheets
    {
        if ($this->service instanceof Sheets) {
            return $this->service;
        }
        if (!is_file($this->config->googleServiceAccountPath)) {
            throw new \RuntimeException(
                'Google service account file not found: ' . $this->config->googleServiceAccountPath
            );
        }
        $client = new GoogleClient();
        $client->setAuthConfig($this->config->googleServiceAccountPath);
        $client->setScopes([Sheets::SPREADSHEETS]);
        $this->service = new Sheets($client);
        return $this->service;
    }

    private function range(string $sheetName, string $a1): string
    {
        $escaped = str_replace("'", "''", $sheetName);
        return "'{$escaped}'!{$a1}";
    }
}
