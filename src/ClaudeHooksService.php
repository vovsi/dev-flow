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
 *
 * Канал доставки (Telegram или системное уведомление macOS) тоже нигде отдельно не хранится —
 * он читается из самой команды уже записанного хука (detectChannel()): второе место хранения
 * состояния рассинхронизировалось бы с файлом, который пользователь может править руками.
 */
final class ClaudeHooksService
{
    /** Сообщение уходит в Telegram-бота из [claude] (нужны telegram_bot_token и telegram_chat_id) */
    public const CHANNEL_TELEGRAM = 'telegram';

    /** Системное уведомление macOS: хук исполняется Claude Code на самом хосте, поэтому osascript доступен */
    public const CHANNEL_MACOS = 'macos';

    /** События Claude Code → ключ [claude] с текстом сообщения (см. Config::claudeNotifications()) */
    private const EVENT_TEXTS = [
        'Notification' => 'notification_text',
        'Stop' => 'stop_text',
    ];

    /** По этой подстроке в команде хука узнаётся канал macOS (см. detectChannel()) */
    private const MACOS_COMMAND_MARKER = 'osascript';

    /** Заголовок системного уведомления macOS — в нём же видно, от какого приложения оно пришло */
    private const MACOS_TITLE = 'Claude Code';

    /** Звук системного уведомления: без него уведомление появляется молча и легко пропускается */
    private const MACOS_SOUND = 'Glass';

    /**
     * @param array{settings_path: string, bot_token: string, chat_id: string, notification_text: string, stop_text: string} $config
     */
    private function __construct(private readonly array $config)
    {
    }

    /**
     * Конфиг читается лениво: секция [claude] целиком необязательна (каналу macOS нечего
     * настраивать), поэтому null возвращается только если сам params.ini не читается.
     */
    public static function createFromConfig(): ?self
    {
        try {
            return new self(Config::claudeNotifications());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Каналы, из которых пользователю есть что выбрать. macOS доступен всегда, Telegram —
     * только с заполненными токеном и chat_id: слать сообщение иначе просто некуда.
     *
     * @return list<string>
     */
    public function availableChannels(): array
    {
        $channels = [self::CHANNEL_MACOS];

        if ($this->config['bot_token'] !== '' && $this->config['chat_id'] !== '') {
            $channels[] = self::CHANNEL_TELEGRAM;
        }

        return $channels;
    }

    /** @return array{enabled: bool, channel: string} */
    public function state(): array
    {
        $hooks = $this->readSettings()['hooks'] ?? null;
        $enabled = is_array($hooks) && $hooks !== [];

        return [
            'enabled' => $enabled,
            'channel' => $enabled ? $this->detectChannel($hooks) : $this->availableChannels()[0],
        ];
    }

    /**
     * @return array{enabled: bool, channel: string} итоговое состояние (равно переданному, если запись прошла)
     */
    public function setEnabled(bool $enabled, string $channel): array
    {
        if (!in_array($channel, $this->availableChannels(), true)) {
            throw new RuntimeException('Канал уведомлений «' . $channel . '» недоступен');
        }

        $settings = $this->readSettings();

        if ($enabled) {
            $settings['hooks'] = $this->buildHooks($channel);
        } else {
            unset($settings['hooks']);
        }

        $this->writeSettings($settings);

        return ['enabled' => $enabled, 'channel' => $channel];
    }

    /** Блок hooks для settings.json: по одной команде выбранного канала на каждое событие */
    private function buildHooks(string $channel): array
    {
        $hooks = [];
        foreach (self::EVENT_TEXTS as $event => $textKey) {
            $command = $channel === self::CHANNEL_MACOS
                ? $this->macosCommand($this->config[$textKey])
                : $this->telegramCommand($this->config[$textKey]);

            $hooks[$event] = [[
                'matcher' => '',
                'hooks' => [[
                    'type' => 'command',
                    'command' => $command,
                ]],
            ]];
        }

        return $hooks;
    }

    private function detectChannel(array $hooks): string
    {
        $raw = (string) json_encode($hooks);

        return str_contains($raw, self::MACOS_COMMAND_MARKER) ? self::CHANNEL_MACOS : self::CHANNEL_TELEGRAM;
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

    private function macosCommand(string $text): string
    {
        $script = sprintf(
            'display notification %s with title %s sound name %s',
            self::appleScriptString($text),
            self::appleScriptString(self::MACOS_TITLE),
            self::appleScriptString(self::MACOS_SOUND)
        );

        return 'osascript -e ' . escapeshellarg($script);
    }

    /** Текст внутрь AppleScript-строки: кавычка или слэш из конфига иначе оборвали бы скрипт */
    private static function appleScriptString(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
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
