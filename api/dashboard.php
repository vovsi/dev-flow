<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\DashboardService;
use App\TaskRepository;

/**
 * @OA\Post(
 *     path="/dashboard",
 *     summary="Get dashboard metrics.",
 *     description="Read-only endpoint that returns numeric dashboard metrics: issues assigned to the current user stuck in the Pull request status and in the Blocked status for longer than their thresholds in working hours — days off from [worktime].non_working_days are skipped (with the task list behind every number).",
 *     @OA\RequestBody(
 *         required=false,
 *         description="No parameters",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(type="object", example={})
 *         )
 *     ),
 *     @OA\Response(
 *          response=200,
 *          description="Successful operation",
 *          @OA\JsonContent(
 *              @OA\Property(property="stale_pull_requests", type="object",
 *                  @OA\Property(property="count", type="integer", example=3),
 *                  @OA\Property(property="hours", type="integer", example=24),
 *                  @OA\Property(property="status", type="string", example="Pull request"),
 *                  @OA\Property(property="non_working_weekdays", type="array", @OA\Items(type="integer"), example={6, 7}),
 *                  @OA\Property(property="tasks", type="array", @OA\Items(
 *                      @OA\Property(property="task_id", type="string", example="PROJ-123"),
 *                      @OA\Property(property="title", type="string", example="Fix login bug"),
 *                      @OA\Property(property="status", type="string", example="Pull request"),
 *                      @OA\Property(property="link", type="string", example="https://example.atlassian.net/browse/PROJ-123")
 *                  ))
 *              ),
 *              @OA\Property(property="stale_blocked", type="object",
 *                  @OA\Property(property="count", type="integer", example=1),
 *                  @OA\Property(property="hours", type="integer", example=24),
 *                  @OA\Property(property="status", type="string", example="Blocked"),
 *                  @OA\Property(property="non_working_weekdays", type="array", @OA\Items(type="integer"), example={6, 7}),
 *                  @OA\Property(property="tasks", type="array", @OA\Items(
 *                      @OA\Property(property="task_id", type="string", example="PROJ-124"),
 *                      @OA\Property(property="title", type="string", example="Waiting for the payment provider"),
 *                      @OA\Property(property="status", type="string", example="Blocked"),
 *                      @OA\Property(property="link", type="string", example="https://example.atlassian.net/browse/PROJ-124")
 *                  ))
 *              )
 *          )
 *      ),
 *      @OA\Response(
 *          response=422,
 *          description="Jira integration is not configured"
 *      ),
 *      @OA\Response(
 *          response=502,
 *          description="Jira request failed"
 *      )
 * )
 */

// Read-only показатели дашборда на экране ввода ссылки. Задачу не трогает, поэтому идёт
// мимо TaskService — как api/calc_earnings.php.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$dashboard = DashboardService::createFromConfig(new TaskRepository($pdo));
if ($dashboard === null) {
    respond(['error' => 'Интеграция с Jira не настроена — заполните config/params.ini'], 422);
}

try {
    $metrics = $dashboard->metrics();
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond($metrics);
