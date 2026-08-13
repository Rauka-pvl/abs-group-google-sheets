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
    ) {
    }
}

final class AccountingService
{
    /** @var array{stageMap: array<string,string>, costArticleMap: array<string,string>, expiresAt: float}|null */
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
        [$stageMap, $costMap] = $this->lookupMaps();
        return $this->mapItem($item, $stageMap, $costMap);
    }

    /** @return array{0: array<string,string>, 1: array<string,string>} */
    private function lookupMaps(): array
    {
        $now = microtime(true);
        if (self::$mapsCache !== null && self::$mapsCache['expiresAt'] > $now) {
            return [self::$mapsCache['stageMap'], self::$mapsCache['costArticleMap']];
        }
        $stageMap = $this->loadStageMap();
        $costMap = $this->loadCostArticleMap();
        self::$mapsCache = [
            'stageMap' => $stageMap,
            'costArticleMap' => $costMap,
            'expiresAt' => $now + 300,
        ];
        return [$stageMap, $costMap];
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
    private function loadCostArticleMap(): array
    {
        $result = $this->client->call('crm.item.fields', [
            'entityTypeId' => $this->config->entityTypeId,
        ]);
        $field = is_array($result) ? ($result['fields'][$this->config->costArticleField] ?? []) : [];
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
     */
    private function mapItem(array $item, array $stageMap, array $costMap): AccountingRow
    {
        $categoryId = (int) ($item['categoryId'] ?? 0);
        $stageId = (string) ($item['stageId'] ?? '');
        $comment = $item[$this->config->commentField] ?? null;
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
        $formatted = number_format((float) $value, 2, ',', ' ');
        return $formatted;
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
