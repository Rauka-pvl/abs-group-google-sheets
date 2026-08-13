<?php

declare(strict_types=1);

namespace App;

final class Logger
{
    public function __construct(private readonly string $logFile)
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function request(string $rawBody, array $server, array $parsed): void
    {
        $this->write('REQUEST', 'Incoming webhook', [
            'method' => $server['REQUEST_METHOD'] ?? '',
            'uri' => $server['REQUEST_URI'] ?? '',
            'ip' => $server['REMOTE_ADDR'] ?? '',
            'content_type' => $server['CONTENT_TYPE'] ?? ($server['HTTP_CONTENT_TYPE'] ?? ''),
            'query' => $server['QUERY_STRING'] ?? '',
            'raw_body' => $rawBody,
            'parsed' => $parsed,
        ]);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
