<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Config;
use App\EarningsService;
use App\ExchangeRateClient;

// Считает заработок за отработанные сегодня секунды в валюте из [currency] — для модалки поздравления,
// показывается при достижении [worktime].daily_hours (см. openQuickTrackModal в app.js).
// К конкретной задаче не привязано, поэтому идёт мимо TaskService.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$input = readJsonInput();
$seconds = max(0, (int) ($input['seconds'] ?? 0));

if ($seconds <= 0) {
    respond(['error' => 'Не указано отработанное время'], 422);
}

$hourlyRateUsd = Config::salaryHourlyRateUsd();
if ($hourlyRateUsd === null) {
    respond(['error' => 'Оклад не задан в config/params.ini ([salary].monthly_usd)'], 422);
}

$currency = Config::currency();
$exchangeRateClient = new ExchangeRateClient(
    Config::exchangeRateUrl(),
    dirname(__DIR__) . '/storage/exchange_rate_cache.json',
    $currency['code']
);
$service = new EarningsService($exchangeRateClient, $hourlyRateUsd);

try {
    $earnings = $service->earningsForSeconds($seconds);
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

// Подпись валюты отдаём вместе с суммой — фронт не должен знать про [currency] в конфиге
respond(['earnings' => $earnings, 'currency_label' => $currency['label']]);
