<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** Читает config/params.ini (не в git — см. config/params.ini.example) */
final class Config
{
    /** Значения [worktime] по умолчанию — используются и при отсутствии config/params.ini (см. public/index.php) */
    public const WORK_TIME_DEFAULTS = [
        'start' => '09:00',
        'end' => '18:00',
        'daily_hours' => 8.0,
        'lunch_start' => '12:00',
        'lunch_end' => '13:00',
    ];

    /**
     * Нерабочие дни недели по умолчанию (ISO-8601: 1 = Пн … 7 = Вс) — суббота и воскресенье.
     * Используются показателем «зависшие PR» на дашборде (см. nonWorkingWeekdays()).
     */
    public const NON_WORKING_WEEKDAYS_DEFAULT = [6, 7];

    /** Порог показателя «зависшие PR» по умолчанию, часов (см. stalePullRequestHours()) */
    public const STALE_PULL_REQUEST_HOURS_DEFAULT = 24;

    /** Значения [salary] по умолчанию (см. salaryHourlyRateUsd()) */
    public const SALARY_DEFAULTS = [
        'monthly_usd' => 1500.0,
        'working_days_per_month' => 21.0,
    ];

    /** Значения [currency] по умолчанию (см. currency()) */
    public const CURRENCY_DEFAULTS = [
        'code' => 'UAH',
        'label' => 'грн',
    ];

    /** Адреса внешних сервисов по умолчанию (см. [services] в config/params.ini.example) */
    public const SERVICE_URL_DEFAULTS = [
        'exchange_rate_url' => 'https://open.er-api.com/v6/latest/USD',
        'quotes_url' => 'https://zenquotes.io/api/random',
    ];

    /** Названия дней недели для [worktime].non_working_days → номер ISO-8601 (сравнение по первым трём буквам) */
    private const WEEKDAY_ALIASES = [
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
        'sun' => 7,
    ];

    /** Ключи [docs] — ссылки на внутреннюю документацию команды, подставляемые в промпт Claude-ревью */
    private const REVIEW_DOC_KEYS = ['php_code_style', 'testing_standards', 'api_data_format'];

    /** Провайдеры нейронки для [llm].provider (см. llm() и LlmClientFactory) */
    public const LLM_PROVIDER_ANTHROPIC = 'anthropic';
    public const LLM_PROVIDER_LMSTUDIO = 'lmstudio';

    /** Модель Claude по умолчанию — самая дешёвая и быстрая, задач тут на пару строк текста */
    public const LLM_ANTHROPIC_MODEL_DEFAULT = 'claude-haiku-4-5';

    private static ?array $data = null;

    /**
     * Настройки нейронки, нормализованные под выбранного провайдера: наружу всегда уходит
     * `provider` + `model` и специфичные для провайдера ключи (api_key либо host). Так
     * LlmClientFactory и клиенты не разбирают конфиг сами, а переключение Claude ⇄ LM Studio —
     * это одна строка `provider` в params.ini (настройки второго провайдера остаются на месте).
     *
     * @return array{provider: string, model: string, api_key?: string, host?: string}
     */
    public static function llm(): array
    {
        $section = self::load()['llm'] ?? [];
        $provider = strtolower(self::stringOrDefault($section, 'provider', self::LLM_PROVIDER_LMSTUDIO));

        if ($provider === self::LLM_PROVIDER_ANTHROPIC) {
            $apiKey = self::stringOrDefault($section, 'anthropic_api_key', '');
            if ($apiKey === '') {
                throw new RuntimeException(
                    'В config/params.ini не задан llm.anthropic_api_key — ключ создаётся на https://platform.claude.com'
                );
            }

            return [
                'provider' => self::LLM_PROVIDER_ANTHROPIC,
                'api_key' => $apiKey,
                'model' => self::stringOrDefault($section, 'anthropic_model', self::LLM_ANTHROPIC_MODEL_DEFAULT),
            ];
        }

        $host = self::stringOrDefault($section, 'lmstudio_host', '');
        $model = self::stringOrDefault($section, 'lmstudio_model', '');
        if ($host === '' || $model === '') {
            throw new RuntimeException(
                'В config/params.ini не заданы llm.lmstudio_host и llm.lmstudio_model — скопируйте config/params.ini.example'
            );
        }

        return [
            'provider' => self::LLM_PROVIDER_LMSTUDIO,
            'host' => $host,
            'model' => $model,
        ];
    }

