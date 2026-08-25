<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ClaudeHooksService;

/**
 * @OA\Post(
 *     path="/get_claude_settings",
 *     summary="Get Claude Code notifications state.",
 *     description="This method returns whether Claude Code Telegram notification hooks are currently enabled in the desktop app's settings.json.",
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
 *              @OA\Property(property="available", type="boolean", example=true),
 *              @OA\Property(property="enabled", type="boolean", example=false)
 *          )
 *      )
 * )
 */

// Read-only: раздел «Claude» в настройках приложения читает текущее состояние тумблера при
// открытии попапа настроек. available=false — секция [claude] не заполнена в
// config/params.ini, раздел на фронте не показывается вовсе (тот же приём, что у Jira/LLM).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$service = ClaudeHooksService::createFromConfig();
if ($service === null) {
    respond(['available' => false, 'enabled' => false]);
}

try {
    $enabled = $service->isEnabled();
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond(['available' => true, 'enabled' => $enabled]);
