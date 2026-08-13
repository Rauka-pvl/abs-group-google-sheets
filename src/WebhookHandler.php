<?php

declare(strict_types=1);

namespace App;

final class WebhookHandler
{
    private const SPA_EVENTS = [
        'ONCRMDYNAMICITEMADD',
        'ONCRMDYNAMICITEMUPDATE',
        'ONCRMDYNAMICITEMDELETE',
    ];

    private const DEAL_EVENTS = [
        'ONCRMDEALADD',
        'ONCRMDEALUPDATE',
        'ONCRMDEALDELETE',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly AccountingService $accounting,
        private readonly SyncService $sync,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function handle(array $payload): array
    {
        $event = strtoupper((string) ($payload['event'] ?? ''));
        $fields = $payload['data']['FIELDS'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $idRaw = $fields['ID'] ?? null;
        $entityRaw = $fields['ENTITY_TYPE_ID'] ?? null;
        $id = is_numeric($idRaw) ? (int) $idRaw : null;
        $entityTypeId = is_numeric($entityRaw) ? (int) $entityRaw : null;

        if ($event === '' || $id === null) {
            return ['status' => 'ignored', 'reason' => 'missing event or id'];
        }

        if (in_array($event, self::DEAL_EVENTS, true)) {
            return [
                'status' => 'ignored',
                'reason' => 'ONCRMDEAL* is for classic deals. Use ONCRMDYNAMICITEMADD/UPDATE/DELETE',
            ];
        }

        if (!in_array($event, self::SPA_EVENTS, true)) {
            return ['status' => 'ignored', 'reason' => 'unsupported event ' . $event];
        }

        if ($entityTypeId !== null && $entityTypeId !== $this->config->entityTypeId) {
            return [
                'status' => 'ignored',
                'reason' => "entityTypeId {$entityTypeId} is not accounting SPA {$this->config->entityTypeId}",
            ];
        }

        if ($event === 'ONCRMDYNAMICITEMDELETE') {
            $action = $this->sync->markDeleted($id);
            $this->logger->info('Delete processed', ['bitrixId' => $id, 'action' => $action]);
            return ['status' => 'ok', 'action' => $action, 'bitrixId' => $id];
        }

        $row = $this->accounting->fetchById($id);
        if ($row === null) {
            $action = $this->sync->markDeleted($id);
            $final = $action === 'missing' ? 'skipped_out_of_scope' : $action;
            $this->logger->info('Out of scope / missing', ['bitrixId' => $id, 'action' => $final]);
            return ['status' => 'ok', 'action' => $final, 'bitrixId' => $id];
        }

        $action = $this->sync->upsert($row);
        $this->logger->info('Upsert processed', ['bitrixId' => $id, 'action' => $action]);
        return ['status' => 'ok', 'action' => $action, 'bitrixId' => $id];
    }
}
