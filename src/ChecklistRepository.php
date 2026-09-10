<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Репозиторий для работы с чек-листом и связью задача-пункт (task_checklist).
 */
final class ChecklistRepository
{
    /**
     * Пункты, которые при сбросе чек-листа (кнопка «Начать заново») принудительно
     * отмечаются выполненными, а не обнуляются — это
     * метаданные уровня задачи, которые не имеют смысла переделывать в каждом новом цикле
     * работы над ней. Указаны через стабильный code, а не id/позицию — при добавлении,
     * удалении или переупорядочивании пунктов в Database::CHECKLIST_ITEMS список не
     * сломается, а новые пункты по умолчанию попадут в «обнуляемые».
     * Сейчас пуст: `story_points` раньше был здесь, но с появлением
     * HIDE_IF_STORY_POINTS_ALREADY_SET_CODE его видимость и так полностью управляется
     * реальным статусом в Jira — форсировать is_done при сбросе больше не нужно, иначе
     * пункт остаётся скрытым (как выполненный) даже когда Story Points в Jira не проставлен.
     */
    private const ALWAYS_DONE_ON_RESET_CODES = [];

    /**
     * Пункт скрывается из чек-листа задачи, если в самой задаче Jira Story Points уже
     * проставлен (tasks.story_points_set, обновляется при каждой синхронизации,
     * см. JiraSyncService::sync) — тогда шаг не нужен, а не просто уже выполнен.
     */
    private const HIDE_IF_STORY_POINTS_ALREADY_SET_CODE = 'story_points';

    /**
     * Пункт скрывается, если задача в Jira уже в рабочем статусе (tasks.in_doing_status,
     * обновляется при каждой синхронизации по [atlassian].doing_status) — переводить её
     * туда повторно нечего. Правило по реальному статусу, а не по отметке в чек-листе:
     * задачу могли перевести в работу руками в Jira или из другого места.
     */
    private const HIDE_IF_ALREADY_IN_DOING_STATUS_CODE = 'status_doing';

    /**
     * Пункт скрывается, если в workflow задачи нет доступного перехода в статус
     * [atlassian].pull_request_status (tasks.pull_request_transition_available, обновляется при
     * каждой синхронизации по expand=transitions, см. JiraSyncService::sync) — переводить
     * задачу нечем, и попытка привела бы только к ошибке «в Jira не найден переход в статус».
     * Заодно пункт уходит, когда задача уже в этом статусе: перехода в текущий статус Jira
     * не отдаёт.
     */
    private const HIDE_IF_NO_PULL_REQUEST_TRANSITION_CODE = 'status_pull_request';

    /**
     * Пункт скрывается, если у задачи уже сохранено имя ветки (tasks.git_branch) — ветка
     * создана, и шаг не нужен. Правило по факту сохранённой ветки, а не по отметке пункта:
     * имя ветки живёт отдельно от чек-листа и остаётся у задачи после «Начать заново».
     */
    private const HIDE_IF_BRANCH_ALREADY_SET_CODE = 'git_branch';

    /**
     * Пункты, которые скрываются у задачи, пока включён её собственный флаг
     * tasks.claude_code_skill_mode (по умолчанию включён у каждой новой задачи, переключается
     * per-task в настройках приложения при открытой задаче — раньше был общим конфигом
     * [mode] в config/params.ini) — эти шаги за разработчика делает скилл Claude Code, поэтому
     * в чек-листе они лишние. Скрытые пункты не попадают в ответ ни одного эндпоинта, значит
     * не показываются, не участвуют в прогрессе и в очерёдности шагов. Отметки в task_checklist
     * при этом остаются в БД — выключение флага у задачи возвращает пункты вместе с уже
     * проставленными галочками. Поэтому пункты и не удалены из Database::CHECKLIST_ITEMS:
     * удаление оттуда стирает и сам пункт, и все галочки задач (Database::pruneRemovedItems),
     * то есть было бы необратимым.
     */
    private const CLAUDE_CODE_SKILL_MODE_HIDDEN_CODES = [
        'code_written',
        'pull_request',
        'claude_review',
        'pr_description',
    ];

    /**
     * Обратная сторона режима: пункты, которые существуют только пока у задачи включён
     * claude_code_skill_mode, а при выключенном скрываются тем же фильтром — это шаги самого
     * скилла, в обычном процессе их делать нечем.
     */
    private const CLAUDE_CODE_SKILL_MODE_ONLY_CODES = [
        'skill_commit',
    ];

    /**
     * Пункты, которые скрываются у задачи, пока включён её флаг tasks.waiting_for_deploy
     * («задача ждёт выливки другой задачи», настройки → «Эта задача»). Пока чужая задача не
     * вылита, PR этой задачи не переводят в Ready for review и не отправляют ревьюверу —
     * значит эти шаги не «ещё не сделаны», а не нужны вовсе. Механика та же, что у
     * CLAUDE_CODE_SKILL_MODE_HIDDEN_CODES: отметки в task_checklist остаются в БД, выключение
     * флага возвращает пункты вместе с уже проставленными галочками.
     */
    private const WAITING_FOR_DEPLOY_HIDDEN_CODES = [
        'status_ready_for_review',
        'send_pr',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Создаёт недостающие строки task_checklist для задачи (все пункты, is_done = 0).
     */
    public function ensureRowsForTask(int $taskId): void
    {
        $checklistIds = $this->db->query('SELECT id FROM checklist ORDER BY sort_order')
            ->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO task_checklist (task_id, checklist_id, is_done) VALUES (:task_id, :checklist_id, 0)'
        );

        foreach ($checklistIds as $checklistId) {
            $stmt->execute(['task_id' => $taskId, 'checklist_id' => $checklistId]);
        }
    }

