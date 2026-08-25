<?php

use App\Config;

require_once __DIR__ . '/../src/bootstrap.php';

// Страница активно меняется в разработке — запрещаем браузеру кешировать сам HTML,
// чтобы правки разметки не терялись за старой закешированной версией страницы.
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Базовые защитные заголовки. Приложение не грузит ни одного внешнего ресурса и общается
// только со своим же api/, поэтому CSP можно держать жёсткой: 'unsafe-inline' нужен лишь
// из-за inline-скрипта с DEVFLOW_CONFIG и inline-стилей в разметке модалок.
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; "
    . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'"
);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// Конфиг не обязателен для запуска приложения (см. README) — при отсутствии config/params.ini
// страница всё равно должна открыться: просто без --reviewer в команде gh и с рабочим днём
// по умолчанию у ползунка быстрого трека времени.
try {
    $githubReviewers = Config::githubReviewers();
    $workTime = Config::workTime();
    $gitRebaseTargets = Config::gitRebaseTargets();
    $reviewSkipMigrationRepos = Config::reviewSkipMigrationRepos();
    $reviewDocLinks = Config::reviewDocLinks();
} catch (\Throwable $e) {
    $githubReviewers = [];
    $workTime = Config::WORK_TIME_DEFAULTS;
    $gitRebaseTargets = [];
    $reviewSkipMigrationRepos = [];
    $reviewDocLinks = [];
}

