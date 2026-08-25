<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;

/** Читает и обновляет данные задачи в Atlassian Jira REST API v2 (Basic Auth email + API token) */
final class JiraClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $email,
        private readonly string $apiToken,
        private readonly string $storyPointsFieldId = 'customfield_10016',
        private readonly string $pullRequestStatusName = 'Pull request',
        /** @var list<string> */
        private readonly array $doingStatusNames = ['Doing'],
    ) {
    }

    /**
     * @return array{title: string, description: ?string, story_points_set: bool}
     */
    public function fetchIssue(string $taskId): array
    {
        // expand=renderedFields отдаёт description готовым HTML вместо ADF/wiki-разметки —
        // так не нужно разбирать формат описания отдельно для Jira Cloud и Server.
        $data = $this->request(
            'GET',
            '/rest/api/2/issue/' . rawurlencode($taskId)
                . '?fields=summary,description,' . rawurlencode($this->storyPointsFieldId)
                . '&expand=renderedFields',
            null,
            "для задачи {$taskId}"
        );

        return [
            'title' => (string) ($data['fields']['summary'] ?? ''),
            'description' => $data['renderedFields']['description'] ?? null,
            'story_points_set' => ($data['fields'][$this->storyPointsFieldId] ?? null) !== null,
        ];
    }

    /**
     * Есть ли такая задача в Jira. 404 — задачи нет (ошибкой не считается, это ответ
     * «не найдена»); любой другой сбой (сеть, 401/403, 5xx) пробрасывается исключением —
     * это проблема доступа/сервиса, а не приговор ссылке.
     */
    public function issueExists(string $taskId): bool
    {
        try {
            $this->request(
                'GET',
                '/rest/api/2/issue/' . rawurlencode($taskId) . '?fields=summary',
                null,
                "при проверке существования задачи {$taskId}"
            );
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function updateStoryPoints(string $taskId, int $storyPoints): void
    {
        $this->request(
            'PUT',
            '/rest/api/2/issue/' . rawurlencode($taskId),
            ['fields' => [$this->storyPointsFieldId => $storyPoints]],
            "при обновлении Story Points для задачи {$taskId}"
        );
    }

    /** Переводит задачу в статус $pullRequestStatusName (например «Pull request») через Jira transitions API */
    public function transitionToPullRequest(string $taskId): void
    {
        $transitionId = $this->findTransitionId($taskId, [$this->pullRequestStatusName]);
        if ($transitionId === null) {
            throw new RuntimeException(
                "В Jira не найден переход в статус «{$this->pullRequestStatusName}» для задачи {$taskId}"
            );
        }

        $this->request(
            'POST',
            '/rest/api/2/issue/' . rawurlencode($taskId) . '/transitions',
            ['transition' => ['id' => $transitionId]],
            "при переводе статуса задачи {$taskId}"
        );
    }

    /** Переводит задачу в первый найденный из статусов $doingStatusNames (например «Doing») через Jira transitions API */
    public function transitionToDoing(string $taskId): void
    {
        $transitionId = $this->findTransitionId($taskId, $this->doingStatusNames);
        if ($transitionId === null) {
            $statusList = implode('», «', $this->doingStatusNames);
            throw new RuntimeException(
                "В Jira не найден переход в статус «{$statusList}» для задачи {$taskId}"
            );
        }

        $this->request(
            'POST',
            '/rest/api/2/issue/' . rawurlencode($taskId) . '/transitions',
            ['transition' => ['id' => $transitionId]],
            "при переводе статуса задачи {$taskId}"
        );
    }

    /**
     * Ищет id перехода среди доступных для задачи переходов по списку названий целевого
     * статуса, в порядке приоритета — первое совпадение из $statusNames и выигрывает, даже
     * если дальше по списку транзиций встретится ещё одно имя-кандидат.
     *
     * @param list<string> $statusNames
     */
    private function findTransitionId(string $taskId, array $statusNames): ?string
    {
        $data = $this->request(
            'GET',
            '/rest/api/2/issue/' . rawurlencode($taskId) . '/transitions',
            null,
            "при получении переходов задачи {$taskId}"
        );
        $transitions = is_array($data['transitions'] ?? null) ? $data['transitions'] : [];

        foreach ($statusNames as $statusName) {
            foreach ($transitions as $transition) {
                // Сравниваем с названием целевого статуса (transition.to.name), а не с названием
                // самого перехода (transition.name) — это название кнопки-действия в workflow
                // и может не совпадать с именем статуса, в который она ведёт.
                $targetStatusName = (string) ($transition['to']['name'] ?? $transition['name'] ?? '');
                if ($targetStatusName !== '' && strcasecmp($targetStatusName, $statusName) === 0) {
                    return (string) $transition['id'];
                }
            }
        }

        return null;
    }

    /** Суммарное время (в секундах), уже затреканное в задаче (агрегат по всем worklog) */
    public function fetchTimeSpentSeconds(string $taskId): int
    {
        $data = $this->request(
            'GET',
            '/rest/api/2/issue/' . rawurlencode($taskId) . '?fields=timespent',
            null,
            "при получении затреканного времени задачи {$taskId}"
        );

        return (int) ($data['fields']['timespent'] ?? 0);
    }

    /**
     * Сколько времени текущий пользователь суммарно затрекал сегодня во все задачи Jira.
     * «Сегодня» считается в таймзоне пользователя Jira (её же имеет в виду startOfDay() в JQL),
     * а не сервера — контейнер живёт в UTC и на границах суток давал бы сдвиг.
     */
    public function fetchTodayTimeSpentSeconds(?string $ensureTaskId = null): int
    {
        $total = 0;
        foreach ($this->fetchTodayTimeSpentBreakdown($ensureTaskId) as $entry) {
            $total += $entry['seconds'];
        }

        return $total;
    }

    /**
     * Сегодняшнее затреканное время текущего пользователя, разбитое по задачам.
     *
     * @param string|null $ensureTaskId Jira-ключ задачи, которую нужно учесть даже если
     *                                  JQL-поиск её не вернул — см. комментарий ниже.
     *
     * @return list<array{task_id: string, title: string, status: string, link: string, seconds: int}>
     */
    public function fetchTodayTimeSpentBreakdown(?string $ensureTaskId = null): array
    {
        $me = $this->request('GET', '/rest/api/2/myself', null, 'при получении текущего пользователя');
        $accountId = (string) ($me['accountId'] ?? '');

        $timezone = $this->timeZoneOf($me);
        $dayStart = (new DateTimeImmutable('today', $timezone))->getTimestamp();
        $dayEnd = (new DateTimeImmutable('tomorrow', $timezone))->getTimestamp();

        // На Jira Cloud старый /rest/api/2/search удалён (HTTP 410) — актуальный поиск это /search/jql.
        // За одни сутки задач с ворклогом заведомо меньше 50, поэтому пагинация не нужна.
        $jql = rawurlencode('worklogAuthor = currentUser() AND worklogDate >= startOfDay()');
        $search = $this->request(
            'GET',
            "/rest/api/2/search/jql?jql={$jql}&fields=key,summary,status&maxResults=50",
            null,
            'при поиске задач с сегодняшним ворклогом'
        );

        $issues = [];
        $keys = [];
        foreach (($search['issues'] ?? []) as $issue) {
            $key = (string) ($issue['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $issues[] = $issue;
            $keys[] = $key;
        }

        // Индекс JQL-поиска на Jira Cloud обновляется с задержкой: только что добавленный
        // worklog в выборку выше ещё не попадает, и задача, в которую пользователь прямо
        // сейчас затрекал время, потерялась бы (у первого за день трека — вместе со всем
        // результатом). Поэтому её читаем напрямую по ключу, минуя поиск.
        if ($ensureTaskId !== null && $ensureTaskId !== '' && !in_array($ensureTaskId, $keys, true)) {
            // Подстраховка не должна ронять основной результат: задачи может уже не быть в Jira
            // (переименована, удалена) — тогда просто отдаём то, что нашёл поиск
            try {
                $issue = $this->request(
                    'GET',
                    '/rest/api/2/issue/' . rawurlencode($ensureTaskId) . '?fields=summary,status',
                    null,
                    "при получении задачи {$ensureTaskId}"
                );
                $issue['key'] = $ensureTaskId;
                $issues[] = $issue;
            } catch (Exception $e) {
                // намеренно игнорируем
            }
        }

        $result = [];
        foreach ($issues as $issue) {
            $key = (string) $issue['key'];

            $data = $this->request(
                'GET',
                '/rest/api/2/issue/' . rawurlencode($key) . '/worklog?startedAfter=' . ($dayStart * 1000),
                null,
                "при получении ворклогов задачи {$key}"
            );

            $seconds = 0;
            foreach (($data['worklogs'] ?? []) as $worklog) {
                // В задаче есть ворклоги и других участников — считаем только свои
                if ((string) ($worklog['author']['accountId'] ?? '') !== $accountId) {
                    continue;
                }
                // startedAfter отсекает только нижнюю границу суток, верхнюю проверяем сами
                $started = strtotime((string) ($worklog['started'] ?? ''));
                if ($started === false || $started < $dayStart || $started >= $dayEnd) {
                    continue;
                }
                $seconds += (int) ($worklog['timeSpentSeconds'] ?? 0);
            }

            if ($seconds > 0) {
                $result[] = [
                    'task_id' => $key,
                    'title' => (string) ($issue['fields']['summary'] ?? ''),
                    'status' => (string) ($issue['fields']['status']['name'] ?? ''),
                    'link' => $this->issueLink($key),
                    'seconds' => $seconds,
                ];
            }
        }

        return $result;
    }

    /**
     * Таймзона пользователя Jira — в ней же JQL понимает даты (startOfDay(), сравнения с
     * «yyyy/MM/dd HH:mm»), поэтому абсолютные границы для запроса считаются в ней, а не в
     * таймзоне сервера: контейнер живёт в UTC и давал бы сдвиг.
     */
    public function fetchUserTimeZone(): DateTimeZone
    {
        return $this->timeZoneOf(
            $this->request('GET', '/rest/api/2/myself', null, 'при получении текущего пользователя')
        );
    }

    /** Таймзона из ответа /myself; нераспознанное значение — UTC, это не повод ронять запрос */
    private function timeZoneOf(array $me): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($me['timeZone'] ?? 'UTC'));
        } catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Задачи, «зависшие» в статусе: сейчас находятся в нём и попали в него раньше момента
     * $changedBefore. Считаем только свои (assignee = currentUser()) — приложение
     * персональное, как и остальные обращения к Jira здесь.
     *
     * Порог приходит абсолютным моментом, а не числом часов: относительное «-24h» в JQL не
     * умеет пропускать нерабочие дни, а это бизнес-правило показателя (см. DashboardService).
     * Момент форматируется в таймзоне переданной даты — вызывающий берёт её у
     * fetchUserTimeZone(), потому что JQL трактует дату в таймзоне пользователя Jira.
     *
     * Момент попадания в статус берём из истории изменений (status CHANGED TO ... BEFORE),
     * а не из полей updated/statuscategorychangedate: updated меняет любой комментарий,
     * а категория статуса у Doing и Pull request одна и та же, и её дата не обновляется.
     *
     * JQL «status CHANGED TO ... BEFORE ...» проверяет сам факт перехода в статус когда-либо
     * до даты-порога, а не то, что *последний* переход произошёл до неё — поэтому задача,
     * успевшая выйти из статуса и вернуться в него уже после $changedBefore, тоже попадёт в
     * выборку. Кандидатов из JQL обычно единицы, поэтому по каждому из них дозапрашивается
     * changelog (`lastTransitionToStatus()`) и отсеиваются те, чей реальный последний переход
     * в статус случился не раньше $changedBefore — лишнего запроса на каждую задачу пользователя
     * это не требует, только на уже отфильтрованных JQL кандидатов.
     *
     * @return list<array{task_id: string, title: string, status: string, link: string}>
     */
    public function fetchIssuesStuckInStatus(string $statusName, DateTimeImmutable $changedBefore): array
    {
        $status = str_replace('"', '\\"', $statusName);
        $jql = sprintf(
            'assignee = currentUser() AND status = "%s" AND status CHANGED TO "%s" BEFORE "%s" ORDER BY updated ASC',
            $status,
            $status,
            $changedBefore->format('Y/m/d H:i')
        );

        // Тот же /search/jql, что и у сегодняшних ворклогов (старый /search на Jira Cloud
        // отвечает HTTP 410). Зависших задач у одного человека заведомо меньше 100 —
        // пагинация не нужна.
        $search = $this->request(
            'GET',
            '/rest/api/2/search/jql?jql=' . rawurlencode($jql) . '&fields=key,summary,status&maxResults=100',
            null,
            "при поиске задач, зависших в статусе «{$statusName}»"
        );

        $result = [];
        foreach (($search['issues'] ?? []) as $issue) {
            $key = (string) ($issue['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $lastChangedAt = $this->lastTransitionToStatus($key, $statusName);
            if ($lastChangedAt !== null && $lastChangedAt->getTimestamp() >= $changedBefore->getTimestamp()) {
                // Реальный последний переход в статус случился позже порога — задача не зависла,
                // JQL нашёл её по более старому переходу (см. комментарий метода выше)
                continue;
            }

            $result[] = [
                'task_id' => $key,
                'title' => (string) ($issue['fields']['summary'] ?? ''),
                'status' => (string) ($issue['fields']['status']['name'] ?? ''),
                'link' => $this->issueLink($key),
            ];
        }

        return $result;
    }

    /**
     * Момент последнего перехода задачи в статус $statusName по истории изменений.
     * null — переход не нашёлся в возвращённой истории (не должно случаться для задачи,
     * уже отобранной JQL-условием «status CHANGED TO», но не повод ронять показатель).
     *
     * Историй у персональной задачи заведомо меньше 100 — второй страницы не запрашиваем.
     */
    private function lastTransitionToStatus(string $taskId, string $statusName): ?DateTimeImmutable
    {
        $data = $this->request(
            'GET',
            '/rest/api/2/issue/' . rawurlencode($taskId) . '/changelog?maxResults=100',
            null,
            "при получении истории статусов задачи {$taskId}"
        );
        $histories = is_array($data['values'] ?? null) ? $data['values'] : [];

        // changelog отдаёт истории в хронологическом порядке — последнее совпадение и есть
        // самый недавний переход в статус
        $lastChangedAt = null;
        foreach ($histories as $history) {
            $items = is_array($history['items'] ?? null) ? $history['items'] : [];
            foreach ($items as $item) {
                if (
                    (string) ($item['field'] ?? '') !== 'status'
                    || strcasecmp((string) ($item['toString'] ?? ''), $statusName) !== 0
                ) {
                    continue;
                }

                $timestamp = strtotime((string) ($history['created'] ?? ''));
                if ($timestamp !== false) {
                    $lastChangedAt = new DateTimeImmutable('@' . $timestamp);
                }
            }
        }

        return $lastChangedAt;
    }

    /** Ссылка на задачу в веб-интерфейсе Jira (base_url из конфига + /browse/КЛЮЧ) */
    private function issueLink(string $key): string
    {
        return rtrim($this->baseUrl, '/') . '/browse/' . $key;
    }

    /** Добавляет worklog (доп. время сверх уже затреканного) к задаче */
    public function addWorklog(string $taskId, int $seconds): void
    {
        $this->request(
            'POST',
            '/rest/api/2/issue/' . rawurlencode($taskId) . '/worklog',
            ['timeSpentSeconds' => $seconds],
            "при добавлении времени в задачу {$taskId}"
        );
    }

    /** Общий curl-вызов Jira REST API — авторизация, таймаут и разбор ошибок в одном месте */
    private function request(string $method, string $path, ?array $body, string $errorContext): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode("{$this->email}:{$this->apiToken}"),
            ],
            CURLOPT_TIMEOUT => 20,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Не удалось обратиться к Jira ({$this->baseUrl}): {$error}");
        }
        if ($status < 200 || $status >= 300) {
            // Код исключения = HTTP-статус: по нему issueExists() отличает «задачи нет» (404)
            // от недоступности Jira или проблем с доступом
            throw new RuntimeException("Jira ответила с ошибкой (HTTP {$status}) {$errorContext}", (int) $status);
        }

        if ($response === '') {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Не удалось разобрать ответ Jira');
        }

        return $data;
    }
}
