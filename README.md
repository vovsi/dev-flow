<div align="center">

# 🧭 DevFlow

### A personal checklist for the routine around a Jira task

Paste a task link — the app walks you through every development step:
Story Points → branch → commit → PR → review → Jira → time tracking → sending the PR to a
reviewer. Each step also performs the work that goes with it: copies a command, generates the
branch name and the PR description with an LLM, transitions the Jira status, logs your time.

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-local%20file-003B57?logo=sqlite&logoColor=white)
![Vanilla JS](https://img.shields.io/badge/Frontend-vanilla%20JS-F7DF1E?logo=javascript&logoColor=black)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)
![Dependencies](https://img.shields.io/badge/dependencies-0-success)

<table>
<tr>
<td><img src="docs/screenshot-light.png" alt="DevFlow — task checklist, light theme" width="380"></td>
<td><img src="docs/screenshot-dark.png" alt="DevFlow — task checklist, dark theme" width="380"></td>
</tr>
</table>

<sub>The app is designed for a small <b>500×500</b> browser window — keep it next to your IDE.</sub>

</div>

---

## Contents

- [What it is and how it works](#what-it-is-and-how-it-works)
- [Checklist steps](#checklist-steps)
- [What you need to install](#what-you-need-to-install)
- [Quick start in 5 minutes](#quick-start-in-5-minutes)
- [Open it in a standalone 500×500 window](#open-it-in-a-standalone-500500-window)
- [Configuration: `config/params.ini`](#configuration-configparamsini)
  - [1. Jira — `[atlassian]`](#1-jira--atlassian)
  - [2. LLM — `[llm]`](#2-llm--llm)
    - [Option A: Claude over the API](#option-a-claude-over-the-api-easier)
    - [Option B: LM Studio (local, free)](#option-b-lm-studio-local-free)
  - [3. GitHub CLI — `[github]`](#3-github-cli--github)
  - [4. Git commands — `[git]`](#4-git-commands--git)
  - [5. Team-specific texts — `[templates]` and `[docs]`](#5-team-specific-texts--templates-and-docs)
  - [6. Work day — `[worktime]`](#6-work-day--worktime)
  - [7. Stuck PRs metric — `[dashboard]`](#7-stuck-prs-metric--dashboard)
  - [8. Earnings — `[salary]`, `[currency]`, `[services]`](#8-earnings--salary-currency-services)
  - [9. Claude Code skill mode — `[mode]`](#9-claude-code-skill-mode--mode)
- [Verify everything is up](#verify-everything-is-up)
- [What you can leave unconfigured](#what-you-can-leave-unconfigured)
- [Troubleshooting](#troubleshooting)
- [Project structure](#project-structure)
- [Updating and stopping](#updating-and-stopping)

---

## What it is and how it works

A single-page app with no frameworks: PHP 8.3 + a SQLite file, and one `app.js` on the frontend.
No Composer, no npm, no build step, no auth — this is a personal tool for one person, and the
database is just a file next to the code.

1. You paste a Jira task link (`https://your-domain.atlassian.net/browse/PROJ-123`).
2. The app checks that the task exists, pulls its title and description from Jira and creates
   (or finds) a checklist for it in its own database.
3. Then you work top to bottom: only the first unfinished item is unlocked. A completed item
   slides out of the list and the progress bar grows.
4. Reopening the same task does **not** reset your ticks — it only refreshes the data from Jira.
   To reset the checklist use the "Начать заново" ("Start over") button; to delete the task
   entirely use the trash icon in the recent tasks list on the link screen.

## Checklist steps

| Step | What the app does | Integration needed |
|---|---|---|
| Указать Story Points *(set Story Points)* | A modal with 1/2/3/5/8/13 → writes the field in Jira. The item is hidden if Story Points are already set on the task | Jira |
| Перевести в статус Doing *(transition to Doing)* | Transitions the Jira task to the status from your config | Jira |
| Создать ветку в Git *(create a git branch)* | Generates a branch name with the LLM from the task title, copies it to the clipboard, stores it in the DB | LLM (optional) |
| Закоммитить код *(commit the code)* | A "what did you do" field → the LLM builds the commit message subject line in Conventional Commits form with the Jira key | LLM |
| Создать PR *(create the PR)* | Hands you a ready `gh pr create --draft` command with your reviewers, then asks for the link to the PR you created | GitHub CLI |
| Проверить PR Claude Code *(review the PR with Claude Code)* | Copies a ready review prompt with the PR link and links to your internal documentation | — |
| Указать описание PR *(write the PR description)* | The LLM fills in your team's PR description template, plus an optional deploy instruction block | LLM |
| PR`s переведены в Ready for review | Just a tick | — |
| Оставить описание в Jira *(leave a description in Jira)* | Copies formatted text for a Jira comment | — |
| Перевести задачу в Pull Request | Transitions the Jira task to the status from your config | Jira |
| Затрекать время *(log time)* | A slider spanning your whole work day (lunch excluded) → adds a worklog to Jira. When you hit your daily norm, a congrats modal shows today's earnings and a motivational quote | Jira |
| PR отправлен ревьюверу *(the PR was sent to a reviewer)* | Just a tick | — |

Independently of the checklist you also get: a back arrow in the top left corner (returns to the
link screen so you can open another task), a "time logged today" indicator, quick time logging
with a single slider, a list of today's tasks, and a git commands dropdown next to the branch
name (`checkout -b`, `push`, `rebase` onto your base branches).

The link screen also shows a small dashboard above the input field. For now it holds a single
metric — "Зависшие PR > 24 ч" ("stuck PRs > 24 h"): how many of your Jira tasks have been sitting
in the Pull request status (the one from `pull_request_status` in your config) for longer than the
threshold from `[dashboard]`. **Days off don't count** — a PR moved on Friday at 17:00 only becomes
"stuck" on Monday at 17:00, not on Saturday (the weekdays to skip live in
`[worktime].non_working_days`). Click the tile to get the list of those tasks — each row links to
Jira; hover the tile to see the status, the threshold and which days are skipped. The round
button on the right refreshes the numbers; hover it to see how long ago they were fetched. Like
the "time logged today" indicator, the numbers also refresh on their own whenever you come back
to the window (at most once a minute), so a PR merged in another tab doesn't keep counting.

While a task is open the dashboard itself is off-screen, so a **red dot appears on the back arrow**
(top-left, "Другая задача") whenever any dashboard metric is above zero. It follows the background
refresh: the dot shows up (or goes away) as soon as the numbers change, without leaving the task.

## What you need to install

The minimum to get the app open:

- **either** Docker Desktop (recommended — nothing else to install),
- **or** PHP 8.3+ with the `pdo_sqlite` extension (`php -m | grep pdo_sqlite`).

For individual features, additionally:

| I want | I need |
|---|---|
| Task title from Jira, status transitions, Story Points, time tracking | An Atlassian account + API token → [`[atlassian]`](#1-jira--atlassian) |
| Generated branch name / commit message / PR description | A Claude API key **or** LM Studio on your machine → [`[llm]`](#2-llm--llm) |
| A working PR creation command | GitHub CLI (`gh`) → [`[github]`](#3-github-cli--github) |

None of it is mandatory: without Jira and an LLM the checklist still works — those steps simply
don't perform any external action (see [What you can leave unconfigured](#what-you-can-leave-unconfigured)).

## Quick start in 5 minutes

```bash
git clone <repository-url> dev-flow
cd dev-flow
cp config/params.ini.example config/params.ini
```

Open `config/params.ini` and **either fill in or delete** the sections containing placeholder
values (`your-domain.atlassian.net`, `sk-ant-...`, `your-github-nickname`) — with placeholders
left in place the app still starts, but Jira will answer with an authorization error. How to
fill each section is covered in [Configuration](#configuration-configparamsini). You can also
start from a completely empty file and add sections as you need them.

Run with Docker:

```bash
docker compose up -d --build
```

Or without Docker, with a local PHP (from the project root — this matters, the document root is
the root itself, not `public/`). **`router.php` is required**: the document root is the whole
project, and the router is what keeps everything except `public/` and `api/` off the network —
without it `config/params.ini` (your tokens), `storage/app.sqlite` and `.git` would be
downloadable over HTTP:

```bash
php -S localhost:8000 router.php
```

Then open: **http://localhost:8000/public/index.php** (or just http://localhost:8000 — it
redirects there).

The app has no authentication, so it is bound to `localhost` only. Docker publishes the port the
same way (`127.0.0.1:8000:8000` in `docker-compose.yml`) — do not change it to `0.0.0.0`, or
everyone on your Wi-Fi could open your tasks, log time to your Jira and spend your LLM tokens.
Also keep the config readable by you alone: `chmod 600 config/params.ini`.

The database file (`storage/app.sqlite`) is created on first open, and the schema and the
checklist items are applied automatically — there are no migrations to run by hand.

## Open it in a standalone 500×500 window

The app is designed for a small window that always sits next to your IDE. In Chrome:

1. Open http://localhost:8000/public/index.php
2. Menu ⋮ → **Cast, save and share** → **Install page as app** (in older versions:
   *More tools → Create shortcut* → check *Open as window*).
3. You get a separate window with no address bar — resize it to roughly 500×500 and park it on
   the side.

## Configuration: `config/params.ini`

The only settings file. It is not committed (see `.gitignore`) — it holds your tokens and
addresses. The template with every key and its comments is `config/params.ini.example`.

```bash
cp config/params.ini.example config/params.ini
```

The format is plain INI: sections in square brackets, values in double quotes.
**Every section is optional** — an unconfigured integration just disables its own features
instead of breaking the app. Editing the file needs no restart under Docker (the config is read
on every request), but if something doesn't get picked up, run `docker compose restart`.

---

### 1. Jira — `[atlassian]`

Gives you: the task title and description, the "does this task exist" check, Story Points,
status transitions, time logging, the "logged today" indicator and the list of today's tasks.

```ini
[atlassian]
base_url = "https://your-domain.atlassian.net"
email = "you@example.com"
api_token = "ATATT3xFfGF0..."
story_points_field = "customfield_10016"
doing_status = "Doing"
pull_request_status = "Pull request"
```

**Where to get `api_token`** (this is not your account password — a password will not work):

1. Go to **https://id.atlassian.com/manage-profile/security/api-tokens** signed in with your
   Atlassian account.
2. **Create API token** → give it any name (e.g. `devflow`) → **Create**.
3. Copy the token right away — it is shown only once. Paste it into `api_token`.

**`base_url`** — your Jira address with no path: open any task and the URL will look like
`https://SOMETHING.atlassian.net/browse/PROJ-123` → the config gets
`https://SOMETHING.atlassian.net`.

**`email`** — the email of the very Atlassian account the token was issued for (they only work
as a pair, Basic Auth).

**`story_points_field`** — the ID of the Story Points custom field, which differs between Jira
instances. The default is `customfield_10016` (correct for most Jira Cloud instances). To check
yours:

```bash
curl -s -u "you@example.com:YOUR_TOKEN" "https://your-domain.atlassian.net/rest/api/2/field" | grep -i "story point"
```

The response contains something like `"id":"customfield_10016","name":"Story Points"` — take the
`id`.

**`doing_status` / `pull_request_status`** — the transition names in your workflow; they must
match letter for letter (case doesn't matter, but spaces and wording do). To see which
transitions are actually available for a task:

```bash
curl -s -u "you@example.com:YOUR_TOKEN" \
  "https://your-domain.atlassian.net/rest/api/2/issue/PROJ-123/transitions" | python3 -m json.tool
```

Use the `transitions[].name` (or `.to.name`) values. Defaults are `Doing` and `Pull request`.

> Without the `[atlassian]` section the app still works: tasks open without a title from Jira,
> and the Jira-backed checklist items are simply ticked without any external action.

---

### 2. LLM — `[llm]`

Gives you: a generated git branch name, the commit message subject line, the PR description
filled into your team's template, the deploy instruction block, and the translation of the
motivational quote.

Two providers are supported, switched with a **single line** — `provider`. Settings for both
live in the section at the same time, so nothing needs to be deleted:

```ini
[llm]
provider = "anthropic"          ; "anthropic" or "lmstudio" (defaults to "lmstudio")

; --- anthropic provider ---
anthropic_api_key = "sk-ant-api03-..."
anthropic_model = "claude-haiku-4-5"

; --- lmstudio provider ---
lmstudio_host = "http://host.docker.internal:1234"
lmstudio_model = "qwen3.5-4b"
```

#### Option A: Claude over the API (easier)

1. Sign up at **https://platform.claude.com**.
2. **Settings → API keys → Create key**, copy the key (`sk-ant-api03-...`) — it is shown only
   once.
3. Top up your balance under **Billing** (with an empty balance the API answers
   `credit balance is too low`).
4. In the config: `provider = "anthropic"`, `anthropic_api_key = "<key>"`.

`anthropic_model` can be omitted — it defaults to `claude-haiku-4-5`, the cheapest and fastest
model. Everything generated here is short (a branch line, a commit line, a PR description), so
real-world usage costs cents per month. No SDK is required: the request goes out over plain
cURL.

#### Option B: LM Studio (local, free)

A fully local LLM on your own machine — no keys, no billing, and no task descriptions leaving
your computer. The app talks to it over `POST <host>/api/v1/chat` with a
`{model, system_prompt, input}` body — that is LM Studio's built-in REST API, so you need
**LM Studio 0.4 or newer** (verified on 0.4.21).

**Step 1. Install LM Studio** — https://lmstudio.ai (macOS / Windows / Linux, free).

**Step 2. Download a model.** The 🔍 **Discover** tab → search by name → **Download**.
The tasks here are simple (one commit line, a short description), so a small model is more than
enough:

| Model | On-disk size | Who it's for |
|---|---|---|
| **Qwen3.5 4B** (`qwen3.5-4b`, Q4_K_M / Q4_K_S quantization) | ~2.5 GB | **recommended**: fast, fits in 8 GB of RAM, handles Russian and the requested output format well |
| **Gemma 3 4B** (`google/gemma-3-4b`) | ~2.5 GB | an alternative if Qwen gets sloppy with the format |
| **DeepSeek Coder V2 Lite Instruct** (`deepseek-coder-v2-lite-instruct`, Q4) | ~9 GB | if you have 16+ GB of RAM and want more "engineering" wording |

Don't go above 7–8B: for two lines of text the quality gain is invisible, while you'll be
waiting seconds instead of getting an instant answer.

**Step 3. Model load parameters** (the load panel, also reachable via ⚙️ next to the model):

| Parameter | Value | Why |
|---|---|---|
| Context Length | `4096` (`8192` is fine) | the PR description is the longest output; 4k is enough, and more context costs more memory |
| GPU Offload | max (all layers) | on Apple Silicon and discrete GPUs this is several times faster |
| Flash Attention | on | less memory, faster |
| Temperature | leave the default (~0.7) | output format is driven by the app's system prompts |
| Keep model in memory | on | otherwise the first generation after a pause waits for the model to load from disk |

**Step 4. Start the server.** The **Developer** tab (the `>_` icon) → the **Status: Running**
toggle. The default port is `1234`. While you're there, enable:

- **Just-in-Time model loading** — the model then loads itself on the first request;
- **Serve on Local Network** — **required if the app runs in Docker**: otherwise the server only
  listens on the host's `localhost` and the container cannot reach it.

**Step 5. Point the config at it:**

- running in **Docker** → `lmstudio_host = "http://host.docker.internal:1234"`
  (`localhost` inside the container is the container itself, not your machine);
- running via `php -S` → `lmstudio_host = "http://localhost:1234"`;
- `lmstudio_model` — the **exact** model id as the server reports it (not the file name).
  To list them:

```bash
curl -s http://localhost:1234/v1/models
```

**Step 6. Check that the LLM answers** with the same request the app makes:

```bash
curl -s http://localhost:1234/api/v1/chat -H 'Content-Type: application/json' -d '{"model":"qwen3.5-4b","system_prompt":"Answer with one word","input":"Hello"}'
```

You should get JSON with `output[0].content`. A `404` means the Developer tab's server isn't in
the *Running* state, or LM Studio is older than 0.4. A `Connection refused` from the container
means *Serve on Local Network* is off.

> With no `[llm]` section configured the "Сгенерировать" ("Generate") buttons answer with an
> error while the rest of the app works as usual; in the congrats modal the quote stays in
> English (the LLM is only the translator there).

---

### 3. GitHub CLI — `[github]`

The "Создать PR" step puts a ready `gh pr create --draft ... --reviewer <nicknames>` command on
your clipboard. To have something to run it with, install and authenticate the GitHub CLI:

```bash
brew install gh
gh auth login
```

(Windows/Linux and other installation methods — https://cli.github.com)

```ini
[github]
reviewers = "octocat, hubot"
```

`reviewers` — comma-separated GitHub nicknames of your reviewers; exactly these are substituted
into `--reviewer`. Not set — the flag is simply left out of the command.

---

### 4. Git commands — `[git]`

The dropdown next to the branch name at the bottom of the screen: `Create Branch`, `Push` and
`Rebase …` entries for your repositories.

```ini
[git]
rebase_targets = "API3:main, Adminka:dev"
```

The format is `Label:base-branch`, comma-separated. The label is what you see in the menu, the
base branch goes into the `git rebase origin/<base>` command. Not set — the dropdown keeps only
`Create Branch` and `Push`.

---

### 5. Team-specific texts — `[templates]` and `[docs]`

The pieces of the copied texts that differ from team to team:

```ini
[templates]
review_skip_migration_repos = "api_v3, adminka"

[docs]
php_code_style = "https://your-domain.atlassian.net/wiki/spaces/DevTeam/pages/000000001"
testing_standards = "https://your-domain.atlassian.net/wiki/spaces/DevTeam/pages/000000002"
api_data_format = "https://your-domain.atlassian.net/wiki/spaces/DevTeam/pages/000000003"
```

- `review_skip_migration_repos` — repositories where migrations are not run: the review prompt
  asks Claude to skip them. Not set — that paragraph is left out of the prompt.
- `[docs]` — links to your internal documentation (Confluence and the like); the review prompt
  cites them next to the matching points. Every key is optional: a missing one simply leaves its
  link out of the prompt.

---

### 6. Work day — `[worktime]`

Configures the time-logging slider (its bounds, the lunch break and your daily hours norm) and
which weekdays are days off.

```ini
[worktime]
start = "09:00"
end = "18:00"
daily_hours = 8
lunch_start = "12:00"
lunch_end = "13:00"
non_working_days = "sat, sun"
```

Optional — the defaults are shown above. The slider position is the time **up to which the day
is worked**; lunch is highlighted in orange and never logged. `daily_hours` is also the
threshold that triggers the congrats modal.

`non_working_days` is used by the "stuck PRs" dashboard metric: hours that fall on those days
are not counted, so a PR moved on Friday evening stays fine over the weekend. Write it as
`"sat, sun"` (full names work too) or as ISO-8601 numbers `"6, 7"`, where 1 is Monday and 7 is
Sunday. Leave the key out and Saturday + Sunday are used; set it to an empty string and every
day counts. Listing all seven days is ignored — otherwise nothing would ever become stuck.

---

### 7. Stuck PRs metric — `[dashboard]`

How long a task may sit in the Pull request status before it lands in the dashboard counter on
the link screen.

```ini
[dashboard]
stale_pull_request_hours = 24
```

Optional — 24 by default. Only working hours count: the weekdays from
`[worktime].non_working_days` are skipped entirely. The number is also what the tile's label
shows ("Зависшие PR > 24 ч"), so changing it here changes the interface too.

---

### 8. Earnings — `[salary]`, `[currency]`, `[services]`

The first time log of the day that brings today's total up to `[worktime].daily_hours` opens a
congrats modal: how much you earned today, plus a motivational quote.

```ini
[salary]
monthly_usd = 1500
working_days_per_month = 21

[currency]
code = "UAH"
label = "грн"

[services]
exchange_rate_url = "https://open.er-api.com/v6/latest/USD"
quotes_url = "https://zenquotes.io/api/random"
```

All optional — the defaults are shown above. The hourly rate is
`monthly_usd / (working_days_per_month × daily_hours)`, and the amount is then converted into
`[currency].code` at the rate from `exchange_rate_url` (cached for 6 hours in
`storage/exchange_rate_cache.json`). The rate and quote services are public and **need no
keys**. If the rate can't be fetched, the earnings line is simply omitted from the modal.

---

### 9. Claude Code skill mode — `[mode]`

If a Claude Code skill already does the commit, the PR, the review and the PR description for
you, those checklist items are just noise. One switch hides them and adds a "Закоммитить
изменения" ("commit the changes") item with the `/commit` command instead:

```ini
[mode]
claude_code_skill_mode = "1"
```

`1` hides `code_written`, `pull_request`, `claude_review`, `pr_description` and shows
`skill_commit`. `0`, a missing key or a missing `params.ini` — the full checklist.
**Nothing is deleted from the database**: switch the mode off and the items come back with their
ticks exactly as they were.

---

## Verify everything is up

| What to check | How | Expected |
|---|---|---|
| The app | Open http://localhost:8000/public/index.php | The screen with the "task link" field |
| The database | `ls -la storage/app.sqlite` | The file appeared after the first open |
| Secrets are not exposed | `curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/config/params.ini` | `404` — the router serves nothing but `public/` and `api/` |
| Jira | Paste a link to a real task | The task key shows up on top, the checklist renders; the "time logged today" indicator appears in the top left |
| Jira (token) | `curl -s -u "email:token" "https://your-domain.atlassian.net/rest/api/2/myself"` | JSON with your account, not a `401` |
| The dashboard | Look above the "task link" field | The blue "Зависшие PR > 24 ч" tile with a number; clicking it opens the task list |
| The dashboard alert dot | Open a task while a metric is above zero | A small red dot on the back arrow in the top-left corner |
| The LLM | Reach "Создать ветку в Git" → "Сгенерировать" | A branch name lands in the field |
| GitHub CLI | `gh auth status` | `Logged in to github.com` |

PHP logs when running under Docker: `docker compose logs -f app`.

## What you can leave unconfigured

| Not configured | What stops working | What keeps working |
|---|---|---|
| `[atlassian]` | Task title from Jira, Story Points, status transitions, time logging, the time indicator, the dashboard on the link screen | The whole checklist as a manual tracker, branches, copying texts |
| `[llm]` | The "Сгенерировать" ("Generate") buttons (branch, commit message, PR description); the quote stays in English | Everything else; you can type the texts by hand |
| `[github]` | Reviewers in the `gh pr create` command | The command itself is still copied |
| `[git]`, `[templates]`, `[docs]` | The `Rebase …` entries, project names and documentation links inside the copied texts | The texts are copied without those pieces |
| `[worktime]`, `[dashboard]`, `[salary]`, `[currency]`, `[services]` | Nothing — the defaults kick in | Everything |
| No `params.ini` at all | Every integration | The checklist, the branch, copying texts, the progress bar |

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Blank page / 404 on open | The server wasn't started from the project root, or `router.php` was left out. You need `php -S localhost:8000 router.php` **from the root** and the `/public/index.php` path — the frontend calls the API over the relative `../api/` path |
| `404` on any path outside `public/` and `api/` | That is `router.php` doing its job — only those two directories are served over HTTP. Add a new public directory to `ALLOWED_PREFIXES` in `router.php` instead of dropping the router |
| `403 Запрос отклонён: недопустимый источник` (request rejected) | An API call arrived from another origin. The endpoints only accept requests from the app's own page (CSRF protection in `api/_bootstrap.php`) — open the app itself instead of calling the API from another site or tool |
| A PHP error shows as an empty response instead of a stack trace | Intended: error output is off (the trace can contain your tokens). Read it in the server log — `docker compose logs -f app` |
| `could not find driver` | The `pdo_sqlite` extension is missing. Check `php -m \| grep pdo_sqlite`; under Docker it is already built in |
| `unable to open database file` | No write permission on `storage/` → `chmod -R 775 storage` |
| "Не удалось распознать ссылку на задачу" (link not recognized) | The link contains no `PROJ-123`-style key. `.../browse/PROJ-123`, `?selectedIssue=PROJ-123` or plain `PROJ-123` all work |
| "Задача не найдена в Jira" (task not found in Jira) | A typo in the key, or your account has no access to the project. Jira being unreachable does not block task creation |
| Jira answers `401` | Wrong `email` + `api_token` pair, or an account password was pasted into `api_token` instead of a token |
| Story Points are not set | Wrong `story_points_field` — find yours via `/rest/api/2/field` (see above) |
| A status transition fails | The name in `doing_status` / `pull_request_status` doesn't match a transition in the workflow — check `/rest/api/2/issue/PROJ-123/transitions` |
| The "Сгенерировать" button errors out | The provider in `[llm]` isn't configured, or LM Studio isn't running / `lmstudio_model` is wrong |
| LLM: `Connection refused` from Docker | `lmstudio_host` says `localhost` instead of `host.docker.internal`, or *Serve on Local Network* is off in LM Studio |
| LLM: `404` from LM Studio | The Developer tab's server isn't *Running*, or LM Studio is older than 0.4 (no `/api/v1/chat`) |
| Claude: `credit balance is too low` | Empty balance at platform.claude.com → Billing |
| Code changes don't show up | Aggressive browser caching. Static assets are versioned by mtime automatically; under Docker run `docker compose restart` after editing |
| The dashboard above the input field is missing | `[atlassian]` isn't configured or Jira is unreachable — there is nothing to count, so the block hides itself. Check the `myself` call above |
| The stuck PRs counter stays at `0` with an old PR open | Days off are skipped, so the age is measured in working hours only: check `[worktime].non_working_days` and `[dashboard].stale_pull_request_hours`. Also make sure `pull_request_status` matches the status name in Jira exactly |
| Time you just logged isn't in the list | The Jira Cloud search index updates with a delay — the app re-fetches the open task directly, the rest show up within a few seconds |

## Project structure

```
router.php                 — built-in PHP server router: serves only public/ and api/,
                             keeps params.ini, storage/ and .git off the network
config/params.ini          — the only settings file (not in git, see params.ini.example)
database/schema.sql        — SQLite schema (tasks, checklist, task_checklist)
storage/                   — created automatically: app.sqlite + the exchange rate cache (not in git)
src/                       — App\* classes: Database, Config, repositories, services,
                             clients for Jira / LLMs / exchange rates / quotes
api/                       — JSON endpoints (POST, JSON-in/JSON-out), a thin layer over the services
public/index.php           — the single HTML page
public/assets/css/style.css — styles (light/dark theme, macOS-flavoured glass)
public/assets/js/app.js    — all the client logic (one file, no build step)
docs/                      — screenshots for this README
Dockerfile, docker-compose.yml — containerized run (PHP 8.3 + pdo_sqlite)
```

The full description of the architecture, the business rules, every endpoint and the behaviour
of every checklist item lives in [`CLAUDE.md`](CLAUDE.md).

## Updating and stopping

```bash
git pull                       # update the code
docker compose restart         # pick up the changes (the project is mounted as a volume)
docker compose up -d --build   # only needed when the Dockerfile changed
docker compose down            # stop
```

The database schema and the checklist items are brought up to date automatically on the first
request after an update — nothing to run by hand. `storage/app.sqlite` is never touched by
updates: your tasks and ticks are kept.
