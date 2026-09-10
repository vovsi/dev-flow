<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\DeployInstructionService;
use App\LlmClientFactory;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Метод не поддерживается'], 405);
}

$input = readJsonInput();
// Инструкция вводится частями (база / конфиги / прочее), каждая по отдельности
// необязательна — нужна хотя бы одна, иначе оформлять нечего
$database = trim((string) ($input['database'] ?? ''));
$config = trim((string) ($input['config'] ?? ''));
$other = trim((string) ($input['other'] ?? ''));

if ($database === '' && $config === '' && $other === '') {
    respond(['error' => 'Не указана инструкция выливки'], 422);
}

try {
    $service = new DeployInstructionService(LlmClientFactory::createFromConfig());
    $deployInstruction = $service->generateFromParts($database, $config, $other);
} catch (\Throwable $e) {
    respond(['error' => $e->getMessage()], 502);
}

respond(['instruction' => $deployInstruction]);
