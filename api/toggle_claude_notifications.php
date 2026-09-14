<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ClaudeHooksService;

/**
 * @OA\Post(
 *     path="/toggle_claude_notifications",
 *     summary="Toggle Claude Code notification hooks.",
 *     description="This method enables or disables the notification hooks (Notification and Stop events) in Claude Code desktop app's settings.json and selects their delivery channel: telegram (bot from the [claude] section of config/params.ini) or macos (system notification via osascript).",
 *     @OA\RequestBody(
 *         required=true,
 *         description="Request data",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 example={
 *                     "enabled": true,
 *                     "channel": "macos"
 *                 }
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *          response=200,
 *          description="Successfully toggled",
 *          @OA\JsonContent(
 *              @OA\Property(property="enabled", type="boolean", example=true),
 *              @OA\Property(property="channel", type="string", example="macos")
 *          )
 *      ),
 *      @OA\Response(
 *          response=422,
 *          description="Claude notifications are not configured or the channel is unavailable"
 *      ),
 *      @OA\Response(
 *          response=502,
 *          description="Failed to read or write settings.json"
 *      )
 * )
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$input = readJsonInput();
$enabled = (bool) ($input['enabled'] ?? false);
$channel = trim((string) ($input['channel'] ?? ''));

$service = ClaudeHooksService::createFromConfig();
if ($service === null) {
    respond(['error' => 'Не удалось прочитать config/params.ini'], 422);
}

// Канал не передан — берём первый доступный (тот же, что показывает get_claude_settings.php)
if ($channel === '') {
    $channel = $service->availableChannels()[0];
}

if (!in_array($channel, $service->availableChannels(), true)) {
    respond(['error' => 'Канал уведомлений недоступен — заполните [claude] в config/params.ini'], 422);
}

try {
    $result = $service->setEnabled($enabled, $channel);
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond($result);