    public static function atlassian(): array
    {
        $data = self::load();

        if (!isset($data['atlassian']['base_url'], $data['atlassian']['email'], $data['atlassian']['api_token'])) {
            throw new RuntimeException(
                'В config/params.ini не заданы atlassian.base_url, atlassian.email и atlassian.api_token — скопируйте config/params.ini.example'
            );
        }

        return $data['atlassian'];
    }

    /**
     * ID кастомного поля Story Points в Jira — отличается между инстансами Jira Cloud/Server,
     * найти можно в URL при редактировании поля в настройках или через
     * GET /rest/api/2/field. Не задано в конфиге — используется значение по умолчанию,
     * подходящее для большинства инстансов Jira Cloud.
     */
    public static function atlassianStoryPointsField(): string
    {
        $data = self::load();
        $field = trim((string) ($data['atlassian']['story_points_field'] ?? ''));

        return $field !== '' ? $field : 'customfield_10016';
    }

    /** Название статуса Jira для перехода по пункту «Перевести задачу в Pull Request». Не задано в конфиге — по умолчанию "Pull request" */
    public static function atlassianPullRequestStatus(): string
    {
        $data = self::load();
        $status = trim((string) ($data['atlassian']['pull_request_status'] ?? ''));

        return $status !== '' ? $status : 'Pull request';
    }

    /**
     * Названия статусов Jira для перехода по пункту «Перевести в статус Doing», в порядке
     * приоритета — первый найденный в workflow задачи переход и будет использован. Формат —
     * «Doing, В работе» (см. commaList()): так можно задать основное название статуса и
     * запасные, если в разных проектах Jira команды оно называется по-разному. Не задано в
     * конфиге — по умолчанию только "Doing".
     *
     * @return list<string>
     */
    public static function atlassianDoingStatuses(): array
    {
        $statuses = self::commaList(self::load()['atlassian'] ?? [], 'doing_status');

        return $statuses !== [] ? $statuses : ['Doing'];
    }

    /**
     * Рабочий день и норма часов для ползунка быстрого трека времени: границы ползунка
     * (start/end), норма, по которой затреканное за день время подсвечивается оранжевым
     * (меньше нормы) или зелёным, и обеденный перерыв (lunch_start/lunch_end) — он
     * подсвечивается на ползунке и не идёт в трекинг. Некорректные значения (не HH:MM,
     * конец не позже начала, норма ≤ 0) молча заменяются на WORK_TIME_DEFAULTS — иначе
     * ползунок оказался бы мёртвым. Обед, не влезающий в рабочий день, просто отключается
     * (пустые строки) — это не повод ломать сам ползунок.
     */
    public static function workTime(): array
    {
        $section = self::load()['worktime'] ?? [];

        $start = self::normalizeClock((string) ($section['start'] ?? ''), self::WORK_TIME_DEFAULTS['start']);
        $end = self::normalizeClock((string) ($section['end'] ?? ''), self::WORK_TIME_DEFAULTS['end']);
        // HH:MM с ведущим нулём сравнимы как строки
        if ($end <= $start) {
            $start = self::WORK_TIME_DEFAULTS['start'];
            $end = self::WORK_TIME_DEFAULTS['end'];
        }

        $dailyHours = (float) ($section['daily_hours'] ?? 0);

        $lunchStart = self::normalizeClock(
            (string) ($section['lunch_start'] ?? ''),
            self::WORK_TIME_DEFAULTS['lunch_start']
        );
        $lunchEnd = self::normalizeClock(
            (string) ($section['lunch_end'] ?? ''),
            self::WORK_TIME_DEFAULTS['lunch_end']
        );
        if ($lunchEnd <= $lunchStart || $lunchStart < $start || $lunchEnd > $end) {
            $lunchStart = '';
            $lunchEnd = '';
        }

        return [
            'start' => $start,
            'end' => $end,
            'daily_hours' => $dailyHours > 0 ? $dailyHours : self::WORK_TIME_DEFAULTS['daily_hours'],
            'lunch_start' => $lunchStart,
            'lunch_end' => $lunchEnd,
        ];
    }