// Флаги кодирования конфига для inline-скрипта (см. window.DEVFLOW_CONFIG в конце страницы)
const JSON_ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Версия статики = время последнего изменения файла: при каждой правке CSS/JS
// у ссылки меняется ?v=..., и браузер больше не подставляет закешированную версию.
$assetVersion = static function (string $relativePath): string {
    $path = __DIR__ . '/' . $relativePath;
    // Встроенный сервер PHP держит один процесс на все запросы, поэтому без сброса
    // stat-кеша filemtime() продолжал бы отдавать время первого запроса.
    clearstatcache(true, $path);

    return (string) filemtime($path);
};
?>
<!doctype html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>DevFlow</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=<?= $assetVersion('assets/favicon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png?v=<?= $assetVersion('assets/favicon-32.png') ?>">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png?v=<?= $assetVersion('assets/icons/icon-192.png') ?>">
    <!-- Манифест с PNG-иконками — не для офлайн-режима (его тут нет и не нужно), а чтобы
         Chrome при установке приложения на Mac (chrome://apps → Install) брал растровую иконку
         из manifest.icons, а не генерировал собственную заглушку из первой буквы названия
         (SVG-фавикон для этого шага Chrome не рассматривает) -->
    <link rel="manifest" href="manifest.json?v=<?= $assetVersion('manifest.json') ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $assetVersion('assets/css/style.css') ?>">
</head>
<body>

<!-- Кнопка возврата к экрану ввода ссылки (видна только внутри задачи), затреканное сегодня
     время во всех задачах Jira (подгружается отдельно от страницы) и кружок быстрого трека
     времени, всплывающий правее по наведению (только когда открыта задача) -->
<div class="today-time-group">
    <button type="button" id="change-task-btn" class="back-btn hidden" data-tooltip="Другая задача" aria-label="Другая задача">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5"/>
            <path d="M11 6l-6 6 6 6"/>
        </svg>
    </button>
    <button type="button" id="today-time" class="today-time hidden" data-tooltip="Затрекано времени" aria-label="Затреканное сегодня время">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 7v5l3.2 2"/>
        </svg>
        <span id="today-time-value"></span>
        <svg class="today-time-check" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12.5l4.5 4.5L19 7"/>
        </svg>
    </button>
    <button type="button" id="track-time-btn" class="track-time-btn" data-tooltip="Затрекать время" aria-label="Затрекать время">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="10.5" cy="13.5" r="7.5"/>
            <path d="M10.5 9.6v3.9l2.8 1.7"/>
            <path d="M19 2.5v6M16 5.5h6"/>
        </svg>
    </button>
    <button type="button" id="today-tasks-btn" class="track-time-btn today-tasks-btn" data-tooltip="Задачи за сегодня" aria-label="Задачи, на которые затрекано время сегодня">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 4h11a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H8"/>
            <path d="M8 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h3"/>
            <path d="M12 9h5M12 13h5M12 17h3"/>
        </svg>
    </button>
</div>

<!-- Иконка настроек -->
<button id="settings-btn" class="icon-btn settings-btn" data-tooltip="Настройки" aria-label="Настройки">
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7">
        <circle cx="12" cy="12" r="3.2"/>
        <path d="M19.4 13a7.6 7.6 0 0 0 .1-1 7.6 7.6 0 0 0-.1-1l2-1.6-2-3.4-2.4 1a7.4 7.4 0 0 0-1.7-1L15 3h-4l-.3 2.6a7.4 7.4 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.6a7.6 7.6 0 0 0 0 2l-2 1.6 2 3.4 2.4-1a7.4 7.4 0 0 0 1.7 1L11 21h4l.3-2.6a7.4 7.4 0 0 0 1.7-1l2.4 1 2-3.4-2-1.6Z"/>
    </svg>
</button>

<!-- Попап настроек: тема + (опционально) раздел «Claude» -->
<div id="theme-popover" class="popover popover--theme hidden">
    <button class="theme-option" data-theme="light">Светлая</button>
    <button class="theme-option" data-theme="dark">Тёмная</button>
    <!-- Показывается только если в config/params.ini заполнена секция [claude]
         (см. api/get_claude_settings.php) — без неё слать уведомления некуда -->
    <div id="claude-settings-section" class="popover-section hidden">
        <div class="popover-divider"></div>
        <div class="popover-label">Claude</div>
        <label class="popover-toggle-row" data-tooltip="Хуки Notification/Stop в Telegram у Claude Code (десктопное приложение)">
            <span>Уведомления</span>
            <span class="toggle-switch">
                <input type="checkbox" id="claude-notifications-toggle">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </span>
        </label>
    </div>
</div>

<main class="app">

    <!-- Экран ввода ссылки на задачу -->
    <section id="link-screen" class="screen link-screen">
        <div class="card link-card">
            <h1 class="app-title">DevFlow 🐝</h1>

            <!-- Дашборд показателей по Jira (api/dashboard.php). Клик по показателю открывает
                 список задач за числом, кнопка справа — актуализация данных -->
            <div id="dashboard" class="dashboard hidden loading">
                <div class="dashboard-metrics">
                    <button type="button" id="metric-stale-pr" class="metric metric--blue">
                        <span class="metric-value" id="metric-stale-pr-value"><span class="skeleton" aria-hidden="true"></span></span>
                        <span class="metric-label" id="metric-stale-pr-label"><span class="skeleton" aria-hidden="true"></span></span>
                    </button>
                </div>
                <button type="button" id="dashboard-refresh" class="dashboard-refresh" aria-label="Обновить показатели">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 11a8 8 0 1 0-2.3 5.7"/>
                        <path d="M20 5v6h-6"/>
                    </svg>
                </button>
            </div>

            <div class="link-input-row">
                <input
                    type="text"
                    id="task-link-input"
                    class="input link-input"
                    placeholder="Вставьте ссылку на задачу Jira"
                    autofocus
                >
                <button id="open-task-btn" class="btn btn-primary open-task-btn">Открыть</button>
            </div>
            <div id="link-error" class="error-text hidden"></div>

            <!-- Последние открытые задачи (localStorage) — клик открывает задачу без ввода ссылки -->
            <div id="recent-tasks" class="recent-tasks hidden">
                <div class="recent-tasks-title">Последние задачи</div>
                <div id="recent-tasks-list" class="recent-tasks-list"></div>
            </div>
        </div>
    </section>

    <!-- Экран задачи с чек-листом -->
    <section id="task-screen" class="screen task-screen hidden">
        <div class="task-header">
            <a class="task-id" id="task-id-label" target="_blank" rel="noopener"></a>
        </div>

        <!-- Прогресс выполнения чек-листа -->
        <div class="progress" id="progress">
            <div class="progress-track">
                <div class="progress-fill" id="progress-fill"></div>
            </div>
            <div class="progress-label" id="progress-label">0%</div>
        </div>

        <ul id="checklist" class="checklist"></ul>

        <button id="finish-task-btn" class="btn finish-btn">Начать заново</button>

        <div class="git-branch-footer" id="git-branch-footer">
            <button id="git-branch-value" class="branch-pill hidden" data-tooltip="Скопировать ветку" aria-label="Скопировать ветку"></button>
            <div class="git-icon-wrap">
                <button id="git-actions-btn" class="git-icon-btn hidden" data-tooltip="Git команды" aria-label="Git команды">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="6" cy="6" r="2.2"/>
                        <circle cx="6" cy="18" r="2.2"/>
                        <circle cx="18" cy="6" r="2.2"/>
                        <path d="M6 8.2V15.8"/>
                        <path d="M8.2 6H14a4 4 0 0 1 4 4v0"/>
                    </svg>
                </button>
                <div id="git-actions-popover" class="popover popover--git hidden">
                    <button class="git-action-option" data-action="create-branch">Create Branch</button>
                    <button class="git-action-option" data-action="push">Push</button>
                    <?php foreach ($gitRebaseTargets as $target): ?>
                        <button class="git-action-option" data-action="rebase" data-base="<?= htmlspecialchars($target['base'], ENT_QUOTES) ?>">Rebase <?= htmlspecialchars($target['label'], ENT_QUOTES) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- Универсальное модальное окно -->
<div id="modal-overlay" class="modal-overlay hidden">
    <div class="modal card">
        <h2 class="modal-title" id="modal-title"></h2>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-actions" id="modal-actions"></div>
    </div>
</div>

<!-- Всплывающее уведомление (например «Скопировано») -->
<div id="toast" class="toast"></div>

<!-- Единственная подсказка приложения — показывается у любого элемента с data-tooltip (см. app.js) -->
<div id="tooltip" class="tooltip" role="tooltip"></div>

<!-- Глобальный прелоадер по центру экрана — для запросов, которые начинаются уже после
     закрытия модалки (см. CLAUDE.md, раздел «Индикация загрузки») -->
<div id="global-loader" class="global-loader hidden">
    <span class="ios-spinner ios-spinner--lg" aria-hidden="true">
        <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
    </span>
</div>

<script>
    // Конфиг уходит в inline-скрипт, поэтому кодируется с JSON_HEX_* : без них закрывающий тег
    // script внутри params.ini разорвал бы тег и превратил значение конфига в исполняемый код.
    // По той же причине сам этот тег нельзя писать здесь буквально даже в комментарии —
    // HTML-парсер закрывает <script> на первой же такой последовательности, и весь конфиг
    // ниже перестаёт исполняться (window.DEVFLOW_CONFIG становится undefined).
    window.DEVFLOW_CONFIG = {
        githubReviewers: <?= json_encode($githubReviewers, JSON_ENCODE_FLAGS) ?>,
        workTime: <?= json_encode($workTime, JSON_ENCODE_FLAGS) ?>,
        reviewSkipMigrationRepos: <?= json_encode($reviewSkipMigrationRepos, JSON_ENCODE_FLAGS) ?>,
        reviewDocLinks: <?= json_encode($reviewDocLinks, JSON_ENCODE_FLAGS | JSON_FORCE_OBJECT) ?>
    };
</script>
<script src="assets/js/app.js?v=<?= $assetVersion('assets/js/app.js') ?>"></script>
</body>
</html>
