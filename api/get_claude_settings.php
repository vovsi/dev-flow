<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\ClaudeHooksService;

/**
 * @OA\Post(
 *     path="/get_claude_settings",
 *     summary="Get Claude Code notifications state.",
 *     description="This method returns whether Claude Code notification hooks are currently enabled in the desktop app's settings.json, which delivery channel they use and which channels are available.",
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
 *              @OA\Property(property="enabled", type="boolean", example=false),
 *              @OA\Property(property="channel", type="string", example="macos"),
 *              @OA\Property(property="channels", type="array", @OA\Items(type="string"))
 *          )
 *      )
 * )
 */

// Read-only: раздел «Claude» в настройках приложения читает текущее состояние тумблера и
// выбранный канал доставки. available=false — конфиг не читается вовсе, раздел на фронте не
// показывается; сама секция [claude] необязательна, каналу macOS настраивать нечего.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$service = ClaudeHooksService::createFromConfig();
if ($service === null) {
    respond(['available' => false, 'enabled' => false, 'channel' => '', 'channels' => []]);
}

try {
    $state = $service->state();
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond([
    'available' => true,
    'enabled' => $state['enabled'],
    'channel' => $state['channel'],
    'channels' => $service->availableChannels(),
]);
