<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Обёртка над подключением к SQLite. Реализует единую точку доступа к БД (Singleton),
 * при первом обращении создаёт таблицы и сеет базовый чек-лист.
 */
final class Database
{
    private static ?PDO $connection = null;

    /**
     * Пункты чек-листа. Порядок массива = порядок отображения (sort_order).
     * code — стабильный идентификатор смысла пункта: по нему, а не по порядку/id,
     * определяется поведение на фронте и группы сброса при повторном открытии задачи.
     * Порядок можно свободно менять, пункты — добавлять и удалять: код каждого
     * существующего пункта не меняется, поэтому уже проставленные галочки не потеряют смысл.
     */
    private const CHECKLIST_ITEMS = [
        ['code' => 'story_points', 'title' => 'Указать Story Points'],
        ['code' => 'status_doing', 'title' => 'Перевести в статус Doing'],
        ['code' => 'git_branch', 'title' => 'Создать ветку в Git'],
        ['code' => 'skill_commit', 'title' => 'Закоммитить изменения'],
        ['code' => 'code_written', 'title' => 'Закоммитить код'],
        ['code' => 'pull_request', 'title' => 'Создать PR'],
        ['code' => 'claude_review', 'title' => 'Проверить PR Claude Code'],
        ['code' => 'pr_description', 'title' => 'Указать описание PR'],
        ['code' => 'status_ready_for_review', 'title' => 'PR`s переведены в Ready for review'],
        ['code' => 'jira_description', 'title' => 'Оставить описание в Jira'],
        ['code' => 'status_pull_request', 'title' => 'Перевести задачу в Pull Request'],
        ['code' => 'time_tracking', 'title' => 'Затрекать время'],
        ['code' => 'send_pr', 'title' => 'PR отправлен ревьюверу'],
    ];

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $path = dirname(__DIR__) . '/storage/app.sqlite';
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');

            self::$connection = $pdo;
            self::migrate($pdo);
        }

        return self::$connection;
    }

    private static function migrate(PDO $pdo): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        $pdo->exec($schema);

        // Для БД, созданных до появления code/sort_order, — добираем недостающие колонки
        self::ensureChecklistColumns($pdo);
        self::backfillMissingCodes($pdo);
        self::ensureTaskColumns($pdo);

        // ON CONFLICT (code) в seedChecklist требует уникального индекса по code
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_checklist_code ON checklist (code)');

        self::seedChecklist($pdo);
        self::pruneRemovedItems($pdo);
    }

    /** Добавляет колонки code/sort_order в checklist, если БД создана до их появления */
    private static function ensureChecklistColumns(PDO $pdo): void
    {
        $columns = array_column($pdo->query('PRAGMA table_info(checklist)')->fetchAll(PDO::FETCH_ASSOC), 'name');

        if (!in_array('code', $columns, true)) {
            $pdo->exec('ALTER TABLE checklist ADD COLUMN code TEXT');
        }
        if (!in_array('sort_order', $columns, true)) {
            $pdo->exec('ALTER TABLE checklist ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
        }
    }

    /**
     * Разово проставляет code существующим строкам (по совпадению текста с CHECKLIST_ITEMS),
     * чтобы миграция со старых версий схемы не потеряла привязку уже проставленных галочек.
     */
    private static function backfillMissingCodes(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT id, title FROM checklist WHERE code IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }

        $codeByTitle = array_column(self::CHECKLIST_ITEMS, 'code', 'title');
        $stmt = $pdo->prepare('UPDATE checklist SET code = :code WHERE id = :id');

        foreach ($rows as $row) {
            $code = $codeByTitle[$row['title']] ?? ('legacy_' . $row['id']);
            $stmt->execute(['code' => $code, 'id' => $row['id']]);
        }
    }

    /** Добавляет колонки title/description в tasks, если БД создана до интеграции с Jira */
    private static function ensureTaskColumns(PDO $pdo): void
    {
        $columns = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_ASSOC), 'name');

        if (!in_array('title', $columns, true)) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN title TEXT');
        }
        if (!in_array('description', $columns, true)) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN description TEXT');
        }
        if (!in_array('story_points_set', $columns, true)) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN story_points_set INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('claude_code_skill_mode', $columns, true)) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN claude_code_skill_mode INTEGER NOT NULL DEFAULT 1');
        }
    }

    /**
     * Записывает канонические пункты по стабильному code (upsert).
     * Так правки/добавление/удаление пунктов в CHECKLIST_ITEMS применяются к уже существующим
     * БД без сдвига смысла у уже отмеченных пунктов.
     */
    private static function seedChecklist(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO checklist (code, title, sort_order) VALUES (:code, :title, :sort_order)
             ON CONFLICT (code) DO UPDATE SET title = excluded.title, sort_order = excluded.sort_order'
        );

        foreach (self::CHECKLIST_ITEMS as $sortOrder => $item) {
            $stmt->execute(['code' => $item['code'], 'title' => $item['title'], 'sort_order' => $sortOrder]);
        }
    }

    /**
     * Удаляет пункты, которых больше нет в CHECKLIST_ITEMS (вместе с их отметками в task_checklist).
     * Так удаление пункта из кода реально убирает его у всех задач, а не оставляет мёртвой строкой.
     */
    private static function pruneRemovedItems(PDO $pdo): void
    {
        $currentCodes = array_column(self::CHECKLIST_ITEMS, 'code');
        $placeholders = implode(',', array_fill(0, count($currentCodes), '?'));

        $pdo->prepare(
            "DELETE FROM task_checklist WHERE checklist_id IN (SELECT id FROM checklist WHERE code NOT IN ($placeholders))"
        )->execute($currentCodes);

        $pdo->prepare("DELETE FROM checklist WHERE code NOT IN ($placeholders)")->execute($currentCodes);
    }
}
