<?php

declare(strict_types=1);

namespace App;

final class BitrixClient
{
    public function __construct(private readonly string $webhookUrl)
    {
    }

    public function call(string $method, array $params = []): mixed
    {
        $payload = [];
        $this->appendParams($payload, $params);
        $data = $this->post($method, $payload);
        if (isset($data['error'])) {
            throw new \RuntimeException(
                'Bitrix error ' . $data['error'] . ': ' . ($data['error_description'] ?? 'unknown')
            );
        }
        return $data['result'] ?? null;
    }

    public function callList(string $method, array $params = []): array
    {
        $items = [];
        $start = 0;
        while ($start !== null) {
            $payload = [];
            $this->appendParams($payload, $params + ['start' => $start]);
            $data = $this->post($method, $payload);
            if (isset($data['error'])) {
                throw new \RuntimeException(
                    'Bitrix error ' . $data['error'] . ': ' . ($data['error_description'] ?? 'unknown')
                );
            }
            $result = $data['result'] ?? [];
            if (is_array($result) && array_is_list($result)) {
                $chunk = $result;
            } elseif (is_array($result)) {
                $chunk = $result['items'] ?? [];
            } else {
                $chunk = [];
            }
            foreach ($chunk as $item) {
                $items[] = $item;
            }
            $start = $data['next'] ?? null;
        }
        return $items;
    }

    private function post(string $method, array $payload): array
    {
        $ch = curl_init($this->webhookUrl . $method);
        if ($ch === false) {
            throw new \RuntimeException('Failed to init curl');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Bitrix HTTP error: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException("Bitrix HTTP {$code} for {$method}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid Bitrix JSON response');
        }
        return $data;
    }

    private function appendParams(array &$data, mixed $value, string $prefix = ''): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $nextPrefix = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
                $this->appendParams($data, $nested, $nextPrefix);
            }
            return;
        }

        if ($prefix === '') {
            throw new \InvalidArgumentException('Cannot append scalar without a key');
        }
        $data[$prefix] = (string) $value;
    }
}
