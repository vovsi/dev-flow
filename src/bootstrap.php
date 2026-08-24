<?php

declare(strict_types=1);

// Тексты ошибок PHP не уходят в браузер: в стектрейсе видны пути на диске и аргументы вызовов —
// в том числе токены, прочитанные из config/params.ini. Смотреть их нужно в логе сервера
// (`docker compose logs -f app`), а не в ответе API.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Простой автозагрузчик классов пространства имён App (без composer — не требуется по масштабу проекта)
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
