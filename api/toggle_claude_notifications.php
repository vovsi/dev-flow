<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ClaudeHooksService;

/**
 * @OA\Post(
 *     path="/toggle_claude_notifications",
 *     summary="Toggle Claude Code notification hooks.",
 *     description="This method enables or disables the Telegram notification hooks (Notification and Stop events) in Claude Code desktop app's settings.json, based on the [claude] section in config/params.ini.",
 *     @OA\RequestBody(
 *         required=true,
 *         description="Request data",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 example={
 *                     "enabled": true
 *                 }
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *          response=200,
 *          description="Successfully toggled",
 *          @OA\JsonContent(
 *              @OA\Property(property="enabled", type="boolean", example=true)
 *          )
 *      ),
 *      @OA\Response(
 *          response=422,
 *          description="Claude notifications are not configured"
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

$service = ClaudeHooksService::createFromConfig();
if ($service === null) {
    respond(['error' => 'Уведомления Claude не настроены — заполните [claude] в config/params.ini'], 422);
}

try {
    $result = $service->setEnabled($enabled);
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond(['enabled' => $result]);
