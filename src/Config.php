<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public readonly string $bitrixWebhookUrl;
    public readonly string $spreadsheetId;
    public readonly string $sheetName;
    public readonly string $googleServiceAccountPath;
    public readonly string $outgoingWebhookToken;
    public readonly string $logFile;
    public readonly int $entityTypeId;
    /** @var list<int> */
    public readonly array $categoryIds;
    /** @var array<int, string> */
    public readonly array $categoryNames;
    public readonly string $costArticleField;
    public readonly string $commentField;
    public readonly string $deletedStatus;
    public readonly int $headerRow;
    public readonly int $dataStartRow;
    public readonly int $dataEndRow;

    public function __construct(string $rootDir)
    {
        $this->bitrixWebhookUrl = self::normalizeWebhookUrl(self::required('BITRIX_WEBHOOK_URL'));
        $this->spreadsheetId = self::required('SPREADSHEET_ID');
        $this->sheetName = trim((string) ($_ENV['SHEET_NAME'] ?? 'Снабжения')) ?: 'Снабжения';
        $sa = trim((string) ($_ENV['GOOGLE_SERVICE_ACCOUNT_PATH'] ?? './credentials/service-account.json'));
        $this->googleServiceAccountPath = self::absolutePath($rootDir, $sa);
        $this->outgoingWebhookToken = trim((string) ($_ENV['OUTGOING_WEBHOOK_TOKEN'] ?? ''));
        $logPath = trim((string) ($_ENV['LOG_FILE'] ?? './logs/requests.log'));
        $this->logFile = self::absolutePath($rootDir, $logPath);

        $this->entityTypeId = 1106;
        $this->categoryIds = [62, 66, 68];
        $this->categoryNames = [
            62 => 'Служебные записки',
            66 => 'Счета на оплату',
            68 => 'Командировочные',
        ];
        $this->costArticleField = 'ufCrm38_1786016637295';
        $this->commentField = 'ufCrm38_1786016654344';
        $this->deletedStatus = 'Удалено';
        $this->headerRow = 18;
        $this->dataStartRow = 19;
        $this->dataEndRow = 98;
    }

    private static function required(string $name): string
    {
        $value = trim((string) ($_ENV[$name] ?? getenv($name) ?: ''));
        if ($value === '') {
            throw new \RuntimeException("Missing required env variable: {$name}");
        }
        return $value;
    }

    private static function normalizeWebhookUrl(string $url): string
    {
        return str_ends_with($url, '/') ? $url : $url . '/';
    }

    private static function absolutePath(string $rootDir, string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1)) {
            return $path;
        }
        return rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
