<?php

declare(strict_types=1);

/**
 * Роутер встроенного PHP-сервера (`php -S ... router.php`).
 *
 * Документ-рут приложения — корень проекта (фронт ходит в API по относительному пути
 * `../api/`), поэтому без фильтра по HTTP наружу выставлены и файлы, которых там быть не должно:
 * `config/params.ini` с токенами Jira/нейронки, `storage/app.sqlite`, `.git`, `.idea`.
 * Отдаём только то, что действительно является приложением: `public/` и `api/`.
 *
 * Роутер обязателен при любом способе запуска — и в Dockerfile, и в `php -S` вручную
 * (см. README). Появится новый публичный каталог — добавляй его в ALLOWED_PREFIXES, а не
 * убирай проверку.
 */

const ALLOWED_PREFIXES = ['/public/', '/api/'];

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$path = '/' . ltrim(rawurldecode($path), '/');

if ($path === '/' || $path === '/index.php') {
    header('Location: /public/index.php', true, 302);

    return true;
}

// Выход из разрешённого каталога через ../ — сразу отказ, до любой работы с файлом
$isAllowed = !str_contains($path, '..');
if ($isAllowed) {
    $isAllowed = false;
    foreach (ALLOWED_PREFIXES as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $isAllowed = true;
            break;
        }
    }
}

if (!$isAllowed) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";

    return true;
}

// false — файл отдаёт (или исполняет) сам встроенный сервер
return false;
