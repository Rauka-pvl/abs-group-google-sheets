<?php

declare(strict_types=1);

namespace App;

final class AccountingRow
{
    public function __construct(
        public readonly int $bitrixId,
        public readonly string $title,
        public readonly string $costArticle,
        public readonly string $supplier,
        public readonly string $amount,
        public readonly string $date,
        public readonly string $comments,
        public readonly string $status,
        public readonly string $categoryName,
        public readonly ?int $departmentId,
        public readonly string $departmentName,
        public readonly ?string $sheetName,
    ) {
    }
}

final class AccountingService
{
    /** @var array{stageMap: array<string,string>, costArticleMap: array<string,string>, departmentMap: array<string,string>, expiresAt: float}|null */
    private static ?array $mapsCache = null;

    public function __construct(
        private readonly BitrixClient $client,
        private readonly Config $config,
    ) {
    }

    public function fetchById(int $id): ?AccountingRow
    {
        $result = $this->client->call('crm.item.get', [
            'entityTypeId' => $this->config->entityTypeId,
            'id' => $id,
        ]);
        $item = is_array($result) ? ($result['item'] ?? null) : null;
        if (!is_array($item)) {
            return null;
        }
        $categoryId = (int) ($item['categoryId'] ?? 0);
        if (!in_array($categoryId, $this->config->categoryIds, true)) {
            return null;
        }
        [$stageMap, $costMap, $departmentMap] = $this->lookupMaps();
        return $this->mapItem($item, $stageMap, $costMap, $departmentMap);
    }

    /** @return array{0: array<string,string>, 1: array<string,string>, 2: array<string,string>} */
    private function lookupMaps(): array
    {
        $now = microtime(true);
        if (self::$mapsCache !== null && self::$mapsCache['expiresAt'] > $now) {
            return [
                self::$mapsCache['stageMap'],
                self::$mapsCache['costArticleMap'],
                self::$mapsCache['departmentMap'],
            ];
        }
        $stageMap = $this->loadStageMap();
        $costMap = $this->loadEnumMap($this->config->costArticleField);
        $departmentMap = $this->loadEnumMap($this->config->departmentField);
        self::$mapsCache = [
            'stageMap' => $stageMap,
            'costArticleMap' => $costMap,
            'departmentMap' => $departmentMap,
            'expiresAt' => $now + 300,
        ];
        return [$stageMap, $costMap, $departmentMap];
    }

    /** @return array<string,string> */
    private function loadStageMap(): array
    {
        $map = [];
        foreach ($this->config->categoryIds as $categoryId) {
            $entityId = 'DYNAMIC_' . $this->config->entityTypeId . '_STAGE_' . $categoryId;
            $statuses = $this->client->callList('crm.status.list', [
                'filter' => ['ENTITY_ID' => $entityId],
            ]);
            foreach ($statuses as $status) {
                $map[(string) ($status['STATUS_ID'] ?? '')] = (string) ($status['NAME'] ?? '');
            }
        }
        return $map;
    }

    /** @return array<string,string> */
    private function loadEnumMap(string $fieldName): array
    {
        $result = $this->client->call('crm.item.fields', [
            'entityTypeId' => $this->config->entityTypeId,
        ]);
        $field = is_array($result) ? ($result['fields'][$fieldName] ?? []) : [];
        $map = [];
        foreach (($field['items'] ?? []) as $item) {
            $id = (string) ($item['ID'] ?? $item['id'] ?? '');
            $value = (string) ($item['VALUE'] ?? $item['value'] ?? '');
            if ($id !== '') {
                $map[$id] = $value;
            }
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,string> $stageMap
     * @param array<string,string> $costMap
     * @param array<string,string> $departmentMap
     */
    private function mapItem(
        array $item,
        array $stageMap,
        array $costMap,
        array $departmentMap,
    ): AccountingRow {
        $categoryId = (int) ($item['categoryId'] ?? 0);
        $stageId = (string) ($item['stageId'] ?? '');
        $comment = $item[$this->config->commentField] ?? null;

        $departmentRaw = $item[$this->config->departmentField] ?? null;
        $departmentId = null;
        if (is_numeric($departmentRaw)) {
            $departmentId = (int) $departmentRaw;
        } elseif (is_array($departmentRaw) && isset($departmentRaw[0]) && is_numeric($departmentRaw[0])) {
            $departmentId = (int) $departmentRaw[0];
        }

        $departmentName = $departmentId !== null
            ? ($departmentMap[(string) $departmentId] ?? '')
            : '';

        return new AccountingRow(
            bitrixId: (int) $item['id'],
            title: (string) ($item['title'] ?? ''),
            costArticle: $this->resolveEnum($item[$this->config->costArticleField] ?? null, $costMap),
            supplier: '',
            amount: $this->formatAmount($item['opportunity'] ?? null),
            date: $this->formatDate($item['createdTime'] ?? null),
            comments: $comment === null ? '' : (string) $comment,
            status: $stageMap[$stageId] ?? $stageId,
            categoryName: $this->config->categoryNames[$categoryId] ?? ('category ' . $categoryId),
            departmentId: $departmentId,
            departmentName: $departmentName,
            sheetName: $this->config->sheetForDepartmentId($departmentId),
        );
    }

    /** @param array<string,string> $map */
    private function resolveEnum(mixed $raw, array $map): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $v) {
                $parts[] = $map[(string) $v] ?? (string) $v;
            }
            return implode(', ', $parts);
        }
        return $map[(string) $raw] ?? (string) $raw;
    }

    private function formatAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_numeric($value)) {
            return (string) $value;
        }
        return number_format((float) $value, 2, ',', ' ');
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable((string) $value);
            return $dt->format('d.m.Y');
        } catch (\Exception) {
            return (string) $value;
        }
    }
}
