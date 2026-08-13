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

    public const TECH_HEADERS = ['Bitrix ID', 'Воронка'];

    private ?Sheets $service = null;

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
        ];
    }

    /** @param list<string> $values */
    public function parseSheetValues(array $values): array
    {
        while (count($values) < 11) {
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
        ];
    }

    public function ensureHeader(): void
    {
        $headerRow = $this->config->headerRow;
        $response = $this->api()->spreadsheets_values->get(
            $this->config->spreadsheetId,
            $this->range("A{$headerRow}:K{$headerRow}")
        );
        $header = $response->getValues()[0] ?? [];
        while (count($header) < 11) {
            $header[] = '';
        }

        $checks = [
            1 => self::BUSINESS_HEADERS[0],
            3 => self::BUSINESS_HEADERS[1],
            4 => self::BUSINESS_HEADERS[2],
            5 => self::BUSINESS_HEADERS[3],
            6 => self::BUSINESS_HEADERS[4],
            7 => self::BUSINESS_HEADERS[5],
            8 => self::BUSINESS_HEADERS[6],
        ];
        foreach ($checks as $index => $expected) {
            if (trim((string) ($header[$index] ?? '')) !== $expected) {
                throw new \RuntimeException(
                    'Sheet "' . $this->config->sheetName . "\" row {$headerRow} headers do not match. "
                    . 'Expected B/D–I: ' . implode(' | ', self::BUSINESS_HEADERS)
                );
            }
        }

        $needTech = trim((string) ($header[9] ?? '')) !== self::TECH_HEADERS[0]
            || trim((string) ($header[10] ?? '')) !== self::TECH_HEADERS[1];
        if ($needTech) {
            $body = new ValueRange(['values' => [self::TECH_HEADERS]]);
            $this->api()->spreadsheets_values->update(
                $this->config->spreadsheetId,
                $this->range("J{$headerRow}:K{$headerRow}"),
                $body,
                ['valueInputOption' => 'RAW']
            );
        }
    }

    /** @return list<array{rowNumber:int, values:list<string>}> */
    public function readDataRows(): array
    {
        $start = $this->config->dataStartRow;
        $end = $this->config->dataEndRow;
        $response = $this->api()->spreadsheets_values->get(
            $this->config->spreadsheetId,
            $this->range("A{$start}:K{$end}")
        );
        $rows = [];
        foreach ($response->getValues() ?? [] as $index => $row) {
            $normalized = array_map(static fn ($c) => (string) ($c ?? ''), $row);
            while (count($normalized) < 11) {
                $normalized[] = '';
            }
            $rows[] = [
                'rowNumber' => $start + (int) $index,
                'values' => array_slice($normalized, 0, 11),
            ];
        }
        return $rows;
    }

    /** @param list<array{rowNumber:int, values:list<string>}> $updates */
    public function batchUpdateRows(array $updates): void
    {
        if ($updates === []) {
            return;
        }
        $data = [];
        foreach ($updates as $update) {
            $data[] = new ValueRange([
                'range' => $this->range('A' . $update['rowNumber'] . ':K' . $update['rowNumber']),
                'values' => [$update['values']],
            ]);
        }
        $body = new BatchUpdateValuesRequest([
            'valueInputOption' => 'RAW',
            'data' => $data,
        ]);
        $this->api()->spreadsheets_values->batchUpdate($this->config->spreadsheetId, $body);
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

    private function range(string $a1): string
    {
        $escaped = str_replace("'", "''", $this->config->sheetName);
        return "'{$escaped}'!{$a1}";
    }
}