    /**
     * Возвращает пункты чек-листа с отметкой выполнения для конкретной задачи.
     */
    public function getStatusesForTask(int $taskId): array
    {
        $params = ['task_id' => $taskId];

        // Все условия читаются прямо из своей же строки tasks (t.*) — и per-task флаги режимов,
        // и снятое из Jira состояние самой задачи, поэтому фильтр строится в самом запросе,
        // без обращения к Config
        $conditions = ''
            . $this->hiddenByFlagCondition('t.story_points_set = 1', [self::HIDE_IF_STORY_POINTS_ALREADY_SET_CODE], 'sp_set', $params)
            . $this->hiddenByFlagCondition('t.in_doing_status = 1', [self::HIDE_IF_ALREADY_IN_DOING_STATUS_CODE], 'in_doing', $params)
            . $this->hiddenByFlagCondition('t.pull_request_transition_available = 0', [self::HIDE_IF_NO_PULL_REQUEST_TRANSITION_CODE], 'no_pr_transition', $params)
            . $this->hiddenByFlagCondition("COALESCE(t.git_branch, '') != ''", [self::HIDE_IF_BRANCH_ALREADY_SET_CODE], 'branch_set', $params)
            . $this->hiddenByFlagCondition('t.claude_code_skill_mode = 1', self::CLAUDE_CODE_SKILL_MODE_HIDDEN_CODES, 'skill_hidden', $params)
            . $this->hiddenByFlagCondition('t.claude_code_skill_mode = 0', self::CLAUDE_CODE_SKILL_MODE_ONLY_CODES, 'skill_only', $params)
            . $this->hiddenByFlagCondition('t.waiting_for_deploy = 1', self::WAITING_FOR_DEPLOY_HIDDEN_CODES, 'deploy_hidden', $params);

        $stmt = $this->db->prepare(
            'SELECT c.id, c.code, c.title, tc.is_done
             FROM checklist c
             JOIN task_checklist tc ON tc.checklist_id = c.id
             JOIN tasks t ON t.id = tc.task_id
             WHERE tc.task_id = :task_id' . $conditions . '
             ORDER BY c.sort_order'
        );
        $stmt->execute($params);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => $row['code'],
                'title' => $row['title'],
                'is_done' => (bool) $row['is_done'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Собирает условие «пункты из $codes не показывать, пока верно $flagExpression» для
     * getStatusesForTask(). Общий хелпер вместо копипасты цикла на каждое правило скрытия по
     * колонке tasks: новое правило — это одна строка в getStatusesForTask(). Пустой список
     * кодов даёт пустое условие, а не битый SQL `IN ()`.
     */
    private function hiddenByFlagCondition(string $flagExpression, array $codes, string $paramPrefix, array &$params): string
    {
        if ($codes === []) {
            return '';
        }

        $placeholders = [];
        foreach ($codes as $index => $code) {
            $name = $paramPrefix . '_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $code;
        }

        return ' AND NOT (' . $flagExpression . ' AND c.code IN (' . implode(', ', $placeholders) . '))';
    }

    /**
     * Удаляет все отметки чек-листа задачи (перед удалением самой задачи).
     */
    public function deleteForTask(int $taskId): void
    {
        $stmt = $this->db->prepare('DELETE FROM task_checklist WHERE task_id = :task_id');
        $stmt->execute(['task_id' => $taskId]);
    }

    public function setDone(int $taskId, int $checklistId, bool $done): void
    {
        $stmt = $this->db->prepare(
            'UPDATE task_checklist SET is_done = :is_done WHERE task_id = :task_id AND checklist_id = :checklist_id'
        );
        $stmt->execute([
            'is_done' => $done ? 1 : 0,
            'task_id' => $taskId,
            'checklist_id' => $checklistId,
        ]);
    }

    /**
     * Сбрасывает чек-лист (кнопка «Начать заново»). Единственное место, где чек-лист
     * обнуляется при живой задаче — повторное открытие (ввод ссылки заново или клик по
     * «Последним задачам») больше не сбрасывает прогресс, см. TaskService::findOrCreateByLink.
     */
    public function resetAll(int $taskId): void
    {
        $stmt = $this->db->prepare('UPDATE task_checklist SET is_done = 0 WHERE task_id = :task_id');
        $stmt->execute(['task_id' => $taskId]);

        if (self::ALWAYS_DONE_ON_RESET_CODES === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count(self::ALWAYS_DONE_ON_RESET_CODES), '?'));
        $stmt = $this->db->prepare(
            "UPDATE task_checklist SET is_done = 1
             WHERE task_id = ? AND checklist_id IN (SELECT id FROM checklist WHERE code IN ($placeholders))"
        );
        $stmt->execute([$taskId, ...self::ALWAYS_DONE_ON_RESET_CODES]);
    }
}
