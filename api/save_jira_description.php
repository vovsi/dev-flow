<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ChecklistRepository;
use App\JiraSyncService;
use App\TaskRepository;
use App\TaskService;

// Пишет отредактированный в модалке блок стандартных секций (Results/Testing/…) в описание
// задачи в Jira: блок там уже есть — заменяется, ещё нет — дописывается в конец.
// Чек-лист не трогает — это отдельное действие внутри пункта «Оставить описание в Jira»,
// сам пункт отмечает кнопка «Готово» через toggle.php.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$input = readJsonInput();
$taskId = (int) ($input['task_id'] ?? 0);
$sections = trim((string) ($input['sections'] ?? ''));

if ($taskId <= 0) {
    respond(['error' => 'Не указана задача'], 422);
}

// Пустой текст затёр бы блок в описании молча — это не сохранение, а потеря данных
if ($sections === '') {
    respond(['error' => 'Пункты не заполнены'], 422);
}

$taskRepository = new TaskRepository($pdo);
$service = new TaskService($taskRepository, new ChecklistRepository($pdo), JiraSyncService::createFromConfig($taskRepository));

try {
    $service->saveJiraDescriptionSections($taskId, $sections);
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond(['success' => true]);
