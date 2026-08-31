<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Показатели дашборда на экране ввода ссылки. Service поверх JiraSyncService: знает
 * бизнес-правила показателей (что считать «зависшей» задачей), но не знает про HTTP.
 *
 * Новый числовой показатель добавляется методом-расчётом + строкой в metrics(); эндпоинт
 * api/dashboard.php при этом не меняется.
 */
final class DashboardService
{
    /**
     * @param int       $stalePullRequestHours Сколько часов в статусе Pull request считаем
     *                                         нормой — дольше задача «зависла»
     * @param int       $staleBlockedHours     То же для статуса Blocked
     * @param list<int> $nonWorkingWeekdays    Дни недели (ISO-8601: 1 = Пн … 7 = Вс), которые
     *                                         не идут в счёт часов «зависания»
     */
    public function __construct(
        private readonly JiraSyncService $jiraSync,
        private readonly string $pullRequestStatus,
        private readonly string $blockedStatus,
        private readonly int $stalePullRequestHours = Config::STALE_PULL_REQUEST_HOURS_DEFAULT,
        private readonly int $staleBlockedHours = Config::STALE_BLOCKED_HOURS_DEFAULT,
        private readonly array $nonWorkingWeekdays = [],
    ) {
    }

    /**
     * Интеграция с Jira опциональна (как и у JiraSyncService) — без настроенного
     * config/params.ini возвращает null, а не бросает исключение.
     */
    public static function createFromConfig(TaskRepository $tasks): ?self
    {
        $jiraSync = JiraSyncService::createFromConfig($tasks);
        if ($jiraSync === null) {
            return null;
        }

        return new self(
            $jiraSync,
            Config::atlassianPullRequestStatus(),
            Config::atlassianBlockedStatus(),
            Config::stalePullRequestHours(),
            Config::staleBlockedHours(),
            Config::nonWorkingWeekdays()
        );
    }

    /**
     * @return array<string, array{count: int, hours: int, status: string, non_working_weekdays: list<int>, tasks: list<array{task_id: string, title: string, status: string, link: string}>}>
     */
    public function metrics(): array
    {
        // Таймзона пользователя Jira, а не сервера: в ней JQL трактует дату порога, а
        // контейнер живёт в UTC и на границах суток давал бы сдвиг. Читается один раз на все
        // показатели — иначе каждый дёргал бы /myself за одним и тем же значением
        $now = new DateTimeImmutable('now', $this->jiraSync->getUserTimeZone());

        return [
            'stale_pull_requests' => $this->stuckInStatus($now, $this->pullRequestStatus, $this->stalePullRequestHours),
            'stale_blocked' => $this->stuckInStatus($now, $this->blockedStatus, $this->staleBlockedHours),
        ];
    }

    /**
     * Общий расчёт показателя «задачи на мне, висящие в статусе дольше порога рабочих часов» —
     * оба текущих показателя отличаются только статусом и порогом, поэтому счёт один.
     *
     * @return array{count: int, hours: int, status: string, non_working_weekdays: list<int>, tasks: list<array{task_id: string, title: string, status: string, link: string}>}
     */
    private function stuckInStatus(DateTimeImmutable $now, string $status, int $hours): array
    {
        $tasks = $this->jiraSync->getIssuesStuckInStatus($status, $this->staleCutoff($now, $hours));

        return [
            'count' => count($tasks),
            'hours' => $hours,
            'status' => $status,
            'non_working_weekdays' => $this->nonWorkingWeekdays,
            'tasks' => $tasks,
        ];
    }

    /**
     * Момент, раньше которого попадание в статус считается «зависанием»: отсчитываем $hours
     * назад от «сейчас», пропуская нерабочие дни целиком ([worktime].non_working_days).
     * Поэтому при пороге 24 ч перевод в Pull request в пятницу 17:00 станет «зависшим» только
     * в понедельник 17:00, а не в субботу.
     *
     * Шаг — час, а не сразу вся разница: сутки в нерабочий день должны вычитаться из
     * календаря, но не из отсчёта часов, и без пошагового прохода это не выразить.
     * Бесконечного цикла быть не может — Config::nonWorkingWeekdays() не возвращает все
     * семь дней.
     */
    private function staleCutoff(DateTimeImmutable $now, int $hours): DateTimeImmutable
    {
        $cursor = $now;
        $remainingHours = $hours;

        while ($remainingHours > 0) {
            $cursor = $cursor->modify('-1 hour');
            // Час относим к дню своего начала: шаг из понедельника 00:00 попадает в воскресенье
            if (!in_array((int) $cursor->format('N'), $this->nonWorkingWeekdays, true)) {
                $remainingHours--;
            }
        }

        return $cursor;
    }
}