    /**
     * Дни недели, которые не идут в счёт часов «зависания» задачи (показатель дашборда):
     * перевели PR в пятницу вечером — до утра рабочего дня задача зависшей не считается.
     * Формат — «sat, sun» (можно полными названиями) либо номера ISO-8601 «6, 7».
     * Ключ не задан — суббота и воскресенье; задан пустым — не пропускаем ни один день.
     * Перечислены все семь дней — список игнорируется: иначе показатель не сработал бы никогда.
     *
     * @return list<int>
     */
    public static function nonWorkingWeekdays(): array
    {
        $section = self::load()['worktime'] ?? [];
        if (!array_key_exists('non_working_days', $section)) {
            return self::NON_WORKING_WEEKDAYS_DEFAULT;
        }

        $days = [];
        foreach (self::commaList($section, 'non_working_days') as $token) {
            $day = self::parseWeekday($token);
            if ($day !== null && !in_array($day, $days, true)) {
                $days[] = $day;
            }
        }
        sort($days);

        return count($days) >= 7 ? [] : $days;
    }

    /**
     * Сколько часов в статусе Pull request считается нормой: дольше — задача попадает в
     * показатель «зависшие PR» на дашборде. Считаются только часы рабочих дней (нерабочие
     * пропускаются, см. nonWorkingWeekdays()). Некорректное значение (не число, ≤ 0) молча
     * заменяется на STALE_PULL_REQUEST_HOURS_DEFAULT — показатель не должен ломаться из-за опечатки.
     */
    public static function stalePullRequestHours(): int
    {
        $hours = (int) (self::load()['dashboard']['stale_pull_request_hours'] ?? 0);

        return $hours > 0 ? $hours : self::STALE_PULL_REQUEST_HOURS_DEFAULT;
    }

    /** «sat» / «saturday» / «6» → 6 (ISO-8601), непонятное значение → null */
    private static function parseWeekday(string $token): ?int
    {
        $token = strtolower(trim($token));

        if (preg_match('/^[1-7]$/', $token) === 1) {
            return (int) $token;
        }

        return self::WEEKDAY_ALIASES[substr($token, 0, 3)] ?? null;
    }

    /**
     * Часовая ставка в USD для расчёта заработка за день в модалке поздравления
     * (EarningsService, показывается при достижении [worktime].daily_hours) — оклад в месяц
     * делится на условное число рабочих часов в месяце. Некорректные значения (≤ 0) молча
     * заменяются на SALARY_DEFAULTS, как и у workTime().
     */
    public static function salaryHourlyRateUsd(): float
    {
        $section = self::load()['salary'] ?? [];

        $monthlyUsd = (float) ($section['monthly_usd'] ?? 0);
        if ($monthlyUsd <= 0) {
            $monthlyUsd = self::SALARY_DEFAULTS['monthly_usd'];
        }

        $workingDays = (float) ($section['working_days_per_month'] ?? 0);
        if ($workingDays <= 0) {
            $workingDays = self::SALARY_DEFAULTS['working_days_per_month'];
        }

        return $monthlyUsd / ($workingDays * self::workTime()['daily_hours']);
    }

    /**
     * Валюта, в которую пересчитывается заработок в модалке поздравления: code — код для
     * курса USD → X у ExchangeRateClient, label — подпись для фронта («грн»). Пустые
     * значения молча заменяются на CURRENCY_DEFAULTS, как и у workTime()/salaryHourlyRateUsd().
     *
     * @return array{code: string, label: string}
     */
    public static function currency(): array
    {
        $section = self::load()['currency'] ?? [];

        return [
            'code' => strtoupper(self::stringOrDefault($section, 'code', self::CURRENCY_DEFAULTS['code'])),
            'label' => self::stringOrDefault($section, 'label', self::CURRENCY_DEFAULTS['label']),
        ];
    }

    /** Адрес API курсов валют (ExchangeRateClient). Не задан в конфиге — SERVICE_URL_DEFAULTS */
    public static function exchangeRateUrl(): string
    {
        return self::stringOrDefault(
            self::load()['services'] ?? [],
            'exchange_rate_url',
            self::SERVICE_URL_DEFAULTS['exchange_rate_url']
        );
    }

