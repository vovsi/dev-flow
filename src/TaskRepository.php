<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Репозиторий для работы с таблицей tasks.
 */
final class TaskRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Ищет задачу по ссылке или по вычисленному task_id.
     */
    public function findByLinkOrTaskId(string $link, string $taskId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tasks WHERE task_link = :link OR task_id = :task_id LIMIT 1'
        );
        $stmt->execute(['link' => $link, 'task_id' => $taskId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function create(string $link, string $taskId): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tasks (task_link, task_id) VALUES (:link, :task_id)'
        );
        $stmt->execute(['link' => $link, 'task_id' => $taskId]);

        $id = (int) $this->db->lastInsertId();

        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function updateGitBranch(int $taskId, string $branch): void
    {
        $stmt = $this->db->prepare('UPDATE tasks SET git_branch = :branch WHERE id = :id');
        $stmt->execute(['branch' => $branch, 'id' => $taskId]);
    }

    public function updateJiraData(int $taskId, string $title, ?string $description, bool $storyPointsSet): void
    {
        $stmt = $this->db->prepare(
            'UPDATE tasks SET title = :title, description = :description, story_points_set = :story_points_set
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'story_points_set' => $storyPointsSet ? 1 : 0,
            'id' => $taskId,
        ]);
    }

    /**
     * Переключает режим Claude Code Skill для конкретной задачи (настройки → «Эта задача»,
     * видна только пока задача открыта). Раньше был глобальным конфигом ([mode] в
     * config/params.ini) — теперь у каждой задачи своё значение.
     */
    public function updateClaudeCodeSkillMode(int $taskId, bool $enabled): void
    {
        $stmt = $this->db->prepare('UPDATE tasks SET claude_code_skill_mode = :enabled WHERE id = :id');
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $taskId]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Извлекает идентификатор задачи (например PROJ-123) из ссылки Jira.
     * Если ссылка сама по себе похожа на идентификатор — возвращает её как есть.
     */
    public function extractTaskId(string $link): string
    {
        $taskId = $this->parseTaskId($link);
        if ($taskId !== null) {
            return $taskId;
        }

        // Фолбэк: последний сегмент пути без query-параметров. Нужен только для поиска уже
        // существующих задач (их task_id мог быть вычислен так же до появления валидации) —
        // для создания новой задачи используется строгий parseTaskId(), см. TaskService.
        $path = parse_url($link, PHP_URL_PATH) ?: $link;
        $segments = array_filter(explode('/', $path));

        return strtoupper((string) end($segments));
    }

    /**
     * Строго распознаёт ключ задачи Jira (PROJ-123) в ссылке или в самом ключе.
     * null — в строке нет ключа задачи, то есть это не ссылка на задачу Jira.
     */
    public function parseTaskId(string $link): ?string
    {
        $link = trim($link);

        if (preg_match('~/browse/([A-Za-z][A-Za-z0-9]*-\d+)~', $link, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('~^([A-Za-z][A-Za-z0-9]*-\d+)$~', $link, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        // Jira Cloud открывает задачу и другими URL (?selectedIssue=PROJ-1,
        // /jira/software/projects/PROJ/boards/1?...) — ключ ищем в любом месте ссылки,
        // но только как отдельный токен, чтобы не выхватить кусок случайного слова.
        if (preg_match('~(?:^|[^A-Za-z0-9])([A-Za-z][A-Za-z0-9]*-\d+)(?:[^A-Za-z0-9-]|$)~', $link, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}
