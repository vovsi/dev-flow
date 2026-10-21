<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ChecklistRepository;
use App\TaskRepository;
use App\TaskService;

/**
 * @OA\Post(
 *     path="/clear_git_branch",
 *     summary="Unlink the git branch from the task.",
 *     description="This method clears the saved git branch name of the task and unchecks the branch creation steps, so the step shows up in the checklist again. The branch itself in git is not touched.",
 *     @OA\RequestBody(
 *         required=true,
 *         description="Request data",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 example={
 *                     "task_id": 1
 *                 }
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *          response=200,
 *          description="Successfully cleared",
 *          @OA\JsonContent(
 *              @OA\Property(property="task", type="object"),
 *              @OA\Property(property="checklist", type="array", @OA\Items(type="object"))
 *          )
 *      ),
 *      @OA\Response(
 *          response=404,
 *          description="Task not found"
 *      ),
 *      @OA\Response(
 *          response=422,
 *          description="Task is not specified"
 *      )
 * )
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$input = readJsonInput();
$taskId = (int) ($input['task_id'] ?? 0);

if ($taskId <= 0) {
    respond(['error' => 'Не указана задача'], 422);
}

$taskService = new TaskService(new TaskRepository($pdo), new ChecklistRepository($pdo));

$result = $taskService->clearGitBranch($taskId);
if ($result === null) {
    respond(['error' => 'Задача не найдена'], 404);
}

respond($result);