    /** Адрес API цитат (QuoteClient). Не задан в конфиге — SERVICE_URL_DEFAULTS */
    public static function quotesUrl(): string
    {
        return self::stringOrDefault(
            self::load()['services'] ?? [],
            'quotes_url',
            self::SERVICE_URL_DEFAULTS['quotes_url']
        );
    }

    private static function stringOrDefault(array $section, string $key, string $fallback): string
    {
        $value = trim((string) ($section[$key] ?? ''));

        return $value !== '' ? $value : $fallback;
    }

    private static function normalizeClock(string $value, string $fallback): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value)) === 1 ? trim($value) : $fallback;
    }

    /** Ники ревьюверов для команды `gh pr create --reviewer`. Не задано в конфиге — пустой список (флаг просто не добавляется в команду) */
    public static function githubReviewers(): array
    {
        return self::commaList(self::load()['github'] ?? [], 'reviewers');
    }

    /**
     * Пункты «Rebase …» в дропдауне git-команд: названия репозиториев команды и их базовых
     * веток («API3:main, Adminka:dev»). Специфично для конкретной команды, поэтому в код не
     * зашивается — не задано в конфиге, пунктов rebase в дропдауне просто нет.
     *
     * @return list<array{label: string, base: string}>
     */
    public static function gitRebaseTargets(): array
    {
        $targets = [];
        foreach (self::commaList(self::load()['git'] ?? [], 'rebase_targets') as $target) {
            [$label, $base] = array_pad(explode(':', $target, 2), 2, '');
            $label = trim($label);
            $base = trim($base);
            if ($label !== '' && $base !== '') {
                $targets[] = ['label' => $label, 'base' => $base];
            }
        }

        return $targets;
    }

    /**
     * Репозитории, в которых миграции не выполняются — упоминаются в промпте Claude-ревью
     * как исключение. Не задано в конфиге — блок исключения в промпт не попадает.
     *
     * @return list<string>
     */
    public static function reviewSkipMigrationRepos(): array
    {
        return self::commaList(self::load()['templates'] ?? [], 'review_skip_migration_repos');
    }

    /**
     * Ссылки на внутреннюю документацию команды (Confluence и т.п.) для промпта Claude-ревью.
     * Адреса конкретного инстанса, поэтому в коде их нет — ключ не задан, ссылка в промпт
     * просто не подставляется.
     *
     * @return array<string, string>
     */
    public static function reviewDocLinks(): array
    {
        $section = self::load()['docs'] ?? [];

        $links = [];
        foreach (self::REVIEW_DOC_KEYS as $key) {
            $links[$key] = self::stringOrDefault($section, $key, '');
        }

        return $links;
    }

    /**
     * Режим «часть шагов делает скилл Claude Code» ([mode].claude_code_skill_mode = 1):
     * пункты чек-листа, которые в этом режиме не нужны, скрываются целиком
     * (список — ChecklistRepository::CLAUDE_CODE_SKILL_MODE_HIDDEN_CODES). 0, не задано или
     * нет самого config/params.ini — режим выключен, чек-лист полный. Единственный геттер,
     * который сам глотает отсутствие конфига: его зовёт ChecklistRepository, а чек-лист
     * обязан работать и без настроенных интеграций.
     */
    public static function claudeCodeSkillMode(): bool
    {
        try {
            $section = self::load()['mode'] ?? [];
        } catch (RuntimeException) {
            return false;
        }

        return trim((string) ($section['claude_code_skill_mode'] ?? '')) === '1';
    }

    /**
     * Значение вида «a, b, c» → список непустых элементов (общий формат для всех
     * перечислений в params.ini: ревьюверы, rebase-цели, репозитории-исключения)
     *
     * @return list<string>
     */
    private static function commaList(array $section, string $key): array
    {
        $raw = trim((string) ($section[$key] ?? ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private static function load(): array
    {
        if (self::$data === null) {
            $path = dirname(__DIR__) . '/config/params.ini';
            if (!is_file($path)) {
                throw new RuntimeException(
                    'Не найден config/params.ini — скопируйте config/params.ini.example и заполните его'
                );
            }

            $parsed = parse_ini_file($path, true);
            self::$data = $parsed === false ? [] : $parsed;
        }

        return self::$data;
    }
}
