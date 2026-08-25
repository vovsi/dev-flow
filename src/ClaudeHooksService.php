<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

/**
 * Включает и выключает уведомления Claude Code — блок `hooks` в settings.json десктопного
 * приложения (раздел «Claude» в настройках DevFlow).
 *
 * Правило одно: «включено» = в settings.json есть непустой `hooks`. Включение записывает туда
 * блок, собранный из [claude] в config/params.ini, выключение — удаляет ключ целиком. Поэтому
 * содержимое хуков живёт в конфиге, а не в settings.json: тумблер не обязан помнить, что там
 * лежало до него.
 */
final class ClaudeHooksService
{
    /** События Claude Code → ключ [claude] с текстом сообщения (см. Config::claudeNotifications()) */
    private const EVENT_TEXTS = [
        'Notification' => 'notification_text',
        'Stop' => 'stop_text',
    ];

    /**
     * @param array{settings_path: string, bot_token: string, chat_id: string, notification_text: string, stop_text: string} $config
     */
    private function __construct(private readonly array $config)
    {
    }

    /**
     * Фича опциональна (нужна секция [claude] в config/params.ini) — не настроена, возвращает
     * null, и раздел «Claude» в настройках просто не показывается (как Jira и нейронка).
     */
    public static function createFromConfig(): ?self
    {
        try {
            return new self(Config::claudeNotifications());
        } catch (Throwable) {
            return null;
        }
    }

    public function isEnabled(): bool
    {
        $hooks = $this->readSettings()['hooks'] ?? null;

        return is_array($hooks) && $hooks !== [];
    }

    /** @return bool итоговое состояние (равно $enabled, если запись прошла) */
    public function setEnabled(bool $enabled): bool
    {
        $settings = $this->readSettings();

        if ($enabled) {
            $settings['hooks'] = $this->buildHooks();
        } else {
            unset($settings['hooks']);
        }

        $this->writeSettings($settings);

        return $enabled;
    }

    /** Блок hooks для settings.json: по одной команде curl в Telegram на каждое событие */
    private function buildHooks(): array
    {
        $hooks = [];
        foreach (self::EVENT_TEXTS as $event => $textKey) {
            $hooks[$event] = [[
                'matcher' => '',
                'hooks' => [[
                    'type' => 'command',
                    'command' => $this->telegramCommand($this->config[$textKey]),
                ]],
            ]];
        }

        return $hooks;
    }

    private function telegramCommand(string $text): string
    {
        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $this->config['bot_token']);

        // Значения экранируются для shell: и текст сообщения, и chat_id приходят из конфига,
        // но попадают в командную строку, которую Claude Code исполняет как есть
        return sprintf(
            'curl -s -X POST %s -d %s -d %s',
            escapeshellarg($url),
            escapeshellarg('chat_id=' . $this->config['chat_id']),
            escapeshellarg('text=' . $text)
        );
    }

    private function readSettings(): array
    {
        $path = $this->config['settings_path'];

        // Файла ещё нет — Claude Code создаст его сам, а включение тумблера создаст его раньше
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Не удалось прочитать ' . $path);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Файл ' . $path . ' содержит некорректный JSON');
        }

        return $data;
    }

    private function writeSettings(array $settings): void
    {
        $path = $this->config['settings_path'];
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Не удалось сериализовать настройки Claude Code');
        }

        $isNew = !is_file($path);

        // Пишем в существующий файл, а не через временный файл с rename: приложение работает в
        // контейнере под root, и переименование сделало бы settings.json файлом root — сам
        // Claude Code (он запущен от пользователя) потерял бы к нему доступ.
        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать ' . $path);
        }

        // Настройки Claude Code содержат токены — новый файл сразу закрываем от чужих глаз
        if ($isNew) {
            @chmod($path, 0600);
        }
    }
}
