-- Схема БД DevFlow

CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_link TEXT NOT NULL UNIQUE,
    task_id TEXT NOT NULL,
    -- title/description — заголовок и описание из Jira. NULL, пока не стянуты
    -- (см. TaskService::syncJiraIfMissing — тянутся один раз, дальше берутся из БД).
    title TEXT DEFAULT NULL,
    description TEXT DEFAULT NULL,
    -- признак того, что в самой задаче Jira Story Points уже проставлен (обновляется при
    -- каждой синхронизации, см. JiraSyncService::sync) — используется, чтобы скрывать пункт
    -- чек-листа «Указать Story Points», когда он не нужен.
    story_points_set INTEGER NOT NULL DEFAULT 0,
    -- текущий статус задачи в Jira — один из [atlassian].doing_status (обновляется при каждой
    -- синхронизации, см. JiraSyncService::sync). Задачу могли перевести в работу руками в
    -- Jira, поэтому пункт «Перевести в статус Doing» скрывается по реальному статусу, а не по
    -- отметке в чек-листе — см. ChecklistRepository::HIDE_IF_ALREADY_IN_DOING_STATUS_CODE.
    in_doing_status INTEGER NOT NULL DEFAULT 0,
    git_branch TEXT DEFAULT NULL,
    -- режим «часть шагов делает скилл Claude Code» для этой конкретной задачи (раньше был
    -- глобальной настройкой [mode].claude_code_skill_mode в config/params.ini, теперь у каждой
    -- задачи свой флаг, переключается в настройках приложения при открытой задаче). Включён
    -- по умолчанию — см. ChecklistRepository::CLAUDE_CODE_SKILL_MODE_HIDDEN_CODES/ONLY_CODES.
    claude_code_skill_mode INTEGER NOT NULL DEFAULT 1,
    -- задача ждёт, пока выльют другую задачу: до выливки PR не переводят в Ready for review и
    -- не отправляют ревьюверу, поэтому соответствующие пункты чек-листа скрываются
    -- (см. ChecklistRepository::WAITING_FOR_DEPLOY_HIDDEN_CODES). Выключен по умолчанию.
    waiting_for_deploy INTEGER NOT NULL DEFAULT 0,
    stat TEXT NOT NULL DEFAULT 'active'
);

CREATE TABLE IF NOT EXISTS checklist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    -- code — стабильный идентификатор смысла пункта (используется в бизнес-логике и на фронте).
    -- id и sort_order можно менять/переставлять, code — никогда: на нём держится привязка
    -- уже проставленных галочек в task_checklist к правильному пункту.
    code TEXT NOT NULL,
    title TEXT NOT NULL,
    sort_order INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS task_checklist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    checklist_id INTEGER NOT NULL,
    is_done INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (task_id) REFERENCES tasks(id),
    FOREIGN KEY (checklist_id) REFERENCES checklist(id),
    UNIQUE (task_id, checklist_id)
);
