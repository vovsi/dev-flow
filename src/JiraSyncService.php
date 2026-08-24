<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Оркестрирует подтягивание заголовка/описания задачи из Jira и сохранение их в БД */
final class JiraSyncService
{
    public function __construct(
        private readonly JiraClient $client,
        private readonly TaskRepository $tasks,
    ) {
    }

    /**
     * Интеграция опциональна (нужен config/params.ini) — если её не настроили, возвращает null,
     * а не бросает исключение. Так вызывающий код (TaskService) не обязан знать о Config.
     */
    public static function createFromConfig(TaskRepository $tasks): ?self
    {
        try {
            $config = Config::atlassian();
        } catch (Throwable $e) {
            return null;
        }

        return new self(
            new JiraClient(
                $config['base_url'],
                $config['email'],
                $config['api_token'],
                Config::atlassianStoryPointsField(),
                Config::atlassianPullRequestStatus(),
                Config::atlassianDoingStatuses()
            ),
            $tasks
        );
    }

    public function sync(array $task): array
    {
        $issue = $this->client->fetchIssue($task['task_id']);
        $this->tasks->updateJiraData(
            (int) $task['id'],
            $issue['title'],
            $issue['description'],
            $issue['story_points_set']
        );

        return $this->tasks->findById((int) $task['id']);
    }

    /** Проверка, что задача с таким ключом реально существует в Jira (валидация ссылки при создании задачи) */
    public function issueExists(string $taskId): bool
    {
        return $this->client->issueExists($taskId);
    }

    public function updateStoryPoints(array $task, int $storyPoints): void
    {
        $this->client->updateStoryPoints($task['task_id'], $storyPoints);
    }

    public function transitionToPullRequest(array $task): void
    {
        $this->client->transitionToPullRequest($task['task_id']);
    }

    public function transitionToDoing(array $task): void
    {
        $this->client->transitionToDoing($task['task_id']);
    }

    public function getTimeSpentSeconds(array $task): int
    {
        return $this->client->fetchTimeSpentSeconds($task['task_id']);
    }

    /**
     * Суммарно затреканное сегодня время по всем задачам. $ensureTaskId (Jira-ключ) — задача,
     * которую нужно учесть даже если JQL-поиск Jira её ещё не отдал (см. JiraClient).
     */
    public function getTodayTimeSpentSeconds(?string $ensureTaskId = null): int
    {
        return $this->client->fetchTodayTimeSpentSeconds($ensureTaskId);
    }

    /**
     * Сегодняшнее затреканное время, разбитое по задачам. $ensureTaskId — как выше.
     *
     * @return list<array{task_id: string, title: string, status: string, link: string, seconds: int}>
     */
    public function getTodayTimeSpentBreakdown(?string $ensureTaskId = null): array
    {
        return $this->client->fetchTodayTimeSpentBreakdown($ensureTaskId);
    }

    /**
     * Задачи, попавшие в статус раньше $changedBefore и до сих пор в нём (метрика дашборда).
     *
     * @return list<array{task_id: string, title: string, status: string, link: string}>
     */
    public function getIssuesStuckInStatus(string $statusName, DateTimeImmutable $changedBefore): array
    {
        return $this->client->fetchIssuesStuckInStatus($statusName, $changedBefore);
    }

    /** Таймзона пользователя Jira — нужна тому, кто считает абсолютные границы для JQL */
    public function getUserTimeZone(): DateTimeZone
    {
        return $this->client->fetchUserTimeZone();
    }

    public function addWorklog(array $task, int $seconds): void
    {
        $this->client->addWorklog($task['task_id'], $seconds);
    }
}
