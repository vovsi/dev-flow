<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ChecklistRepository;
use App\TaskRepository;

/**
 * @OA\Post(
 *     path="/toggle_waiting_for_deploy",
 *     summary="Toggle the per-task \"waiting for another task deploy\" flag.",
 *     description="This method marks one specific task as waiting until another task is deployed — while it is on, the checklist hides the Ready for review and send PR to reviewer steps.",
 *     @OA\RequestBody(
 *         required=true,
 *         description="Request data",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 example={
 *                     "task_id": 1,
 *                     "enabled": true
 *                 }
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *          response=200,
 *          description="Successfully toggled",
 *          @OA\JsonContent(
 *              @OA\Property(property="task", type="object"),
 *              @OA\Property(property="checklist", type="array", @OA\Items(type="object"))
 *          )
 *      ),
 *      @OA\Response(
 *          response=422,
 *          description="Task is not specified"
 *      )
 * )
 */

// Флаг живёт в самой задаче (tasks.waiting_for_deploy), а не в config/params.ini — раздел
// «Эта задача» в настройках виден только пока задача открыта. Как и
// toggle_claude_code_skill_mode.php, никакой бизнес-логики тут нет (только запись в БД +
// перечитывание чек-листа), поэтому TaskService не нужен — репозитории напрямую.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$input = readJsonInput();
$taskId = (int) ($input['task_id'] ?? 0);
$enabled = (bool) ($input['enabled'] ?? false);

if ($taskId <= 0) {
    respond(['error' => 'Не указана задача'], 422);
}

$taskRepository = new TaskRepository($pdo);
$checklistRepository = new ChecklistRepository($pdo);

$taskRepository->updateWaitingForDeploy($taskId, $enabled);

respond([
    'task' => $taskRepository->findById($taskId),
    'checklist' => $checklistRepository->getStatusesForTask($taskId),
]);
