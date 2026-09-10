<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

/**
 * Оркестрирует поиск/создание задачи и подготовку её чек-листа.
 * Для уже существующей задачи возвращается её текущий чек-лист как есть (без сброса —
 * сброс делает только ChecklistRepository::resetAll по кнопке «Начать заново»),
 * для новой задачи создаётся полный чек-лист без отметок.
 */
final class TaskService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ChecklistRepository $checklist,
        // Интеграция с Jira опциональна (см. JiraSyncService::createFromConfig) — без неё
        // задача всё равно открывается/создаётся, просто без заголовка/описания.
        private readonly ?JiraSyncService $jiraSync = null,
    ) {
    }

    /**
     * @throws RuntimeException если ссылка не ведёт на задачу Jira или такой задачи в Jira нет —
     *                          валидация ввода, задача при этом в БД не создаётся
     */
    public function findOrCreateByLink(string $link): array
    {
        $taskId = $this->tasks->parseTaskId($link);
        if ($taskId === null) {
            throw new RuntimeException(
                'Это не похоже на задачу Jira — вставьте ссылку вида https://…/browse/PROJ-123 или ключ задачи PROJ-123'
            );
        }

        $this->assertLinkSchemeIsWeb($link);

        $existing = $this->tasks->findByLinkOrTaskId($link, $taskId);

        if ($existing !== null) {
            $this->checklist->ensureRowsForTask((int) $existing['id']);
            $task = $this->syncJira($existing);

            return [
                'task' => $task,
                'checklist' => $this->checklist->getStatusesForTask((int) $task['id']),
                'isNew' => false,
            ];
        }

        $this->assertIssueExistsInJira($taskId);

        $task = $this->tasks->create($link, $taskId);
        $this->checklist->ensureRowsForTask((int) $task['id']);
        $task = $this->syncJira($task);

        return [
            'task' => $task,
            'checklist' => $this->checklist->getStatusesForTask((int) $task['id']),
            'isNew' => true,
        ];
    }

    /**
     * Пускает только ссылки со схемой http/https (или сам ключ задачи, у него схемы нет).
     * Ссылка сохраняется в БД как есть и подставляется на фронте в href заголовка задачи,
     * поэтому строка вида `javascript:alert(1)/PROJ-1` — она тоже содержит ключ задачи и
     * раньше проходила валидацию — превращала заголовок в ссылку, исполняющую код по клику.
     *
     * @throws RuntimeException если схема ссылки не http/https
     */
    private function assertLinkSchemeIsWeb(string $link): void
    {
        $scheme = strtolower((string) (parse_url(trim($link), PHP_URL_SCHEME) ?? ''));

        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException(
                'Ссылка на задачу должна начинаться с http:// или https:// — вставьте адрес задачи из браузера'
            );
        }
    }

    /**
     * Не даёт завести в БД задачу, которой нет в Jira (опечатка в ключе, ссылка на чужой
     * инстанс, случайная ссылка с похожим на ключ текстом). Проверяются только новые задачи —
     * уже открывавшаяся когда-то задача в проверке не нуждается.
     * Недоступность самой Jira валидацией не считается (как и в syncJira() — best-effort):
     * иначе при упавшей Jira приложением нельзя было бы пользоваться вообще.
     */
    private function assertIssueExistsInJira(string $taskId): void
    {
        if ($this->jiraSync === null) {
            return;
        }

        try {
            $exists = $this->jiraSync->issueExists($taskId);
        } catch (Throwable $e) {
            return;
        }

        if (!$exists) {
            throw new RuntimeException("Задача {$taskId} не найдена в Jira — проверьте ссылку");
        }
    }

    /**
     * Принудительно перечитывает заголовок/описание из Jira при каждом открытии задачи —
     * Jira всегда источник истины, локальные данные только кэш для отображения. Ошибки
     * (Jira недоступна или не настроена) не должны мешать открытию задачи — просто оставляем
     * её с тем, что уже было сохранено.
     */
    public function syncJira(array $task): array
    {
        if ($this->jiraSync === null) {
            return $task;
        }

        try {
            return $this->jiraSync->sync($task);
        } catch (Throwable $e) {
            return $task;
        }
    }

    /**
     * Проставляет Story Points в самой задаче Jira и отмечает пункт чек-листа выполненным.
     * Пункт отмечается только при успешном обновлении в Jira — без интеграции или при её
     * ошибке пользователь должен увидеть проблему, а не «выполненный» пункт с неверными данными.
     */
    public function updateStoryPoints(int $taskId, int $checklistId, int $storyPoints): array
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new RuntimeException('Задача не найдена');
        }
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        $this->jiraSync->updateStoryPoints($task, $storyPoints);
        $this->checklist->setDone($taskId, $checklistId, true);

        return [
            'task' => $task,
            'checklist' => $this->checklist->getStatusesForTask($taskId),
        ];
    }

    /**
     * Переводит задачу в Jira в статус Pull Request и отмечает пункт чек-листа выполненным.
     * Пункт отмечается только при успешном переходе в Jira — по той же причине, что и
     * updateStoryPoints() выше.
     */
    public function transitionToPullRequest(int $taskId, int $checklistId): array
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new RuntimeException('Задача не найдена');
        }
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        $this->jiraSync->transitionToPullRequest($task);
        $this->checklist->setDone($taskId, $checklistId, true);

        return [
            'task' => $task,
            'checklist' => $this->checklist->getStatusesForTask($taskId),
        ];
    }

    /**
     * Переводит задачу в Jira в статус Doing и отмечает пункт чек-листа выполненным.
     * Пункт отмечается только при успешном переходе в Jira — по той же причине, что и
     * updateStoryPoints() выше.
     */
    public function transitionToDoing(int $taskId, int $checklistId): array
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new RuntimeException('Задача не найдена');
        }
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        $this->jiraSync->transitionToDoing($task);
        $this->checklist->setDone($taskId, $checklistId, true);

        return [
            'task' => $task,
            'checklist' => $this->checklist->getStatusesForTask($taskId),
        ];
    }

    /**
     * Блок стандартных секций (Results/Testing/…) для модалки — read-only: `sections` это то,
     * что лежит в описании задачи прямо сейчас (null — блока там нет), `draft` — что показать
     * в редактируемом поле: тот же блок (или пустой шаблон, если блока ещё нет) с дописанными
     * пунктами, которые пользователь ввёл на предыдущих шагах чек-листа — $notes с ключами
     * `pr_link`, `database`, `config` (каждый идёт отдельным номером в свою секцию) и `other`
     * (уточнение в скобках к последнему PR); пустые значения ничего не дописывают. Читается из
     * самой Jira, а не из tasks.description: описание могли изменить руками уже после открытия
     * задачи, а этот текст пользователь правит в модалке и пишет обратно.
     *
     * Разделение на «в Jira» и «в поле» нужно самой модалке: по их несовпадению она понимает,
     * что блок изменился и его есть смысл сохранять (дописанные пункты в Jira при этом не
     * пишутся — сохранение остаётся отдельным действием пользователя).
     *
     * @param array<string, string> $notes
     * @return array{sections: ?string, draft: string}
     */
    public function getJiraDescriptionSections(int $taskId, array $notes = []): array
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new RuntimeException('Задача не найдена');
        }
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        $sections = JiraDescriptionService::extractSections($this->jiraSync->getDescription($task));

        $draft = JiraDescriptionService::withItems(
            $sections ?? JiraDescriptionService::template(),
            [
                JiraDescriptionService::SECTION_DATABASE => $notes['database'] ?? '',
                JiraDescriptionService::SECTION_CONFIG => $notes['config'] ?? '',
                JiraDescriptionService::SECTION_PULL_REQUESTS => $notes['pr_link'] ?? '',
            ]
        );

        return [
            'sections' => $sections,
            // Заметка «Другое» дописывается после ссылки на PR: она уточняет уже поставленный
            // выше пункт, а до этого последним номером был бы предыдущий PR
            'draft' => JiraDescriptionService::withPullRequestNote($draft, $notes['other'] ?? ''),
        ];
    }

    /**
     * Пишет блок секций в описание задачи: если блок там уже есть — заменяет его целиком,
     * если нет — дописывает в конец (см. JiraDescriptionService::replaceSections).
     * Описание перечитывается прямо перед записью: в модалке оно было прочитано раньше, и за
     * это время текст постановщика выше блока могли изменить.
     */
    public function saveJiraDescriptionSections(int $taskId, string $sections): void
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new RuntimeException('Задача не найдена');
        }
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        $current = $this->jiraSync->getDescription($task);
        $this->jiraSync->setDescription($task, JiraDescriptionService::replaceSections($current, $sections));
    }

    /** Читает уже затреканное в Jira время (без побочных эффектов) — для отображения в модалке перед добавлением нового worklog */
    public function getTimeSpentSeconds(int $taskId): int
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new RuntimeException('Задача не найдена');
        }
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        return $this->jiraSync->getTimeSpentSeconds($task);
    }

    /**
     * Суммарно затреканное сегодня время по всем задачам (read-only).
     *
     * $ensureTaskId (tasks.id, необязателен) — задача, которую нужно учесть даже если
     * JQL-поиск Jira её ещё не вернул: индекс поиска обновляется с задержкой, поэтому сразу
     * после трека задача из выборки выпадает (см. JiraClient::fetchTodayTimeSpentBreakdown).
     */
    public function getTodayTimeSpentSeconds(?int $ensureTaskId = null): int
    {
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        return $this->jiraSync->getTodayTimeSpentSeconds($this->resolveJiraKey($ensureTaskId));
    }

    /**
     * Сегодняшнее затреканное время, разбитое по задачам (read-only). $ensureTaskId — как выше.
     *
     * @return list<array{task_id: string, title: string, status: string, link: string, seconds: int}>
     */
    public function getTodayTimeSpentBreakdown(?int $ensureTaskId = null): array
    {
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        return $this->jiraSync->getTodayTimeSpentBreakdown($this->resolveJiraKey($ensureTaskId));
    }

    /** Внутренний id задачи → её Jira-ключ; неизвестная задача поводом для ошибки не является */
    private function resolveJiraKey(?int $taskId): ?string
    {
        if ($taskId === null || $taskId <= 0) {
            return null;
        }

        $task = $this->tasks->findById($taskId);

        return $task === null ? null : (string) $task['task_id'];
    }

    /**
     * Добавляет worklog в Jira, не касаясь чек-листа — для быстрого трека времени ползунком
     * (кружок рядом с индикатором затреканного за сегодня времени). Возвращает саму задачу.
     */
    public function logTimeOnly(int $taskId, int $seconds): array
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new RuntimeException('Задача не найдена');
        }
        if ($this->jiraSync === null) {
            throw new RuntimeException('Интеграция с Jira не настроена — заполните config/params.ini');
        }

        $this->jiraSync->addWorklog($task, $seconds);

        return $task;
    }

    /**
     * Добавляет worklog в Jira (время сверх уже затреканного) и отмечает пункт чек-листа
     * выполненным. Пункт отмечается только при успешном ответе Jira — по той же причине,
     * что и updateStoryPoints()/transitionToPullRequest() выше.
     */
    public function logTime(int $taskId, int $checklistId, int $seconds): array
    {
        $task = $this->logTimeOnly($taskId, $seconds);
        $this->checklist->setDone($taskId, $checklistId, true);

        return [
            'task' => $task,
            'checklist' => $this->checklist->getStatusesForTask($taskId),
        ];
    }

    /**
     * Полностью удаляет задачу и её чек-лист по ссылке/идентификатору.
     * Возвращает false, если задача не найдена.
     */
    public function deleteByLink(string $link): bool
    {
        $taskId = $this->tasks->extractTaskId($link);
        $existing = $this->tasks->findByLinkOrTaskId($link, $taskId);

        if ($existing === null) {
            return false;
        }

        $this->checklist->deleteForTask((int) $existing['id']);
        $this->tasks->delete((int) $existing['id']);

        return true;
    }
}
