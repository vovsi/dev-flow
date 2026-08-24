<?php

declare(strict_types=1);

// Общий бутстрап для всех API-эндпоинтов: автозагрузка, JSON-заголовки, разбор входных данных

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/**
 * Отсекает запросы, пришедшие не из самого приложения (CSRF).
 *
 * Авторизации и сессий здесь нет намеренно (см. CLAUDE.md, YAGNI), поэтому любая открытая в
 * браузере страница могла бы вслепую отправить POST на http://localhost:8000/api/* и удалить
 * задачу, затрекать время в Jira, перевести статус или сжечь токены нейронки: Content-Type
 * сервер не проверяет, а `text/plain` не требует preflight, то есть CORS такой запрос не
 * останавливает. Токен в куке/сессии для одной локальной страницы был бы избыточен —
 * достаточно проверить источник запроса.
 */
function assertSameOrigin(): void
{
    // Sec-Fetch-Site шлют все актуальные браузеры и его нельзя подделать из JS
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
        respond(['error' => 'Запрос отклонён: недопустимый источник'], 403);
    }

    // Фолбэк для браузеров без Sec-Fetch-Site: Origin у cross-site запроса не совпадёт с Host
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $originHost = (string) (parse_url($origin, PHP_URL_HOST) ?? '');
    $originPort = parse_url($origin, PHP_URL_PORT);
    if ($originPort !== null) {
        $originHost .= ':' . $originPort;
    }

    if ($originHost === '' || strcasecmp($originHost, $host) !== 0) {
        respond(['error' => 'Запрос отклонён: недопустимый источник'], 403);
    }
}

/** Читает и декодирует JSON-тело запроса */
function readJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/** Отправляет JSON-ответ и завершает скрипт */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

assertSameOrigin();

$pdo = Database::connection();
