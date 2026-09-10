<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ChecklistRepository;
use App\JiraSyncService;
use App\TaskRepository;
use App\TaskService;

// Read-only чтение блока стандартных секций (Results/Testing/…) из описания задачи в Jira:
// модалка пункта «Оставить описание в Jira» показывает draft в редактируемом поле, а по
// has_sections понимает, придётся его в описании заменять или дописывать. Ничего не меняет —
// в том числе переданные пункты (ссылка на PR, заметки о базе, конфиге и прочем): они попадают
// только в draft (см. TaskService).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$input = readJsonInput();
$taskId = (int) ($input['task_id'] ?? 0);
// Ссылка на PR необязательна — её сохраняют пункты «Создать PR» и «Закоммитить изменения»,
// и до них секция Pull Requests остаётся пустой. Так же необязательны заметки о базе,
// конфиге и прочем: их вводят в шаге «Закоммитить изменения», который в ручном режиме скрыт
$notes = [
    'pr_link' => trim((string) ($input['pr_link'] ?? '')),
    'database' => trim((string) ($input['database'] ?? '')),
    'config' => trim((string) ($input['config'] ?? '')),
    'other' => trim((string) ($input['other'] ?? '')),
];

if ($taskId <= 0) {
    respond(['error' => 'Не указана задача'], 422);
}

$taskRepository = new TaskRepository($pdo);
$service = new TaskService($taskRepository, new ChecklistRepository($pdo), JiraSyncService::createFromConfig($taskRepository));

try {
    $sections = $service->getJiraDescriptionSections($taskId, $notes);
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond([
    'has_sections' => $sections['sections'] !== null,
    'sections' => $sections['sections'],
    'draft' => $sections['draft'],
]);
