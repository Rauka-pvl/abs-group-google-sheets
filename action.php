<?php

declare(strict_types=1);

use App\AccountingService;
use App\BitrixClient;
use App\Config;
use App\Logger;
use App\SheetsClient;
use App\SyncService;
use App\WebhookHandler;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

$rootDir = __DIR__;
$logger = null;

try {
    if (is_file($rootDir . '/.env')) {
        Dotenv::createImmutable($rootDir)->safeLoad();
    }

    $config = new Config($rootDir);
    $logger = new Logger($config->logFile);

    $rawBody = file_get_contents('php://input') ?: '';
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

    $payload = [];
    if (str_contains(strtolower($contentType), 'application/json') && $rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        $payload = is_array($decoded) ? $decoded : [];
    } elseif ($rawBody !== '') {
        parse_str($rawBody, $parsed);
        $payload = is_array($parsed) ? $parsed : [];
    } elseif ($_POST !== []) {
        $payload = $_POST;
    }

    $logger->request($rawBody !== '' ? $rawBody : http_build_query($_POST), $_SERVER, $payload);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        echo json_encode([
            'ok' => true,
            'service' => 'bitrix-google-sync',
            'endpoint' => 'action.php',
            'log' => $config->logFile,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method not allowed']);
        exit;
    }

    $token = '';
    if (isset($payload['auth']) && is_array($payload['auth'])) {
        $token = (string) ($payload['auth']['application_token'] ?? '');
    }
    if ($token === '' && isset($_POST['auth']) && is_array($_POST['auth'])) {
        $token = (string) ($_POST['auth']['application_token'] ?? '');
    }

    if ($config->outgoingWebhookToken === '' || $token === '' || !hash_equals($config->outgoingWebhookToken, $token)) {
        $logger->error('Invalid application_token', ['has_token' => $token !== '']);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid token']);
        exit;
    }

    $bitrix = new BitrixClient($config->bitrixWebhookUrl);
    $accounting = new AccountingService($bitrix, $config);
    $sheets = new SheetsClient($config);
    $sync = new SyncService($sheets, $config);
    $handler = new WebhookHandler($config, $accounting, $sync, $logger);

    $result = $handler->handle($payload);

    if (($result['status'] ?? '') === 'ignored') {
        $logger->info('Ignored event', $result);
        echo json_encode([
            'ok' => true,
            'ignored' => true,
            'reason' => $result['reason'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'bitrixId' => $result['bitrixId'] ?? null,
        'action' => $result['action'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    if ($logger instanceof Logger) {
        $logger->error('Unhandled exception', ['message' => $message]);
    } else {
        $fallback = $rootDir . '/logs/requests.log';
        if (!is_dir(dirname($fallback))) {
            @mkdir(dirname($fallback), 0755, true);
        }
        @file_put_contents(
            $fallback,
            '[' . date('Y-m-d H:i:s') . '] ERROR ' . $message . "\n",
            FILE_APPEND
        );
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
}
