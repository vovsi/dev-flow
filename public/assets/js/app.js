// DevFlow — вся клиентская логика одностраничного приложения
(() => {
    'use strict';

    // ==================== Тексты для копирования ====================

    /** Репозитории без миграций — исключение в промпте Claude-ревью, тоже из конфига
     * ([templates].review_skip_migration_repos). Список пуст — блок исключения не добавляется */
    const REVIEW_SKIP_MIGRATION_REPOS =
        (window.DEVFLOW_CONFIG && window.DEVFLOW_CONFIG.reviewSkipMigrationRepos) || [];

    /** Ссылки на внутреннюю документацию команды для промпта Claude-ревью — из конфига
     * ([docs] в config/params.ini). Ключ не задан — ссылка в промпт не подставляется */
    const REVIEW_DOC_LINKS = (window.DEVFLOW_CONFIG && window.DEVFLOW_CONFIG.reviewDocLinks) || {};

    /** Хвост « См.: <url>» для пункта промпта ревью, если ссылка на документацию задана в конфиге */
    function reviewDocRef(key) {
        const url = REVIEW_DOC_LINKS[key];
        return url ? ` См.: ${url}` : '';
    }

    /** Команда скилла Claude Code, который делает коммит за разработчика (пункт `skill_commit`,
     * виден только при включённом у задачи флаге claude_code_skill_mode — настройки → «Эта задача») */
    const SKILL_COMMIT_COMMAND = '/commit';

    const JIRA_DESCRIPTION_HTML =
        '<b> Results</b><br/>1. <br/>' +
        '<b> Testing</b><br/>1. <br/>' +
        '<b> Database</b><br/>1. <br/>' +
        '<b> Config</b><br/>1. <br/>' +
        '<b> Pull Requests</b><br/>1. <br/>';

    const JIRA_DESCRIPTION_PLAIN = 'Results\n1. \n\nTesting\n1. \n\nDatabase\n1. \n\nConfig\n1. \n\nPull Requests\n1. ';

    /** Блок «Важное исключение» промпта ревью — только если в конфиге перечислены репозитории
     * без миграций (REVIEW_SKIP_MIGRATION_REPOS); пустой список — блока в промпте нет */
    function buildReviewMigrationsExceptionBlock() {
        if (REVIEW_SKIP_MIGRATION_REPOS.length === 0) {
            return '';
        }

        const repos = REVIEW_SKIP_MIGRATION_REPOS.map((repo) => `\`${repo}\``).join(', ');

        return '### Важное исключение:\n\n' +
            `Для репозиториев ${repos} миграции для MySQL не выполняются — не проверяй ` +
            'корректность и не оценивай качество написанных миграций в этих репозиториях.\n\n' +
            '---\n\n';
    }

    function buildClaudeReviewText(prLink) {
        return 'Ты — Senior Fullstack Code Reviewer с глубокой экспертизой в PHP, MySQL, JavaScript, HTML и CSS. \n\n' +
        'Проведи тщательное Code Review предоставленного Pull Request (PR) / diff. \n\n' +
        '### Оценивай код по следующим критериям:\n\n' +
        '1. **Безопасность (Security - Критический приоритет):**\n' +
        '   - **PHP / MySQL:** Уязвимости к SQL-инъекциям (отсутствие Prepared Statements/PDO), XSS (неэкранированный вывод), CSRF, невалидированные пользовательские данные, небезопасное хранение паролей или токенов.\n' +
        '   - **JS / HTML:** Dom-based XSS, некорректная обработка `innerHTML` / `dangerouslySetInnerHTML`, утечки токенов или чувствительных данных в клиентский код / `localStorage`.\n' +
        '   - **Данные и доступы:** сырой SQL с пользовательским вводом (только биндинги / query builder); чувствительные данные ' +
        '(пароли, токены, ключи) в логах, ответах и комментариях; отсутствие проверок аутентификации и авторизации у новых эндпоинтов; ' +
        'невалидированная загрузка файлов (тип, размер, расширение); `eval()`, `exec()`, `shell_exec()`, небезопасная десериализация ' +
        '(`unserialize()` пользовательских данных).\n\n' +
        '2. **Производительность и работа с БД:**\n' +
        '   - **MySQL / PHP:** Проблема N+1 запросов в циклах, отсутствующие или неоптимальные индексы, загрузка избыточных данных (`SELECT *`), неэффективная фильтрация на стороне PHP вместо БД, отсутствие транзакций там, где обновляются несколько связанных таблиц.\n' +
        '   - **JS / CSS / HTML:** Избыточная перерисовка (layout thrashing), тяжелые операции на UI-потоке, утечки памяти (неудаленные event listener\'ы / таймеры), загрузка неоптимизированных ресурсов.\n' +
        '   - **Объёмы и фон:** большие выборки отдаются постранично, а не загружаются целиком; долгие и блокирующие операции ' +
        '(письма, обращения к внешним API, тяжёлые отчёты) вынесены в очередь, а не выполняются в потоке HTTP-запроса.\n\n' +
        '3. **Архитектура и чистый код (Clean Code):**\n' +
        '   - **PHP:** Соблюдение SOLID, DRY, KISS, YAGNI (детальный разбор — ниже). Нарушение разделения ответственности (например, SQL-запросы в контроллерах или шаблонах).\n' +
        '   - **JS:** Ограничение глобальной области видимости, асинхронная обработка (promises / async-await вместо callback hell), чистые функции.\n' +
        '   - **HTML / CSS:** Семантическая верстка, правильная иерархия тегов, доступность (a11y), понятный и масштабируемый CSS (БЭМ, отсутствие жесткого завязывания на ID или избыточной вложенности селекторов).\n\n' +
        '   **SOLID — что именно проверять:**\n\n' +
        '   - **S — Single Responsibility Principle (SRP):** у класса должна быть одна и только одна причина для изменения. ' +
        'Класс, метод и модуль делают что-то одно; если для описания нужно слово «и» — это нужно разделить.\n' +
        '     ❌ Плохо: `UserService::register()`, который валидирует вход, создаёт пользователя в БД, шлёт welcome-письмо и пишет лог.\n' +
        '     ✅ Хорошо: `UserRegistrar` с инъекцией `UserRepository`, `Mailer`, `EventLogger` — каждый отвечает за своё, сервис только оркестрирует.\n' +
        '     🚩 Красный флаг: в названии класса есть «And», класс делает 5 разных вещей.\n\n' +
        '   - **O — Open/Closed Principle (OCP):** код открыт для расширения и закрыт для модификации. ' +
        'Новое поведение добавляется новым кодом, а не правкой существующего.\n' +
        '     ❌ Плохо: `PaymentProcessor::process(string $provider, ...)` с цепочкой `if ($provider === \'stripe\') ... if ($provider === \'paypal\') ...` — каждый новый провайдер требует править этот файл.\n' +
        '     ✅ Хорошо: интерфейс `PaymentGateway` с методом `charge()`, реализации `StripeGateway` / `PaypalGateway`, а `PaymentProcessor::process(PaymentGateway $gateway, float $amount)` просто вызывает `$gateway->charge($amount)` — новый провайдер = новый класс, существующий код не трогается.\n' +
        '     🚩 Красный флаг: разрастающиеся цепочки `if ($type === \'x\')` / `switch` по типу.\n\n' +
        '   - **L — Liskov Substitution Principle (LSP):** наследник должен подставляться вместо базового класса без нарушения корректности. ' +
        'Если `class B extends A`, то везде, где используется `A`, должен работать и `B`.\n' +
        '     ❌ Плохо: `Square extends Rectangle`, где `setWidth()` втихую меняет и высоту — вызывающий код такого не ожидает, контракт базового класса сломан.\n' +
        '     ✅ Хорошо: `Square` и `Rectangle` — отдельные реализации интерфейса `Shape`.\n' +
        '     🚩 Красный флаг: переопределённый метод меняет ожидаемое поведение, бросает исключение вместо работы или требует более строгих входных данных, чем родитель.\n\n' +
        '   - **I — Interface Segregation Principle (ISP):** клиент не должен зависеть от методов интерфейса, которые он не использует. ' +
        'Несколько узких интерфейсов лучше одного «толстого».\n' +
        '     ❌ Плохо: `interface Worker { work(); eat(); sleep(); }` — реализациям приходится реализовывать ненужное.\n' +
        '     ✅ Хорошо: отдельные `Workable`, `Feedable`, `Restable`.\n' +
        '     🚩 Красный флаг: интерфейс с методами, которые часть реализаций оставляет пустыми или бросает `NotImplemented`.\n\n' +
        '   - **D — Dependency Inversion Principle (DIP):** зависеть нужно от абстракций, а не от конкретных классов. ' +
        'Высокоуровневые модули не импортируют низкоуровневые напрямую — оба зависят от интерфейсов.\n' +
        '     ❌ Плохо: `class OrderService { private MySQLOrderRepository $repo; public function __construct() { $this->repo = new MySQLOrderRepository(); } }` — зависимость захардкожена внутри.\n' +
        '     ✅ Хорошо: `class OrderService { public function __construct(private OrderRepositoryInterface $repo) {} }`.\n' +
        '     🚩 Красный флаг: `new ConcreteClass()` внутри сервиса, статические вызовы конкретных классов вместо инъекции.\n\n' +
        '   **DRY — Don\'t Repeat Yourself:** каждый кусок знания должен иметь единственное однозначное представление в системе. ' +
        'Признаки нарушения: скопированные блоки с мелкими правками, одинаковая валидация в трёх контроллерах, повторяющиеся фрагменты SQL. ' +
        'Лечится выносом в общий метод, сервис, трейт или базовый класс. ' +
        '⚠️ DRY относится к логике, а не к внешнему сходству: два похожих по виду класса, решающих разные задачи, объединять не нужно. ' +
        '🚩 Красный флаг: копипаста с мелкими изменениями.\n\n' +
        '   **KISS — Keep It Simple, Stupid:** самое простое работающее решение и есть правильное. ' +
        'Не переусложняй, не добавляй слои абстракции раньше, чем они понадобились. ' +
        'Чек-лист: сможет ли junior прочитать и понять это за 5 минут? Окупает ли себя каждая абстракция? ' +
        'Решается реальная проблема или гипотетическая будущая? ' +
        '❌ Плохо: 8 классов и 3 интерфейса (`EmailPipelineBuilder`, middleware, стратегии, фабрики payload) ради отправки одного письма. ' +
        '✅ Хорошо: `Mail::to($user)->send(new WelcomeMail());` ' +
        '🚩 Красный флаг: лишние слои, преждевременная абстракция.\n\n' +
        '   **YAGNI — You Aren\'t Gonna Need It:** не реализуй то, что реально не нужно прямо сейчас. ' +
        'Никаких фич, конфиг-флагов и абстракций «на всякий случай» — решай сегодняшнюю задачу. ' +
        '🚩 Красные флаги: «нам это может понадобиться позже…», абстрактная фабрика ради единственной реализации, ' +
        'конфиг-флаги, которые никогда не переключаются, параметры и классы «про запас».\n\n' +
        '   **PHP Code Style — PSR-12 (обязателен для всего PHP-кода).** Официальная спецификация: https://www.php-fig.org/psr/psr-12/' +
        reviewDocRef('php_code_style') + '\n' +
        '   Дополнительно: никаких магических чисел и строк (только именованные константы), никакого закомментированного кода, ' +
        'идентификаторы, комментарии и docblock-и — только на английском.\n\n' +
        '   - **Файлы:** UTF-8 без BOM; в PHP-only файлах закрывающий `?>` не ставится; первая строка после `<?php` — ' +
        '`declare(strict_types=1);`; затем пустая строка, `namespace`, блок `use`.\n' +
        '   - **Отступы и длина строки:** 4 пробела, табы запрещены; мягкий лимит 120 символов — более длинные строки следует разбивать; ' +
        'продолжение строки — с отступом на один уровень (4 пробела).\n' +
        '   - **Ключевые слова и типы:** ключевые слова PHP в нижнем регистре (`true`, `false`, `null`, `class`, `extends`); ' +
        'типы — только короткие формы (`int`, `bool`, `string`, `float`, `array`, `object`).\n' +
        '   - **`namespace` / `use`:** один `use` на класс/функцию/константу (без группировки `{}`, если проект не делает так повсеместно); ' +
        'неиспользуемых `use` быть не должно; внутри группы — сортировка по алфавиту.\n' +
        '   - **Классы:** открывающая `{` на отдельной строке после объявления, закрывающая `}` на отдельной строке; ' +
        '`extends`/`implements` на одной строке с именем класса, а если не влезает в 120 символов — каждый интерфейс на своей строке. ' +
        'Свойства: у всех объявлена видимость, объявлен тип (PHP 7.4+), одно свойство на объявление. ' +
        'Константы класса: с объявленной видимостью, в `UPPER_SNAKE_CASE`.\n' +
        '   - **Методы и функции:** открывающая `{` на отдельной строке; у всех параметров объявлены типы; объявлен тип возвращаемого значения; ' +
        'объявлена видимость; `abstract`/`final` — до видимости, `static` — после. Если список параметров не влезает в 120 символов — ' +
        'каждый параметр на своей строке с отступом и запятой после последнего.\n' +
        '   - **Управляющие конструкции:** один пробел после ключевого слова (`if`, `for`, `foreach`, `while`, `switch`, `match`); ' +
        'открывающая `{` на той же строке, закрывающая — на своей; `elseif`, а не `else if`; фигурные скобки всегда, даже для однострочного тела.\n' +
        '   - **Замыкания и стрелочные функции:** пробел до и после `function` в замыкании; для одного выражения предпочтительна короткая `fn`.\n' +
        '   - **Docblock-и и комментарии:** docblock (`/** */`) — для публичного API, сложной логики и неочевидных параметров; ' +
        'однострочные `//` — для краткого пояснения по месту; `@param`/`@return` не нужны, если тип полностью выражен в сигнатуре, ' +
        'но нужны для сложных типов (`@return Collection<int, User>`). **Закомментированный код в PR недопустим — удалять, история есть в Git.**\n' +
        '   - **Именование:** класс — `PascalCase` (`OrderService`); интерфейс — `PascalCase` + суффикс `Interface` (`OrderRepositoryInterface`); ' +
        'трейт — `PascalCase` (`HasTimestamps`); enum — `PascalCase` (`OrderStatus`); метод/функция — `camelCase` (`findActiveUsers()`); ' +
        'переменная и свойство — `camelCase` (`$userId`, `$retryCount`); константа — `UPPER_SNAKE_CASE` (`MAX_RETRY_COUNT`); ' +
        'колонка БД и поле API — `snake_case` (`created_at`, `user_id`).\n' +
        '   - **Возможности PHP 8+ — использовать:** именованные аргументы при вызове функций с большим числом параметров; ' +
        'nullsafe-оператор `?->` вместо проверок на null перед вызовом; constructor promotion для простых value-object и DTO; ' +
        '`match` вместо сложного `switch`; enum вместо констант класса для конечных наборов значений; ' +
        'Fibers — только по явной договорённости команды.\n' +
        '   - **Запрещено всегда:** ❌ `var` для свойств (синтаксис PHP 4, без видимости); ❌ короткие открывающие теги `<?`; ' +
        '❌ закрывающий `?>` в PHP-only файлах; ❌ оператор подавления ошибок `@` (молча прячет баги); ❌ `eval()` (дыра в безопасности); ' +
        '❌ `extract()` (непредсказуемо засоряет область видимости); ❌ `global` (скрытая связанность); ' +
        '❌ магические числа и строки — только именованные константы; ❌ закомментированный код.\n\n' +
        '   **Laravel-конвенции (для репозиториев на Laravel).** Дополняют PSR-12: сначала PSR-12, затем эти правила; ' +
        'при конфликте приоритет у Laravel-конвенций.\n\n' +
        '   - **Именование:** Model — единственное число `PascalCase` (`User`, `Order`, `PhoneNumber`); ' +
        'Controller — единственное число + `Controller` (`UserController`); методы ресурсного контроллера — 7 стандартных ' +
        '(`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`); миграция — описательный `snake_case` ' +
        '(`create_users_table`, `add_status_to_orders_table`); таблица БД — множественное число `snake_case` (`users`, `order_items`); ' +
        'колонка — `snake_case` (`first_name`, `is_active`); имя роута — `dot.notation` в ресурсном стиле (`users.index`, `orders.store`); ' +
        'Form Request — глагол + ресурс + `Request` (`CreateUserRequest`); Job — глагольная фраза (`SendWelcomeEmail`); ' +
        'Event — прошедшее время (`UserRegistered`, `OrderShipped`); Listener — настоящее время, описывает действие (`SendWelcomeNotification`); ' +
        'Policy — модель + `Policy` (`UserPolicy`).\n' +
        '   - **Модели:** только Eloquent-связи, без сырых запросов в модели; явный `$fillable` вместо `$guarded = []`; ' +
        'типы явно описаны в `$casts`; **никакой бизнес-логики в моделях** — модель это только доступ к данным.\n' +
        '   - **Контроллеры:** тонкие — оркестрируют, но не реализуют бизнес-логику; один публичный метод-действие на роут ' +
        '(для сложных эндпоинтов — single-action контроллер); валидация через Form Request, а не `$request->validate()` в теле метода ' +
        'для нетривиальных правил; ответы — `response()->json()` / API Resource либо `view()`, с указанным типом возврата.\n' +
        '   - **Сервисный слой:** бизнес-логика в классах `app/Services/`; сервисы внедряются через конструктор, а не создаются ' +
        'через `new` в контроллере; сервис может зависеть от репозиториев и других сервисов, но **никогда от `Request`**.\n' +
        '   - **Репозитории:** запросы к БД живут в `app/Repositories/`, каждый репозиторий реализует интерфейс ' +
        '(`OrderRepositoryInterface` → `EloquentOrderRepository`); в новом коде вызовов Eloquent вне репозиториев и миграций быть не должно. ' +
        '⚠️ Паттерн обязателен для нового кода — существующий код под него не переписываем, если это не прямая цель задачи.\n' +
        '   - **Form Requests:** вся нетривиальная валидация — в отдельных классах Form Request; авторизация в `authorize()`, правила в `rules()`.\n' +
        '   - **Роуты:** API — в `routes/api.php`, web — в `routes/web.php`; связанные роуты группируются через `Route::prefix()` и ' +
        '`Route::middleware()`; именованные роуты — всегда; где применимо — `Route::resource()` / `Route::apiResource()`.\n' +
        '   - **Миграции:** любое изменение схемы — только миграцией, руками таблицы не меняются; миграция обратима — `down()` реализован; ' +
        '**уже выкаченную в прод миграцию не правят** — добавляют новую.\n' +
        '   - **Artisan-команды:** в `app/Console/Commands/`, сигнатура `namespace:verb-noun` (`orders:prune-old`, `users:sync-status`); ' +
        'команда тонкая, логика — в сервисах.\n\n' +
        '4. **Корректность, обработка ошибок и крайние случаи (Edge Cases):**\n' +
        '   - Делает ли код то, что описано в задаче Jira и критериях приёмки (детальнее — пункт 6 ниже).\n' +
        '   - Обработка `null` / `undefined` / пустых строк, пустых массивов, `0`, отрицательных и максимальных значений.\n' +
        '   - Перехват исключений (try-catch) на бэкенде и корректные HTTP-статусы ответа: исключение либо обработано, ' +
        'либо осознанно проброшено выше — не проглатывается молча.\n' +
        '   - Поведение при отказе внешних сервисов (сторонние API, очереди, БД): таймауты, повторы, понятная ошибка вместо падения.\n' +
        '   - Гонки и конкурентный доступ: параллельные запросы к одним и тем же данным, отсутствие транзакций/блокировок там, где они нужны.\n' +
        '   - Корректность валидации данных как на клиенте, так и ОБЯЗАТЕЛЬНО на сервере.\n\n' +
        '5. **Тесты, тестируемость и поддерживаемость:**\n' +
        '   - Для нового поведения есть тесты.' + reviewDocRef('testing_standards') + '\n' +
        '   - Тесты осмысленные — проверяют реальное поведение, а не сам факт вызова метода.\n' +
        '   - У исправления бага есть регрессионный тест, который падал бы без фикса.\n' +
        '   - Насколько просто написать Unit/Integration тесты для этого кода.\n' +
        '   - Наличие хардкода (константы, конфиги, секреты прямо в коде).\n\n' +
        '6. **Соответствие ТЗ (задаче в Jira):**\n' +
        '   - Найди ссылку на задачу Jira в описании PR и открой задачу.\n' +
        '   - Сравни, что требовалось по описанию задачи, с тем, что фактически сделано в PR.\n' +
        '   - Явно укажи, выполнена ли задача полностью, частично или не соответствует ТЗ, и что именно расходится.\n' +
        '   - Если в описании задачи указано, что фича должна работать только для тестовых пользователей ' +
        '(например, ограничение по флагу, списку ID, окружению и т.п.), а в коде такого ограничения нет или ' +
        'оно включает фичу для всех пользователей — обязательно укажи это как отдельное несоответствие ТЗ.\n\n' +
        '7. **Замечания ревьюверов из комментариев PR:**\n' +
        '   - Прочитай комментарии к PR и учти замечания, оставленные ревьюверами.\n' +
        '   - Проверь, исправлены ли эти замечания в текущем коде/diff, и укажи, какие остались неисправленными.\n\n' +
        '8. **Изменения API:**\n' +
        '   - Структура ответа соответствует принятому в команде формату данных API.' + reviewDocRef('api_data_format') + '\n' +
        '   - Ломающие изменения (breaking changes) явно описаны в описании PR.\n' +
        '   - У новых эндпоинтов есть авторизация.\n\n' +
        '9. **Миграции и БД:**\n' +
        '   - Миграция обратима — метод `down()` реализован.\n' +
        '   - Существующие продовые данные не ломаются миграцией.\n' +
        '   - Для колонок, участвующих в WHERE / ORDER BY / JOIN, добавлены индексы.\n\n' +
        '---\n\n' +
        buildReviewMigrationsExceptionBlock() +
        '### Как формулировать замечания:\n\n' +
        '- Будь конкретен: процитируй строку, объясни, в чём проблема, и предложи исправление. ' +
        '✅ «Здесь будет N+1 при загрузке списка заказов — добавь `->with(\'items\')` в `OrderRepository::findAll()`». ' +
        '❌ «Это неправильно».\n' +
        '- Где уместно — сошлись на конкретный раздел документации или стандарта.\n' +
        '- Помечай каждое замечание меткой: `[blocking]` — обязательно исправить до мерджа, ' +
        '`[suggestion]` — необязательное улучшение, `[nit]` — вкусовщина по стилю, на усмотрение автора.\n' +
        '- Комментируй не всё подряд, а то, что действительно важно; категории, не относящиеся к этому PR, пропускай.\n' +
        '- Решают принятые стандарты, а не личные предпочтения: не превращай ревью в спор о вкусах.\n\n' +
        '---\n\n' +
        '### Формат ответа:\n\n' +
        '1. **Краткое резюме:** Общее впечатление от PR (1–3 предложения).\n' +
        '2. **Critical / Blocker (Критические проблемы, метка `[blocking]`):** Ошибки безопасности, баги, приводящие к падению, утечки памяти, SQL-инъекции. Требуют обязательного исправления.\n' +
        '3. **Major (Важные замечания, метка `[blocking]` или `[suggestion]`):** Архитектурные огрехи, проблемы с производительностью (N+1), отсутствие валидации, отсутствие тестов, необратимые миграции.\n' +
        '4. **Minor / Nits (Мелкие улучшения и стиль, метка `[suggestion]` или `[nit]`):** Рефакторинг, читаемость, форматирование, верстка.\n' +
        '5. **Соответствие ТЗ:** Вывод по пункту 6 выше — выполнена задача или нет, и почему.\n' +
        '6. **Замечания ревьюверов:** Вывод по пункту 7 выше — какие замечания учтены, какие остались открытыми.\n' +
        '7. **Примеры исправлений:** Для ключевых проблем укажи конкретный фрагмент кода "Было → Стало".\n' +
        '8. **Вердикт:** Можно ли апрувить PR. Апрув — когда все `[blocking]`-замечания устранены, код делает то, ' +
        'что должен, и не вносит очевидных регрессий; `[suggestion]` и `[nit]` апрув не блокируют.\n\n' +
        '---\n\n' +
        '**Вот код для ревью:**\n' +
        (prLink || '[ссылка на PR не найдена — вставьте вручную]');
    }

    /** Команда `gh pr create` для пункта «Создать PR» — ревьюверы подставляются из config/params.ini (см. Config::githubReviewers) */
    function buildGhPrCreateCommand() {
        const reviewers = (window.DEVFLOW_CONFIG && window.DEVFLOW_CONFIG.githubReviewers) || [];
        let command = 'gh pr create --draft --title "$(git log -1 --format=%s)" --body "$(git log -1 --format=%b)" --assignee "@me"';
        if (reviewers.length > 0) {
            command += ` --reviewer "${reviewers.join(',')}"`;
        }
        return command;
    }

    // ==================== DOM-элементы ====================

    const linkScreen = document.getElementById('link-screen');
    const taskScreen = document.getElementById('task-screen');
    const taskLinkInput = document.getElementById('task-link-input');
    const openTaskBtn = document.getElementById('open-task-btn');
    const linkError = document.getElementById('link-error');
    const recentTasksEl = document.getElementById('recent-tasks');
    const recentTasksListEl = document.getElementById('recent-tasks-list');
    const dashboardEl = document.getElementById('dashboard');
    const dashboardRefreshBtn = document.getElementById('dashboard-refresh');
    const taskIdLabel = document.getElementById('task-id-label');
    const changeTaskBtn = document.getElementById('change-task-btn');
    const checklistEl = document.getElementById('checklist');
    const progressFill = document.getElementById('progress-fill');
    const progressLabel = document.getElementById('progress-label');
    const finishTaskBtn = document.getElementById('finish-task-btn');
    const gitBranchValue = document.getElementById('git-branch-value');
    const gitActionsBtn = document.getElementById('git-actions-btn');
    const gitActionsPopover = document.getElementById('git-actions-popover');
    const todayTimeEl = document.getElementById('today-time');
    const todayTimeValueEl = document.getElementById('today-time-value');
    const trackTimeBtn = document.getElementById('track-time-btn');
    const todayTasksBtn = document.getElementById('today-tasks-btn');
    const settingsBtn = document.getElementById('settings-btn');
    const themePopover = document.getElementById('theme-popover');
    const taskSettingsSection = document.getElementById('task-settings-section');
    const taskSkillModeToggle = document.getElementById('task-skill-mode-toggle');
    const claudeSettingsSection = document.getElementById('claude-settings-section');
    const claudeNotificationsToggle = document.getElementById('claude-notifications-toggle');
    const toastEl = document.getElementById('toast');
    const tooltipEl = document.getElementById('tooltip');
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitleEl = document.getElementById('modal-title');
    const modalBodyEl = document.getElementById('modal-body');
    const modalActionsEl = document.getElementById('modal-actions');
    const globalLoaderEl = document.getElementById('global-loader');

    const CHECK_SVG =
        '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>';

    const COPY_SVG =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
        'stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2.2"/>' +
        '<path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>';

    /** Разметка единого спиннера приложения (см. .ios-spinner в style.css) — 8 «спиц»,
     * затухающих по кругу, воспроизводит системный активити-индикатор iOS/macOS */
    function spinnerHtml(size) {
        const sizeClass = size === 'lg' ? ' ios-spinner--lg' : '';
        return `<span class="ios-spinner${sizeClass}" aria-hidden="true">${'<i></i>'.repeat(8)}</span>`;
    }

    /**
     * Разметка скелетона (см. .skeleton в style.css) — плашка на месте значения, которое ещё
     * грузится. Ставится туда, где иначе была бы пустота до ответа сервера; спиннер там не
     * годится (по HIG он рядом с контролом, а не на месте результата). Размер задаётся в CSS
     * по месту применения, здесь параметров нет намеренно.
     */
    function skeletonHtml() {
        return '<span class="skeleton" aria-hidden="true"></span>';
    }

    /** Значок окна — намекает, что по клику на пункт откроется модалка, а не сразу отметка */
    const MODAL_HINT_SVG =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
        'stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2.5"/>' +
        '<path d="M3 9h18"/></svg>';

    /** Пункты, клик по которым открывает модальное окно (а не сразу отмечает пункт) — рядом с
     * заголовком таких пунктов рисуется MODAL_HINT_SVG */
    const ITEM_OPENS_MODAL = new Set([
        'story_points',
        'git_branch',
        'skill_commit',
        'code_written',
        'pull_request',
        'claude_review',
        'pr_description',
        'jira_description',
    ]);

    // ==================== Иконки сервисов (справа у каждого пункта чек-листа) ====================

    /** Разметка и брендовый цвет иконки по сервису. Цвет одновременно — источник лёгкой подсветки строки */
    const SERVICE_META = {
        jira: {
            color: '#0052CC',
            svg:
                '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.571 11.513H0a5.218 5.218 0 0 0 ' +
                '5.232 5.215h2.13v2.057A5.215 5.215 0 0 0 12.575 24V12.518a1.005 1.005 0 0 0-1.004-1.005zm5.723' +
                '-5.756H5.736a5.215 5.215 0 0 0 5.215 5.215h2.129v2.058a5.218 5.218 0 0 0 5.215 5.214V6.758a1.001' +
                ' 1.001 0 0 0-1.001-1.001zM23.013 0H11.455a5.215 5.215 0 0 0 5.215 5.215h2.129v2.129A5.215 5.215' +
                ' 0 0 0 24 12.559V1.001A1.001 1.001 0 0 0 23.013 0z"/></svg>',
        },
        git: {
            color: '#F05032',
            svg:
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
                'stroke-linejoin="round"><circle cx="6" cy="6" r="2.4"/><circle cx="6" cy="18" r="2.4"/>' +
                '<circle cx="18" cy="6" r="2.4"/><path d="M6 8.4V15.6"/><path d="M8.4 6H14a4 4 0 0 1 4 4v0"/></svg>',
        },
        github: {
            color: '#8957E5',
            svg:
                '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 ' +
                '5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82' +
                '-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51' +
                '-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2' +
                '.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12' +
                '.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 ' +
                '.21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>',
        },
        // Звезда Claude — лучи намеренно разной длины (как в логотипе), а не ровная восьмилучевая
        claude: {
            color: '#D97757',
            svg:
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" ' +
                'stroke-linecap="round"><path d="M12 2.6V21.4"/><path d="M3.1 12.6h17.8"/>' +
                '<path d="M5.5 5.9 18.2 18.6"/><path d="M18.5 6.2 5.8 18.9"/>' +
                '<path d="M8.1 3.9 15.4 20.3"/><path d="M20.6 8.6 3.7 15.9"/></svg>',
        },
        telegram: {
            color: '#26A5E4',
            svg:
                '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 ' +
                '12 12-5.373 12-12S18.627 0 12 0zm5.568 8.16c-.169 1.858-.896 6.728-1.266 8.9-.156.918-.472 ' +
                '1.226-.804 1.257-.696.06-1.226-.406-1.899-.807-1.056-.63-1.653-1.02-2.673-1.632-1.184-.708-.417' +
                '-1.098.259-1.734.176-.168 3.239-2.964 3.298-3.216.007-.031.014-.147-.056-.208-.07-.061-.174-.04' +
                '-.249-.024-.106.023-1.793 1.146-5.062 3.369-.478.328-.913.489-1.301.481-.428-.009-1.252-.242' +
                '-1.865-.442-.751-.244-1.349-.373-1.297-.787.027-.216.325-.437.893-.663 3.498-1.524 5.831-2.529 ' +
                '6.998-3.014 3.332-1.386 4.023-1.627 4.476-1.635.099-.002.321.023.465.14.121.098.153.23.171.322' +
                '.017.09.038.297.021.458z"/></svg>',
        },
        php: {
            color: '#777BB4',
            svg:
                '<svg viewBox="0 0 32 20"><rect width="32" height="20" rx="4" fill="currentColor"/>' +
                '<text x="16" y="14.5" font-family="Helvetica, Arial, sans-serif" font-size="11" ' +
                'font-weight="700" font-style="italic" fill="#fff" text-anchor="middle">php</text></svg>',
        },
    };

    /** Какой сервис относится к каждому пункту чек-листа (код → ключ SERVICE_META) */
    const ITEM_SERVICE = {
        story_points: 'jira',
        status_doing: 'jira',
        git_branch: 'git',
        skill_commit: 'claude',
        code_written: 'php',
        pull_request: 'github',
        claude_review: 'claude',
        pr_description: 'github',
        status_ready_for_review: 'github',
        jira_description: 'jira',
        status_pull_request: 'jira',
        time_tracking: 'jira',
        send_pr: 'telegram',
    };

    /** Минимальный интервал между перезапросами данных из Jira при возврате к окну —
     * общий для индикатора затреканного времени и показателей дашборда */
    const BACKGROUND_REFRESH_MS = 60 * 1000;
    let todayTimeLoadedAt = 0;
    let todayTimeLoading = false;

    /** Рабочий день и норма часов для ползунка быстрого трека (config/params.ini, секция [worktime]) */
    const WORK_TIME = (window.DEVFLOW_CONFIG && window.DEVFLOW_CONFIG.workTime) ||
        // Дублирует Config::WORK_TIME_DEFAULTS, включая обед: без ключей обеда ползунок молча
        // теряет обеденную полосу и начинает трекать обед как рабочее время
        { start: '09:00', end: '18:00', daily_hours: 8, lunch_start: '12:00', lunch_end: '13:00' };

    /** Дневная норма в секундах — общая и для подсветки индикатора, и для ползунка/поздравления */
    const DAILY_NORM_SECONDS = Math.round(Number(WORK_TIME.daily_hours) * 3600);

    /** Шаг ползунка трека времени, минуты */
    const TRACK_STEP_MINUTES = 10;

    /** Диаметр бегунка — должен совпадать с .worktime-range::-webkit-slider-thumb в style.css:
     * по нему считается позиция пузырька над бегунком (бегунок не выезжает за края трека) */
    const TRACK_THUMB_SIZE = 26;

    /** Ключ localStorage — под ним хранится ссылка последней открытой задачи */
    const TASK_LINK_STORAGE_KEY = 'devflow_task_link';

    /** Ключ localStorage и лимит для списка последних открытых задач на экране ввода ссылки */
    const RECENT_TASKS_STORAGE_KEY = 'devflow_recent_tasks';
    const RECENT_TASKS_LIMIT = 10;

    /** Состояние текущей задачи и чек-листа */
    const state = {
        task: null,
        checklist: [],
    };

    // ==================== Утилиты ====================

    // Экранируем вручную, а не через textContent + innerHTML: тот вариант не трогает кавычки,
    // а результат подставляется и внутрь атрибутов (href, placeholder) — значение с кавычкой
    // выходило из атрибута и позволяло дописать в тег свой обработчик
    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Ссылка для href: пускаем только http(s). Ссылка задачи приходит из БД (её однажды ввёл
     * пользователь) и из base_url Jira, поэтому старая запись со схемой `javascript:` иначе
     * исполнила бы код по клику. Валидация на бэкенде (TaskService) закрывает новые записи,
     * эта — уже сохранённые.
     */
    function safeHttpUrl(value) {
        const url = String(value === null || value === undefined ? '' : value).trim();

        return /^https?:\/\//i.test(url) ? url : '';
    }

    function prLinkStorageKey() {
        return `devflow_pr_link_${state.task.id}`;
    }

    /** Есть ли пункт с таким code в чек-листе текущей задачи. Единственный источник правды о
     * составе чек-листа — ответ API (пункты, скрытые флагом задачи claude_code_skill_mode,
     * отфильтровывает ChecklistRepository), поэтому список скрытых пунктов на фронте не
     * дублируется — зависимости между пунктами проверяются через эту функцию */
    function hasChecklistItem(code) {
        return state.checklist.some((entry) => entry.code === code);
    }

    /** tasks.claude_code_skill_mode приходит из SQLite как 0/1 — приводим к JS-булеву */
    function isClaudeCodeSkillModeEnabled(task) {
        return Boolean(Number(task.claude_code_skill_mode));
    }

    /** Описание, введённое в пункте «Закоммитить код» — подставляется в поле пункта «Указать
     * описание PR», чтобы не набирать один и тот же текст дважды */
    function commitDescriptionStorageKey() {
        return `devflow_commit_description_${state.task.id}`;
    }

    /** Форматирует секунды в компактную строку «Хч Ум» для отображения затреканного времени */
    function formatDuration(totalSeconds) {
        const totalMinutes = Math.round(totalSeconds / 60);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        if (hours === 0 && minutes === 0) return '0м';
        return [hours ? `${hours}ч` : '', minutes ? `${minutes}м` : ''].filter(Boolean).join(' ');
    }

    /** Форматирует секунды как «часы:минуты» (2:05) — вид индикатора затреканного за сегодня времени */
    function formatClock(totalSeconds) {
        const totalMinutes = Math.round(totalSeconds / 60);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        return `${hours}:${String(minutes).padStart(2, '0')}`;
    }

    /** Форматирует секунды как «3 часа 25 мин» — вид итога в модалке быстрого трека */
    function formatHoursMinutes(totalSeconds) {
        const totalMinutes = Math.round(totalSeconds / 60);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        const hoursWord = (n) => {
            const tail = n % 100 >= 11 && n % 100 <= 14 ? 0 : n % 10;
            if (tail === 1) return 'час';
            return tail >= 2 && tail <= 4 ? 'часа' : 'часов';
        };
        if (hours === 0) return `${minutes} мин`;
        return minutes === 0
            ? `${hours} ${hoursWord(hours)}`
            : `${hours} ${hoursWord(hours)} ${minutes} мин`;
    }

    /** «HH:MM» → минуты с начала суток (границы рабочего дня приходят из конфига строкой) */
    function parseClock(value) {
        const [hours, minutes] = String(value).split(':').map(Number);
        return (hours || 0) * 60 + (minutes || 0);
    }

    /** Минуты с начала суток → «16:00» (время дня, в отличие от formatClock — длительности) */
    function formatTimeOfDay(totalMinutes) {
        const hours = Math.floor(totalMinutes / 60);
        return `${String(hours).padStart(2, '0')}:${String(totalMinutes % 60).padStart(2, '0')}`;
    }

    /** «Сколько времени назад» для подсказки индикатора затреканного времени (сокращённо: 4 мин / 2 ч) */
    function formatAgo(timestamp) {
        const minutes = Math.floor((Date.now() - timestamp) / 60000);
        if (minutes < 1) return 'только что';
        if (minutes < 60) return `${minutes} мин назад`;
        return `${Math.floor(minutes / 60)} ч назад`;
    }

    /**
     * Заработок для модалки поздравления — округляем до целого, с разделителем тысяч.
     * Подпись валюты приходит с сервера ([currency].label в config/params.ini), на фронте не зашита.
     */
    function formatMoney(value, currencyLabel) {
        return `${Math.round(value).toLocaleString('ru-RU')} ${currencyLabel}`;
    }

    // ==================== Подсказки (data-tooltip) ====================

    /** Задержка перед показом — как в macOS: короткое наведение мышью подсказку не вызывает */
    const TOOLTIP_DELAY_MS = 400;
    let tooltipTimer = null;
    let tooltipTarget = null;

    function positionTooltip(el) {
        const rect = el.getBoundingClientRect();
        // offsetWidth/Height, а не getBoundingClientRect: у скрытой подсказки есть transform
        // (scale появления), и rect отдал бы уменьшенный размер — подсказку сместило бы вбок
        const tipWidth = tooltipEl.offsetWidth;
        const tipHeight = tooltipEl.offsetHeight;
        const margin = 8;

        // Обычно под элементом; если снизу не помещается (окно всего 500×500) — над ним
        let top = rect.bottom + 6;
        if (top + tipHeight + margin > window.innerHeight) {
            top = rect.top - tipHeight - 6;
        }
        const left = Math.min(
            Math.max(margin, rect.left + rect.width / 2 - tipWidth / 2),
            window.innerWidth - tipWidth - margin
        );

        tooltipEl.style.top = `${Math.max(margin, top)}px`;
        tooltipEl.style.left = `${left}px`;
    }

    /** Текст читается в момент показа, а не наведения — так подсказка может быть динамической
     * (например «обновлено 4 мин назад» у индикатора затреканного времени) */
    function showTooltip(el) {
        const text = el.dataset.tooltip;
        if (!text) return;
        tooltipEl.textContent = text;
        positionTooltip(el);
        tooltipEl.classList.add('visible');
        tooltipTarget = el;
    }

    function hideTooltip() {
        clearTimeout(tooltipTimer);
        tooltipTarget = null;
        tooltipEl.classList.remove('visible');
    }

    // Делегирование на document: подсказка появляется у любого элемента с data-tooltip,
    // в том числе у отрисованных динамически (строки «Последних задач», пункты чек-листа)
    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest('[data-tooltip]');
        if (!el || el === tooltipTarget) return;
        hideTooltip();
        tooltipTimer = setTimeout(() => showTooltip(el), TOOLTIP_DELAY_MS);
    });

    document.addEventListener('mouseout', (e) => {
        if (e.target.closest('[data-tooltip]')) hideTooltip();
    });

    // После клика подсказка мешает результату действия (и может устареть) — убираем сразу
    document.addEventListener('click', hideTooltip);

    let toastTimer = null;

    function showToast(message) {
        toastEl.textContent = message;
        toastEl.classList.add('visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('visible'), 2200);
    }

    /** Уведомление о том, что именно скопировано в буфер обмена (единый формат для всех копирований) */
    function notifyCopied(description) {
        showToast(`Скопировано: ${description}`);
    }

    /** Глобальный прелоадер по центру экрана — для запросов к внешним сервисам, начинающихся
     * уже после закрытия модалки (кнопка, закрывающая модалку, не может показать спиннер на себе) */
    function showGlobalLoader() {
        globalLoaderEl.classList.remove('hidden');
    }

    function hideGlobalLoader() {
        globalLoaderEl.classList.add('hidden');
    }

    /** Спиннер прямо на пункте чек-листа — для запросов к внешним сервисам, которые пункт
     * запускает сразу по клику, без модалки (например «Перевести задачу в Pull Request») */
    function setItemLoading(checklistId, loading) {
        const li = checklistEl.querySelector(`li[data-checklist-id="${checklistId}"]`);
        if (li) li.classList.toggle('loading', loading);
    }

    /** Спиннер на кнопке (например «Подтвердить»/«Сгенерировать» пока идёт запрос к внешнему
     * сервису) — единая точка для паттерна disabled+спиннер, не повторяй его руками на местах.
     * Исключение — .recent-task-open, у которой свой .task-ripple вместо этого спиннера. */
    function setButtonLoading(buttonEl, loading) {
        buttonEl.disabled = loading;
        buttonEl.classList.toggle('btn-loading', loading);
        if (loading) {
            buttonEl.insertAdjacentHTML('beforeend', spinnerHtml());
        } else {
            const spinner = buttonEl.querySelector('.ios-spinner');
            if (spinner) spinner.remove();
        }
    }

    /** Копирует обычный текст в буфер обмена */
    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) {
            return false;
        }
    }

    /** Копирует текст с HTML-разметкой (сохраняет стиль при вставке в Jira/редакторы) */
    async function copyRichText(html, plain) {
        try {
            const item = new ClipboardItem({
                'text/html': new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob([plain], { type: 'text/plain' }),
            });
            await navigator.clipboard.write([item]);
            return true;
        } catch (e) {
            return copyText(plain);
        }
    }

    async function apiCall(url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || 'Ошибка запроса');
        }
        return data;
    }

    // ==================== Модальное окно ====================

    /**
     * Показывает модальное окно и возвращает Promise, который разрешается значением
     * нажатой кнопки. Кнопки с keepOpen выполняют действие, не закрывая окно
     * (используется для кнопки «Скопировать» рядом с подтверждением).
     * onRender(bodyEl) вызывается сразу после вставки bodyHtml — там можно навесить
     * свои обработчики на интерактивные элементы внутри тела модалки.
     */
    function showModal(title, bodyHtml, buttons, onRender) {
        return new Promise((resolve) => {
            modalTitleEl.textContent = title;
            modalBodyEl.innerHTML = bodyHtml;
            modalActionsEl.innerHTML = '';

            function cleanup() {
                modalOverlay.classList.add('hidden');
                modalOverlay.removeEventListener('click', onOverlayClick);
            }

            function onOverlayClick(e) {
                if (e.target === modalOverlay) {
                    cleanup();
                    resolve(null);
                }
            }

            /** Позволяет onRender закрыть модалку вручную (например, после успешного API-запроса
             * внутри собственного onClick кнопки, не дожидаясь стандартного авто-закрытия) */
            function close(value) {
                cleanup();
                resolve(value);
            }

            if (typeof onRender === 'function') {
                onRender(modalBodyEl, close);
            }

            // Горизontальный ряд (Отмена слева / действие справа) работает только для 2 кнопок —
            // при 3+ на ширине 500×500 он неизбежно рвётся неровно. Стандарт для 3+ действий —
            // вертикальный full-width стек, порядок сохраняется (Отмена — первая, финальная
            // кнопка — последняя), см. CLAUDE.md.
            modalActionsEl.classList.toggle('modal-actions--stacked', buttons.length > 2);

            buttons.forEach((btn) => {
                const buttonEl = document.createElement('button');
                buttonEl.className = 'btn ' + (btn.primary ? 'btn-primary' : 'btn-secondary');
                buttonEl.textContent = btn.label;
                buttonEl.addEventListener('click', async () => {
                    if (btn.onClick) {
                        await btn.onClick(buttonEl);
                    }
                    if (btn.keepOpen) {
                        return;
                    }
                    const value = btn.getValue ? btn.getValue() : btn.value;
                    cleanup();
                    resolve(value);
                });
                modalActionsEl.appendChild(buttonEl);
            });

            modalOverlay.addEventListener('click', onOverlayClick);
            modalOverlay.classList.remove('hidden');

            const input = modalBodyEl.querySelector('input');
            if (input) {
                input.focus();
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        const primaryBtn = modalActionsEl.querySelector('.btn-primary');
                        if (primaryBtn) primaryBtn.click();
                    }
                });
            }
        });
    }

    // ==================== Тема оформления ====================

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('devflow_theme', theme);
    }

    function initTheme() {
        const saved = localStorage.getItem('devflow_theme');
        if (saved) {
            applyTheme(saved);
            return;
        }
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
    }

    function closePopovers() {
        themePopover.classList.add('hidden');
        gitActionsPopover.classList.add('hidden');
    }

    settingsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        closePopovers();
        themePopover.classList.toggle('hidden');
    });
    document.addEventListener('click', closePopovers);
    themePopover.addEventListener('click', (e) => e.stopPropagation());
    themePopover.querySelectorAll('.theme-option').forEach((btn) => {
        btn.addEventListener('click', () => {
            applyTheme(btn.dataset.theme);
            themePopover.classList.add('hidden');
        });
    });

    // ==================== Claude Code Skill для текущей задачи (раздел «Эта задача») ====================

    /**
     * Показывает/скрывает раздел «Эта задача» в настройках и синхронизирует тумблер с
     * state.task — в отличие от раздела «Claude» ниже, это не общая настройка приложения,
     * а флаг конкретной задачи (tasks.claude_code_skill_mode), поэтому раздел виден только
     * пока задача открыта.
     */
    function updateTaskSettingsSection() {
        if (!state.task) {
            taskSettingsSection.classList.add('hidden');
            return;
        }
        taskSkillModeToggle.checked = isClaudeCodeSkillModeEnabled(state.task);
        taskSettingsSection.classList.remove('hidden');
    }

    // Пишет только в SQLite (без внешнего сервиса) — запрос быстрый, отдельная индикация
    // загрузки не нужна (см. правило в CLAUDE.md), тот же приём, что у claudeNotificationsToggle
    taskSkillModeToggle.addEventListener('change', async () => {
        const enabled = taskSkillModeToggle.checked;
        taskSkillModeToggle.disabled = true;
        try {
            const data = await apiCall('../api/toggle_claude_code_skill_mode.php', {
                task_id: state.task.id,
                enabled,
            });
            state.task = data.task;
            state.checklist = data.checklist;
            taskSkillModeToggle.checked = isClaudeCodeSkillModeEnabled(state.task);
            renderChecklist();
            showToast(enabled ? 'Claude Code Skill включён для этой задачи' : 'Claude Code Skill выключен для этой задачи');
        } catch (e) {
            taskSkillModeToggle.checked = !enabled; // откатываем визуально
            showToast(e.message || 'Не удалось изменить настройку задачи');
        } finally {
            taskSkillModeToggle.disabled = false;
        }
    });

    // ==================== Уведомления Claude Code (раздел «Claude» в настройках) ====================

    /**
     * Читает состояние тумблера при инициализации (не только при открытии попапа — попап
     * может открыться раньше ответа, тогда секция появится с задержкой). Фича опциональна:
     * секция [claude] не заполнена в config/params.ini — available=false, раздел остаётся
     * скрытым (тот же приём, что у дашборда/Jira).
     */
    async function loadClaudeSettings() {
        try {
            const data = await apiCall('../api/get_claude_settings.php', {});
            if (!data.available) return;
            claudeNotificationsToggle.checked = !!data.enabled;
            claudeSettingsSection.classList.remove('hidden');
        } catch (e) {
            // Недоступно — раздел просто не показываем, это не ошибка пользователя
        }
    }

    // Тумблер пишет только в локальный settings.json (без обращения к Telegram) — запрос
    // быстрый, отдельная индикация загрузки не нужна (см. правило в CLAUDE.md)
    claudeNotificationsToggle.addEventListener('change', async () => {
        const enabled = claudeNotificationsToggle.checked;
        claudeNotificationsToggle.disabled = true;
        try {
            const data = await apiCall('../api/toggle_claude_notifications.php', { enabled });
            claudeNotificationsToggle.checked = data.enabled;
            showToast(data.enabled ? 'Уведомления Claude включены' : 'Уведомления Claude выключены');
        } catch (e) {
            claudeNotificationsToggle.checked = !enabled; // откатываем визуально
            showToast(e.message || 'Не удалось изменить настройки Claude');
        } finally {
            claudeNotificationsToggle.disabled = false;
        }
    });

    // ==================== Дропдаун git-команд у названия ветки ====================

    /** Команды git по каждому пункту дропдауна (branch — текущая ветка задачи) */
    // Пункты rebase рисуются из config/params.ini ([git].rebase_targets, см. public/index.php) —
    // базовая ветка приходит в data-base кнопки, поэтому команда одна на все репозитории
    const GIT_ACTION_COMMANDS = {
        'create-branch': (branch) => `git checkout -b ${branch}`,
        push: (branch) => `git push origin ${branch}`,
        rebase: (branch, base) => `git rebase origin/${base}`,
    };

    gitActionsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const wasHidden = gitActionsPopover.classList.contains('hidden');
        closePopovers();
        if (wasHidden) {
            gitActionsPopover.classList.remove('hidden');
        }
    });
    gitActionsPopover.addEventListener('click', (e) => e.stopPropagation());
    gitActionsPopover.querySelectorAll('.git-action-option').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const command = GIT_ACTION_COMMANDS[btn.dataset.action](state.task.git_branch, btn.dataset.base);
            await copyText(command);
            notifyCopied(command);
            gitActionsPopover.classList.add('hidden');
        });
    });

    // ==================== Рендер экрана задачи ====================

    function renderGitBranch() {
        const hasBranch = Boolean(state.task.git_branch);
        gitActionsBtn.classList.toggle('hidden', !hasBranch);
        if (hasBranch) {
            gitBranchValue.textContent = state.task.git_branch;
            gitBranchValue.classList.remove('hidden');
        } else {
            gitBranchValue.classList.add('hidden');
        }
    }

    /**
     * Рендерит чек-лист. Выполненные пункты в списке не показываются — они уже улетели по
     * анимации. Работаем строго по очереди: активен и доступен для клика только первый
     * невыполненный пункт, остальные заблокированы (updateLockState расставляет классы).
     */
    function renderChecklist() {
        checklistEl.innerHTML = '';
        const pending = state.checklist.filter((item) => !item.is_done);

        if (pending.length === 0) {
            checklistEl.innerHTML =
                '<li class="checklist-empty">' +
                '<span class="checklist-empty-text">Все пункты выполнены</span>' +
                '<span class="checklist-empty-icon">👍</span>' +
                '</li>';
            renderProgress();
            return;
        }

        pending.forEach((item) => {
            const service = SERVICE_META[ITEM_SERVICE[item.code]];

            const li = document.createElement('li');
            li.className = 'checklist-item';
            li.dataset.checklistId = String(item.id);
            if (service) {
                li.style.setProperty('--service-color', service.color);
            }
            const modalHint = ITEM_OPENS_MODAL.has(item.code)
                ? `<span class="modal-hint-icon" data-tooltip="Открывает окно">${MODAL_HINT_SVG}</span>`
                : '';
            li.innerHTML =
                `<span class="checkbox">${CHECK_SVG}</span>` +
                `<span class="item-title">${escapeHtml(item.title)}${modalHint}</span>` +
                (service ? `<span class="service-icon" style="color: ${service.color}">${service.svg}</span>` : '') +
                `<span class="item-spinner">${spinnerHtml()}</span>` +
                `<button type="button" class="jump-here-btn">Перейти сюда</button>`;
            li.addEventListener('click', () => {
                // 'done' — на случай повторного клика в ~0.5с окне до улёта пункта: сама li уже
                // отмечена выполненной, но замыкание ниже всё ещё держит старый объект item
                // (state.checklist был заменён новым массивом после markDone), поэтому проверка
                // item.is_done внутри handleItemClick тут не сработает — нужна проверка по DOM.
                if (li.classList.contains('locked') || li.classList.contains('done') || li.classList.contains('loading')) return;
                handleItemClick(item);
            });
            li.querySelector('.jump-here-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                jumpToItem(item);
            });
            checklistEl.appendChild(li);
        });
        updateLockState();
        renderProgress();
    }

    /** Только первый оставшийся пункт активен, все следующие — заблокированы */
    function updateLockState() {
        checklistEl.querySelectorAll('.checklist-item').forEach((li, index) => {
            li.classList.toggle('locked', index !== 0);
        });
    }

    /**
     * Анимация выполнения пункта: сначала проставляется галочка, затем, с небольшой
     * паузой, пункт плавно сворачивается и выезжает из списка, после чего удаляется из DOM.
     */
    function animateItemCompletion(checklistId) {
        const li = checklistEl.querySelector(`li[data-checklist-id="${checklistId}"]`);
        if (!li) {
            renderChecklist();
            return;
        }

        li.classList.add('done');

        setTimeout(() => {
            const height = li.getBoundingClientRect().height;
            li.style.maxHeight = `${height}px`;

            requestAnimationFrame(() => {
                li.classList.add('leaving');
                li.style.maxHeight = '0px';
                li.style.paddingTop = '0px';
                li.style.paddingBottom = '0px';
                li.style.marginTop = '0px';
                li.style.marginBottom = '0px';
            });

            const removeAndCheckEmpty = () => {
                li.remove();
                if (!checklistEl.querySelector('.checklist-item')) {
                    renderChecklist();
                } else {
                    updateLockState(); // следующий по очереди пункт становится активным
                }
            };
            li.addEventListener('transitionend', removeAndCheckEmpty, { once: true });
            setTimeout(removeAndCheckEmpty, 500); // страховка, если transitionend не сработает
        }, 550);
    }

    /** Отображает долю выполненных пунктов чек-листа зелёным прогресс-баром */
    function renderProgress() {
        const total = state.checklist.length;
        const done = state.checklist.filter((item) => item.is_done).length;
        const percent = total === 0 ? 0 : Math.round((done / total) * 100);

        progressFill.style.width = `${percent}%`;
        progressLabel.textContent = `${percent}%`;
    }

    function showTaskScreen() {
        linkError.classList.add('hidden');
        linkScreen.classList.add('hidden');
        taskScreen.classList.remove('hidden');
        taskIdLabel.textContent = state.task.task_id;
        taskIdLabel.href = safeHttpUrl(state.task.task_link);
        renderGitBranch();
        renderChecklist();
        changeTaskBtn.classList.remove('hidden'); // возврат к вводу ссылки есть только внутри задачи
        updateTrackTimeAvailability(); // быстрый трек времени доступен только внутри задачи
        updateTaskSettingsSection(); // раздел «Эта задача» в настройках тоже только внутри задачи
    }

    function showLinkScreen() {
        state.task = null;
        state.checklist = [];
        localStorage.removeItem(TASK_LINK_STORAGE_KEY);
        taskScreen.classList.add('hidden');
        linkScreen.classList.remove('hidden');
        taskLinkInput.value = '';
        taskLinkInput.focus();
        renderRecentTasks();
        changeTaskBtn.classList.add('hidden');
        updateTrackTimeAvailability();
        updateTaskSettingsSection();
    }

    // ==================== Последние открытые задачи (экран ввода ссылки) ====================

    function getRecentTasks() {
        try {
            const list = JSON.parse(localStorage.getItem(RECENT_TASKS_STORAGE_KEY));
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    }

    /** Добавляет задачу в начало списка последних (без дублей, максимум RECENT_TASKS_LIMIT) */
    function rememberRecentTask(task) {
        const withoutCurrent = getRecentTasks().filter((t) => t.taskId !== task.task_id);
        withoutCurrent.unshift({ taskId: task.task_id, link: task.task_link });
        localStorage.setItem(
            RECENT_TASKS_STORAGE_KEY,
            JSON.stringify(withoutCurrent.slice(0, RECENT_TASKS_LIMIT))
        );
    }

    /** Убирает задачу из списка последних (используется при удалении задачи) */
    function forgetRecentTask(taskId) {
        const withoutTask = getRecentTasks().filter((t) => t.taskId !== taskId);
        localStorage.setItem(RECENT_TASKS_STORAGE_KEY, JSON.stringify(withoutTask));
    }

    const TRASH_ICON_SVG =
        '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<polyline points="3 6 5 6 21 6"></polyline>' +
        '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>' +
        '<path d="M10 11v6"></path><path d="M14 11v6"></path>' +
        '<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>' +
        '</svg>';

    /** Запрашивает подтверждение и удаляет задачу из БД и списка последних */
    async function deleteRecentTask(task) {
        const confirmed = await showModal(
            'Удалить задачу?',
            `<p>Задача «${escapeHtml(task.taskId)}» и весь её чек-лист будут удалены без возможности восстановления.</p>`,
            [
                { label: 'Отмена', value: false },
                { label: 'Удалить', primary: true, value: true },
            ]
        );
        if (!confirmed) return;

        // Запрос начинается только после закрытия модалки (кнопка «Удалить» не keepOpen) —
        // на кнопке уже нет спиннера, поэтому показываем глобальный прелоадер по центру экрана.
        showGlobalLoader();
        try {
            await apiCall('../api/delete_task.php', { link: task.link });
        } catch (e) {
            // Задача уже отсутствует в БД (например, удалена ранее из другого окна) —
            // всё равно чистим локальный список, чтобы строка не висела вечно.
        } finally {
            hideGlobalLoader();
        }
        forgetRecentTask(task.taskId);
        renderRecentTasks();
    }

    /** Рендерит список последних задач на экране ввода ссылки; клик по строке открывает задачу */
    function renderRecentTasks() {
        const tasks = getRecentTasks();
        if (tasks.length === 0) {
            recentTasksEl.classList.add('hidden');
            return;
        }

        recentTasksListEl.innerHTML = '';
        tasks.forEach((task) => {
            const row = document.createElement('div');
            row.className = 'recent-task-item';

            const openBtn = document.createElement('button');
            openBtn.type = 'button';
            openBtn.className = 'recent-task-open';
            openBtn.innerHTML =
                `<span class="recent-task-code">${escapeHtml(task.taskId)}</span>` +
                `<span class="recent-task-percent">${skeletonHtml()}</span>` +
                '<span class="task-ripple" aria-hidden="true">' +
                '<span></span><span></span><span></span><span></span>' +
                '</span>';
            openBtn.addEventListener('click', async () => {
                openBtn.disabled = true;
                openBtn.classList.add('btn-loading');
                try {
                    await loadTask(task.link);
                } finally {
                    openBtn.disabled = false;
                    openBtn.classList.remove('btn-loading');
                }
            });

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'recent-task-delete';
            deleteBtn.dataset.tooltip = 'Удалить задачу';
            deleteBtn.setAttribute('aria-label', 'Удалить задачу');
            deleteBtn.innerHTML = TRASH_ICON_SVG;
            deleteBtn.addEventListener('click', () => deleteRecentTask(task));

            row.appendChild(openBtn);
            row.appendChild(deleteBtn);
            recentTasksListEl.appendChild(row);

            // Процент выполнения подгружается отдельно и необязателен для самого открытия
            // задачи — ошибка (например, задача удалена из БД) просто оставит поле пустым.
            // До ответа на месте процента стоит скелетон, а не пустота.
            apiCall('../api/state.php', { link: task.link })
                .then((data) => {
                    const total = data.checklist.length;
                    const done = data.checklist.filter((item) => item.is_done).length;
                    const percent = total === 0 ? 0 : Math.round((done / total) * 100);
                    openBtn.querySelector('.recent-task-percent').textContent = `${percent}%`;
                })
                .catch(() => {
                    openBtn.querySelector('.recent-task-percent').textContent = '';
                });
        });
        recentTasksEl.classList.remove('hidden');
    }

    // ==================== Загрузка / обновление задачи ====================

    function applyTaskState(data, link) {
        state.task = data.task;
        state.checklist = data.checklist;
        localStorage.setItem(TASK_LINK_STORAGE_KEY, link);
        rememberRecentTask(data.task);
        showTaskScreen();
    }

    /**
     * Пользователь вставил ссылку (в поле ввода или кликом по «Последним задачам») —
     * находит либо создаёт задачу. Для уже существующей задачи чек-лист не сбрасывается,
     * возвращается как есть — сброс делает только кнопка «Начать заново» (finishTaskBtn).
     * Заголовок/описание при этом всегда перечитываются из Jira (TaskService::syncJira).
     */
    async function loadTask(link) {
        linkError.classList.add('hidden'); // ошибка предыдущей попытки не должна висеть во время новой
        try {
            const data = await apiCall('../api/task.php', { link });
            applyTaskState(data, link);
        } catch (e) {
            linkScreen.classList.remove('hidden');
            linkError.textContent = e.message;
            linkError.classList.remove('hidden');
        }
    }

    /**
     * Восстанавливает уже открытую задачу после обновления страницы (ссылка из localStorage).
     * В отличие от loadTask — НЕ переоткрытие, поэтому чек-лист не сбрасывается: читаем
     * текущее состояние через api/state.php. Но это тоже открытие задачи, поэтому передаём
     * refresh_jira — заголовок/описание всё равно перечитываются из Jira.
     */
    async function restoreTask(link) {
        try {
            const data = await apiCall('../api/state.php', { link, refresh_jira: true });
            applyTaskState(data, link);
        } catch (e) {
            // Сохранённая ссылка больше не актуальна (например, БД была очищена) — просто
            // показываем экран ввода, без сообщения об ошибке (это не действие пользователя)
            showLinkScreen();
        }
    }

    /** Применяет ответ API (task/checklist) к состоянию и запускает анимацию улёта пункта —
     * общий хвост для markDone() и пунктов с собственным API-эндпоинтом (например Story Points) */
    function applyChecklistUpdate(data, checklistId) {
        state.checklist = data.checklist;
        if (data.task) {
            state.task = data.task;
        }
        renderProgress();
        renderGitBranch();
        animateItemCompletion(checklistId);
    }

    /** Отмечает пункт чек-листа выполненным (опционально передаёт доп. данные, например ветку) */
    async function markDone(checklistId, extra = {}) {
        const data = await apiCall('../api/toggle.php', {
            task_id: state.task.id,
            checklist_id: checklistId,
            done: true,
            ...extra,
        });
        applyChecklistUpdate(data, checklistId);
    }

    /**
     * Возвращает пользователя к пункту с указанным code — снимает отметки с него и всех
     * пунктов после него, чтобы цикл работы повторился (например по следующему репозиторию).
     * Привязка по code, а не по позиции — см. инвариант проекта в CLAUDE.md.
     */
    async function rewindTo(code) {
        const index = state.checklist.findIndex((i) => i.code === code);
        if (index < 0) return;

        let data = null;
        for (const above of state.checklist.slice(index)) {
            if (!above.is_done) continue;
            data = await apiCall('../api/toggle.php', {
                task_id: state.task.id,
                checklist_id: above.id,
                done: false,
            });
        }
        if (data) {
            state.checklist = data.checklist;
            if (data.task) {
                state.task = data.task;
            }
        }
        renderProgress();
        renderChecklist();
    }

    let jumpInProgress = false;

    /**
     * «Перейти сюда» — отмечает выполненными все пункты выше указанного, без вызова их
     * обработчиков (заглушка вместо реального действия: ветка/ссылка на PR и т.п. не
     * сохраняются, просто пропускаются). Сам указанный пункт не трогаем — он становится
     * активным по очереди после перерисовки.
     */
    async function jumpToItem(item) {
        if (jumpInProgress) return;
        const pending = state.checklist.filter((i) => !i.is_done);
        const index = pending.findIndex((i) => i.id === item.id);
        if (index <= 0) return;

        jumpInProgress = true;
        try {
            let data = null;
            for (const above of pending.slice(0, index)) {
                data = await apiCall('../api/toggle.php', {
                    task_id: state.task.id,
                    checklist_id: above.id,
                    done: true,
                });
            }
            if (data) {
                state.checklist = data.checklist;
                if (data.task) {
                    state.task = data.task;
                }
            }
            renderChecklist();
        } finally {
            jumpInProgress = false;
        }
    }

    /** Варианты Story Points для модалки пункта «Указать Story Points» */
    const STORY_POINTS_OPTIONS = [
        { value: 1, description: 'Тривиально, хорошо понятно, никаких неизвестных' },
        { value: 2, description: 'Просто, всё ясно, есть понятный путь выполнения' },
        { value: 3, description: 'Средняя сложность, возможно одна неизвестная' },
        { value: 5, description: 'Сложно, несколько неизвестных или технические сложности' },
        { value: 8, description: 'Очень сложно, много неизвестных, стоит рассмотреть разбиение на части' },
        { value: 13, description: 'Слишком большая задача, обязательно нужно разбить на подзадачи' },
    ];

    // ==================== Поведение пунктов чек-листа ====================
    // Ключ — стабильный code пункта (см. Database::CHECKLIST_ITEMS), а не порядковый номер:
    // так добавление/удаление/переупорядочивание пунктов не требует правок здесь.

    const ITEM_HANDLERS = {
        // Указать Story Points — выбрать значение из шкалы, сохранить его в самой задаче Jira,
        // отметка пункта — только при успешном обновлении в Jira
        story_points: async (item) => {
            const optionsHtml = STORY_POINTS_OPTIONS.map((opt, index) =>
                `<label class="story-points-option">
                     <input type="radio" class="story-points-input" name="story-points" value="${opt.value}" ${index === 0 ? 'checked' : ''}>
                     <span class="story-points-radio">${opt.value}</span>
                     <span class="story-points-desc">${escapeHtml(opt.description)}</span>
                 </label>`
            ).join('');

            let closeModal = null;
            await showModal(
                'Указать Story Points',
                `<div class="story-points-options">${optionsHtml}</div>`,
                [
                    { label: 'Отмена', value: null },
                    {
                        label: 'Подтвердить',
                        primary: true,
                        keepOpen: true, // модалка закрывается вручную через close() только после успешного ответа Jira
                        onClick: async (buttonEl) => {
                            const points = Number(modalBodyEl.querySelector('input[name="story-points"]:checked').value);
                            setButtonLoading(buttonEl, true);
                            try {
                                const data = await apiCall('../api/update_story_points.php', {
                                    task_id: state.task.id,
                                    checklist_id: item.id,
                                    story_points: points,
                                });
                                applyChecklistUpdate(data, item.id);
                                showToast('Story Points в задаче успешно обновлен');
                                closeModal(points);
                            } catch (e) {
                                showToast(e.message || 'Не удалось обновить Story Points в Jira');
                                setButtonLoading(buttonEl, false);
                            }
                        },
                    },
                ],
                (bodyEl, close) => {
                    closeModal = close;
                }
            );
        },

        // Перевести в статус Doing — переводит статус задачи в Jira,
        // отметка пункта — только при успешном переходе (см. status_pull_request для того же паттерна)
        status_doing: async (item) => {
            setItemLoading(item.id, true);
            try {
                const data = await apiCall('../api/transition_doing.php', {
                    task_id: state.task.id,
                    checklist_id: item.id,
                });
                applyChecklistUpdate(data, item.id);
                showToast('Задача переведена в статус Doing');
            } catch (e) {
                showToast(e.message || 'Не удалось перевести статус задачи в Jira');
            } finally {
                setItemLoading(item.id, false);
            }
        },

        // Создать ветку в Git — запросить название, скопировать, сохранить в задаче.
        // Если ветка уже была сохранена ранее — можно оставить её без изменений в БД
        git_branch: async (item) => {
            const existingBranch = state.task.git_branch;
            const KEEP_CURRENT = Symbol('keep-current-branch');
            const buttons = [{ label: 'Отмена', value: null }];
            if (existingBranch) {
                buttons.push({ label: 'Оставить текущую', value: KEEP_CURRENT });
            }
            buttons.push({
                label: 'Сохранить',
                primary: true,
                getValue: () => modalBodyEl.querySelector('input').value.trim() || null,
            });

            const result = await showModal(
                'Название ветки',
                `<input type="text" class="input" placeholder="${escapeHtml('например feature/PROJ-123-описание')}">` +
                    '<div class="modal-copy-actions modal-copy-actions--row">' +
                    '<button type="button" class="btn btn-secondary" data-generate-branch-btn>Сгенерировать</button>' +
                    '<button type="button" class="btn btn-secondary" data-copy-branch-btn>Скопировать</button>' +
                    '</div>',
                buttons,
                (bodyEl) => {
                    bodyEl.querySelector('[data-generate-branch-btn]').addEventListener('click', async (e) => {
                        const buttonEl = e.currentTarget;
                        setButtonLoading(buttonEl, true);
                        try {
                            const data = await apiCall('../api/generate_branch_name.php', { task_id: state.task.id });
                            bodyEl.querySelector('input').value = data.branch_name;
                        } catch (genError) {
                            showToast(genError.message || 'Не удалось сгенерировать название ветки');
                        } finally {
                            setButtonLoading(buttonEl, false);
                        }
                    });
                    bodyEl.querySelector('[data-copy-branch-btn]').addEventListener('click', async () => {
                        const branchValue = bodyEl.querySelector('input').value.trim();
                        if (!branchValue) {
                            showToast('Введите название ветки');
                            return;
                        }
                        const copied = await copyText(branchValue);
                        if (copied) {
                            notifyCopied(`название ветки «${branchValue}»`);
                        } else {
                            showToast('Не удалось скопировать');
                        }
                    });
                }
            );

            if (result === KEEP_CURRENT) {
                await markDone(item.id);
                return;
            }
            if (!result) return;

            const branch = result;
            const copied = await copyText(branch);
            await markDone(item.id, { branch });
            if (copied) {
                notifyCopied(`название ветки «${branch}»`);
            } else {
                showToast('Ветка сохранена, но не скопирована');
            }
        },

        // Закоммитить код — ввести описание, сгенерировать первую строку commit message нейронкой
        // (копируется в буфер сразу по готовности, кнопку можно нажимать повторно), отметка пункта —
        // только отдельной кнопкой «Закоммитил и Запушил». Введённое описание сохраняется в
        // sessionStorage и подставляется в поле пункта «Указать описание PR»
        code_written: async (item) => {
            const result = await showModal(
                'Закоммитить код',
                '<div class="form-modal">' +
                    `<textarea class="input textarea" data-description placeholder="${escapeHtml('Опишите что сделали...')}"></textarea>` +
                    '<div class="modal-copy-actions">' +
                    '<button type="button" class="btn btn-secondary" data-generate-btn>Сгенерировать Description</button>' +
                    '</div>' +
                    '<div class="snippet hidden" id="commit-message-result"></div>' +
                    '</div>',
                [
                    { label: 'Отмена', value: null },
                    {
                        // Объект, а не строка: пустое описание не должно читаться как отмена
                        label: 'Закоммитил и Запушил',
                        primary: true,
                        getValue: () => ({
                            description: modalBodyEl.querySelector('[data-description]').value.trim(),
                        }),
                    },
                ],
                (bodyEl) => {
                    bodyEl.querySelector('[data-generate-btn]').addEventListener('click', async (e) => {
                        const buttonEl = e.currentTarget;
                        const description = bodyEl.querySelector('[data-description]').value.trim();
                        if (!description) {
                            showToast('Опишите, что сделали');
                            return;
                        }

                        setButtonLoading(buttonEl, true);
                        try {
                            const data = await apiCall('../api/generate_commit_message.php', {
                                description,
                                task_id: state.task.task_id,
                            });
                            await copyText(data.message);
                            notifyCopied('commit message');
                            const resultEl = bodyEl.querySelector('#commit-message-result');
                            resultEl.textContent = data.message;
                            resultEl.classList.remove('hidden');
                        } catch (e) {
                            showToast(e.message || 'Не удалось сгенерировать commit message');
                        } finally {
                            setButtonLoading(buttonEl, false);
                        }
                    });
                }
            );
            if (!result) {
                return;
            }

            sessionStorage.setItem(commitDescriptionStorageKey(), result.description);
            await markDone(item.id);
        },

        // Создать PR — 1) скопировать команду `gh pr create`, 2) вставить ссылку на созданный PR;
        // ссылка сохраняется для пунктов «Проверить PR Claude Code», «Отправить PR ревьюверу»
        pull_request: async (item) => {
            const command = buildGhPrCreateCommand();
            const link = await showModal(
                'Создать PR',
                `<div class="pr-steps">
                     <div class="pr-step">
                         <span class="pr-step-num">1</span>
                         <span class="pr-step-text">Выполните команду</span>
                         <button type="button" class="btn btn-secondary" data-copy-command-btn>Скопировать</button>
                     </div>
                     <div class="pr-step">
                         <span class="pr-step-num">2</span>
                         <input type="text" class="input" id="pr-link-input" style="flex: 1;" placeholder="${escapeHtml('Вставьте ссылку на PR')}">
                     </div>
                 </div>`,
                [
                    { label: 'Отмена', value: null },
                    {
                        label: 'Готово',
                        primary: true,
                        getValue: () => modalBodyEl.querySelector('#pr-link-input').value.trim() || null,
                    },
                ],
                (bodyEl) => {
                    bodyEl.querySelector('[data-copy-command-btn]').addEventListener('click', async () => {
                        await copyText(command);
                        notifyCopied('команда gh pr create');
                    });
                }
            );
            if (!link) return;
            sessionStorage.setItem(prLinkStorageKey(), link);
            await markDone(item.id);
        },

        // Закоммитить изменения — шаг режима Claude Code Skill: коммит, PR и его описание делает
        // сам скилл, от приложения нужна только его команда в буфере. Инструкцию выливки скилл
        // знать не может — поэтому здесь же (в режиме скилла пункт `pr_description` скрыт, а
        // значит ввести её больше негде) можно оформить её блок для вставки в описание PR:
        // логика та же, что у `pr_description` — результат копируется сразу, генерацию и
        // копирование можно повторять. Инструкция необязательна, отметка пункта — «Готово»
        skill_commit: async (item) => {
            const confirmed = await showModal(
                'Закоммитить изменения',
                '<div class="form-modal">' +
                    '<div class="modal-copy-actions">' +
                    `<button type="button" class="btn btn-secondary" data-copy-btn>Скопировать ${escapeHtml(SKILL_COMMIT_COMMAND)}</button>` +
                    '</div>' +
                    `<textarea class="input textarea" data-instruction placeholder="${escapeHtml('Инструкция выливки (необязательно)...')}"></textarea>` +
                    '<div class="modal-copy-actions">' +
                    '<button type="button" class="btn btn-secondary" data-generate-btn>Сгенерировать</button>' +
                    '</div>' +
                    '<div class="snippet hidden" id="deploy-instruction-result"></div>' +
                    '<div class="modal-copy-actions hidden" id="deploy-instruction-copy-actions">' +
                    '<button type="button" class="btn btn-secondary" data-copy-result-btn>Скопировать</button>' +
                    '</div>' +
                    '</div>',
                [
                    { label: 'Отмена', value: false },
                    { label: 'Готово', primary: true, value: true },
                ],
                (bodyEl) => {
                    bodyEl.querySelector('[data-copy-btn]').addEventListener('click', async () => {
                        await copyText(SKILL_COMMIT_COMMAND);
                        notifyCopied(`команда «${SKILL_COMMIT_COMMAND}»`);
                    });

                    bodyEl.querySelector('[data-generate-btn]').addEventListener('click', async (e) => {
                        const buttonEl = e.currentTarget;
                        const instruction = bodyEl.querySelector('[data-instruction]').value.trim();
                        if (!instruction) {
                            showToast('Опишите инструкцию выливки');
                            return;
                        }

                        setButtonLoading(buttonEl, true);
                        try {
                            const data = await apiCall('../api/generate_deploy_instruction.php', {
                                instruction,
                            });
                            await copyText(data.instruction);
                            notifyCopied('инструкция выливки');
                            const resultEl = bodyEl.querySelector('#deploy-instruction-result');
                            resultEl.textContent = data.instruction;
                            resultEl.classList.remove('hidden');
                            bodyEl.querySelector('#deploy-instruction-copy-actions').classList.remove('hidden');
                        } catch (e) {
                            showToast(e.message || 'Не удалось сгенерировать инструкцию выливки');
                        } finally {
                            setButtonLoading(buttonEl, false);
                        }
                    });
                    bodyEl.querySelector('[data-copy-result-btn]').addEventListener('click', async () => {
                        await copyText(bodyEl.querySelector('#deploy-instruction-result').textContent);
                        notifyCopied('инструкция выливки');
                    });
                }
            );
            if (confirmed) {
                await markDone(item.id);
            }
        },

        // Проверить PR через Claude — скопировать шаблон промпта со ссылкой на PR из шага «PR создан»,
        // отметка пункта выполненным отдельной кнопкой (копирование можно повторять, не завершая пункт)
        claude_review: async (item) => {
            const reviewText = buildClaudeReviewText(sessionStorage.getItem(prLinkStorageKey()));
            const confirmed = await showModal(
                'Проверка PR через Claude',
                `<div class="snippet">${escapeHtml(reviewText)}</div>` +
                    '<div class="modal-copy-actions">' +
                    '<button type="button" class="btn btn-secondary" data-copy-btn>Скопировать</button>' +
                    '</div>',
                [
                    { label: 'Отмена', value: false },
                    { label: 'Готово', primary: true, value: true },
                ],
                (bodyEl) => {
                    bodyEl.querySelector('[data-copy-btn]').addEventListener('click', async () => {
                        await copyText(reviewText);
                        notifyCopied('промпт для ревью Claude');
                    });
                }
            );
            if (!confirmed) {
                return;
            }

            await markDone(item.id);
        },

        // Указать описание PR — ввести, что сделано, и (необязательно) инструкцию выливки;
        // нейронка собирает описание PR по шаблону команды, инструкция — отдельным блоком в конец
        // (не указана — блока нет вообще). Результат копируется в буфер сразу по готовности; под ним
        // появляется отдельная кнопка «Скопировать» для повторного копирования (генерацию тоже можно
        // повторять), отметка пункта — только отдельной кнопкой «Готово»
        // После «Готово» — вопрос про следующий репозиторий мультирепо-задачи (см. ниже)
        pr_description: async (item) => {
            const confirmed = await showModal(
                'Описание PR',
                '<div class="form-modal">' +
                    `<textarea class="input textarea" data-description placeholder="${escapeHtml('Опишите что сделали...')}"></textarea>` +
                    `<textarea class="input textarea" data-instruction placeholder="${escapeHtml('Инструкция выливки (необязательно)...')}"></textarea>` +
                    '<div class="modal-copy-actions">' +
                    '<button type="button" class="btn btn-secondary" data-generate-btn>Сгенерировать</button>' +
                    '</div>' +
                    '<div class="snippet hidden" id="pr-description-result"></div>' +
                    '<div class="modal-copy-actions hidden" id="pr-description-copy-actions">' +
                    '<button type="button" class="btn btn-secondary" data-copy-result-btn>Скопировать</button>' +
                    '</div>' +
                    '</div>',
                [
                    { label: 'Отмена', value: false },
                    { label: 'Готово', primary: true, value: true },
                ],
                (bodyEl) => {
                    // Что сделано уже описано в пункте «Закоммитить код» — переиспользуем текст
                    bodyEl.querySelector('[data-description]').value =
                        sessionStorage.getItem(commitDescriptionStorageKey()) || '';

                    bodyEl.querySelector('[data-generate-btn]').addEventListener('click', async (e) => {
                        const buttonEl = e.currentTarget;
                        const description = bodyEl.querySelector('[data-description]').value.trim();
                        const instruction = bodyEl.querySelector('[data-instruction]').value.trim();
                        if (!description) {
                            showToast('Опишите, что сделали');
                            return;
                        }

                        setButtonLoading(buttonEl, true);
                        try {
                            const data = await apiCall('../api/generate_pr_description.php', {
                                description,
                                instruction,
                                task_id: state.task.task_id,
                                task_link: state.task.task_link,
                            });
                            await copyText(data.description);
                            notifyCopied('описание PR');
                            const resultEl = bodyEl.querySelector('#pr-description-result');
                            resultEl.textContent = data.description;
                            resultEl.classList.remove('hidden');
                            bodyEl.querySelector('#pr-description-copy-actions').classList.remove('hidden');
                        } catch (e) {
                            showToast(e.message || 'Не удалось сгенерировать описание PR');
                        } finally {
                            setButtonLoading(buttonEl, false);
                        }
                    });
                    bodyEl.querySelector('[data-copy-result-btn]').addEventListener('click', async () => {
                        const result = bodyEl.querySelector('#pr-description-result').textContent;
                        await copyText(result);
                        notifyCopied('описание PR');
                    });
                }
            );
            if (!confirmed) {
                return;
            }

            // Одна задача может затрагивать несколько репозиториев — если проект ещё есть,
            // весь цикл «коммит → PR → ревью → описание PR» повторяется для него, поэтому
            // откатываемся к «Закоммитить код» (этот пункт не отмечаем — он тоже войдёт в новый цикл)
            const hasMoreProjects = await showModal('Есть ещё один проект?', '', [
                { label: 'Нет', value: false },
                { label: 'Да', primary: true, value: true },
            ]);
            if (hasMoreProjects === true) {
                await rewindTo('code_written');
                showToast('Закоммитьте код в следующем проекте');
                return;
            }

            await markDone(item.id);
        },

        // PR`s переведены в Ready for review — отмечается сразу
        status_ready_for_review: (item) => markDone(item.id),

        // Оставить описание в Jira — кнопки копирования (описание и ссылка на PR из шага
        // «PR создан») можно нажимать повторно, отметка пункта — отдельной кнопкой «Готово»
        jira_description: async (item) => {
            const confirmed = await showModal(
                'Описание в Jira',
                `<div class="snippet" style="font-family: inherit;">${JIRA_DESCRIPTION_HTML}</div>` +
                    '<div class="modal-copy-actions">' +
                    '<button type="button" class="btn btn-secondary" data-copy-btn>Скопировать</button>' +
                    // ссылку на PR сохраняет пункт «Создать PR» — пока он отключён, копировать нечего
                    (hasChecklistItem('pull_request')
                        ? '<button type="button" class="btn btn-secondary" data-copy-pr-btn>Скопировать PR</button>'
                        : '') +
                    '</div>',
                [
                    { label: 'Отмена', value: false },
                    { label: 'Готово', primary: true, value: true },
                ],
                (bodyEl) => {
                    bodyEl.querySelector('[data-copy-btn]').addEventListener('click', async () => {
                        await copyRichText(JIRA_DESCRIPTION_HTML, JIRA_DESCRIPTION_PLAIN);
                        notifyCopied('описание для Jira (с форматированием)');
                    });
                    bodyEl.querySelector('[data-copy-pr-btn]')?.addEventListener('click', async () => {
                        await copyText(sessionStorage.getItem(prLinkStorageKey()) || '');
                        notifyCopied('ссылка на PR');
                    });
                }
            );
            if (confirmed) {
                await markDone(item.id);
            }
        },

        // Перевести задачу в Pull Request — переводит статус задачи в Jira,
        // отметка пункта — только при успешном переходе (см. update_story_points для того же паттерна)
        status_pull_request: async (item) => {
            setItemLoading(item.id, true);
            try {
                const data = await apiCall('../api/transition_pull_request.php', {
                    task_id: state.task.id,
                    checklist_id: item.id,
                });
                applyChecklistUpdate(data, item.id);
                showToast('Задача переведена в статус Pull request');
            } catch (e) {
                showToast(e.message || 'Не удалось перевести статус задачи в Jira');
            } finally {
                setItemLoading(item.id, false);
            }
        },

        // Затрекать время — показать сколько уже затрекано, запросить часы/минуты сверх
        // имеющегося, добавить worklog в Jira, отметка пункта — только при успехе
        time_tracking: async (item) => {
            // Ползунок строится на затреканном за сегодня (по всем задачам), а строкой над ним
            // показывается затреканное именно в эту задачу — оба числа нужны сразу
            let todaySeconds = 0;
            let taskSeconds = 0;
            setItemLoading(item.id, true);
            try {
                const [today, task] = await Promise.all([
                    apiCall('../api/today_time_spent.php', ensureTaskPayload()),
                    apiCall('../api/get_time_spent.php', { task_id: state.task.id }),
                ]);
                todaySeconds = today.time_spent_seconds || 0;
                taskSeconds = task.time_spent_seconds || 0;
                applyTodayTimeSpent(todaySeconds);
            } catch (e) {
                showToast(e.message || 'Не удалось получить затреканное время из Jira');
                return;
            } finally {
                setItemLoading(item.id, false);
            }

            await openQuickTrackModal(todaySeconds, {
                noteHtml:
                    '<div class="snippet">Уже затрекано в задачу: ' +
                    `<strong>${escapeHtml(formatDuration(taskSeconds))}</strong></div>`,
                submit: async (minutes) => {
                    const data = await apiCall('../api/log_time.php', {
                        task_id: state.task.id,
                        checklist_id: item.id,
                        hours: Math.floor(minutes / 60),
                        minutes: minutes % 60,
                    });
                    applyChecklistUpdate(data, item.id);
                    showToast('Время затрекано в Jira');
                },
            });
        },

        // PR отправлен ревьюверу — отмечается сразу по клику, без модалки и запросов
        send_pr: (item) => markDone(item.id),
    };

    function handleItemClick(item) {
        if (item.is_done) {
            return;
        }
        const handler = ITEM_HANDLERS[item.code];
        if (handler) {
            handler(item);
        }
    }

    // ==================== Обработчики верхнего уровня ====================

    async function submitLink() {
        const link = taskLinkInput.value.trim();
        if (!link) return;
        setButtonLoading(openTaskBtn, true);
        try {
            await loadTask(link);
        } finally {
            setButtonLoading(openTaskBtn, false);
        }
    }

    taskLinkInput.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        submitLink();
    });

    openTaskBtn.addEventListener('click', submitLink);

    changeTaskBtn.addEventListener('click', showLinkScreen);

    finishTaskBtn.addEventListener('click', async () => {
        const confirmed = await showModal(
            'Начать заново?',
            '<p>Все отметки чек-листа будут сброшены, задача начнётся с первого пункта.</p>',
            [
                { label: 'Отмена', value: false },
                { label: 'Начать заново', primary: true, value: true },
            ]
        );
        if (!confirmed) return;

        const data = await apiCall('../api/finish.php', { task_id: state.task.id });
        state.checklist = data.checklist;
        sessionStorage.removeItem(prLinkStorageKey());
        renderChecklist();
        showToast('Чек-лист сброшен');
    });

    gitBranchValue.addEventListener('click', async () => {
        const copied = await copyText(state.task.git_branch);
        if (copied) {
            notifyCopied(`название ветки «${state.task.git_branch}»`);
        } else {
            showToast('Не удалось скопировать');
        }
    });

    // ==================== Затреканное сегодня время (шапка) ====================

    /** Единая точка применения свежего значения к индикатору — им пользуются и обычная
     * подгрузка, и быстрый трек времени (оба получают из Jira одно и то же число) */
    function applyTodayTimeSpent(seconds) {
        todayTimeLoadedAt = Date.now();
        todayTimeEl.classList.remove('hidden');
        todayTimeValueEl.textContent = formatClock(seconds);
        // Норма за день выполнена — индикатор зеленеет и показывает галочку
        todayTimeEl.classList.toggle('today-time--met', seconds >= DAILY_NORM_SECONDS);
        updateTrackTimeAvailability();
    }

    /**
     * Подтягивает суммарно затреканное сегодня в Jira время. Специально не участвует в
     * цепочке загрузки задачи и ничего не блокирует: индикатор появляется сам, когда Jira
     * ответит, а при ошибке (нет интеграции, недоступна сеть) просто остаётся скрытым.
     */
    /** Открытая сейчас задача для эндпоинтов сегодняшнего времени: её свежий worklog Jira
     * отдаёт поиском с задержкой, поэтому задачу передаём явно — иначе только что затреканное
     * время пропадёт и из индикатора, и из списков задач за сегодня, и из ползунка трека */
    function ensureTaskPayload() {
        return state.task ? { task_id: state.task.id } : {};
    }

    async function loadTodayTimeSpent() {
        if (todayTimeLoading) return;
        todayTimeLoading = true;
        todayTimeLoadedAt = Date.now();
        todayTimeEl.classList.remove('hidden');
        todayTimeEl.classList.remove('today-time--met');
        todayTimeValueEl.innerHTML = skeletonHtml();
        try {
            const data = await apiCall('../api/today_time_spent.php', ensureTaskPayload());
            applyTodayTimeSpent(data.time_spent_seconds || 0);
        } catch (e) {
            todayTimeEl.classList.add('hidden');
            todayTimeValueEl.textContent = '';
            updateTrackTimeAvailability();
        } finally {
            todayTimeLoading = false;
        }
    }

    // ==================== Быстрый трек времени ползунком ====================

    /** Кружок трека всплывает по наведению только когда трекать есть куда: открыта задача и
     * время из Jira доступно (индикатор скрыт — значит интеграция не настроена или недоступна) */
    function updateTrackTimeAvailability() {
        const available = Boolean(state.task) && !todayTimeEl.classList.contains('hidden');
        trackTimeBtn.classList.toggle('available', available);
    }

    /**
     * Модалка трека времени ползунком — одна на кружок быстрого трека и на пункт чек-листа
     * «Затрекать время»: ползунок от начала до конца рабочего дня ([worktime] в
     * config/params.ini), позиция бегунка — время, до которого отработан день. Отсюда
     * сумма трека за день = отработанные минуты до позиции (обед из них вычитается,
     * см. workedMinutes), а в Jira уходит только разница с уже затреканным (alreadySeconds).
     * Минимум ползунка — позиция уже затреканного времени: «раз-трекать» назад нельзя,
     * поэтому левее бегунок не уводим.
     *
     * Различается только отправка: `submit(minutes)` уходит в свой эндпоинт и сам сообщает
     * об успехе (тост, отметка пункта), ошибку — бросает. `noteHtml` — необязательная строка
     * над ползунком (у пункта чек-листа там затреканное именно в эту задачу время).
     */
    function openQuickTrackModal(alreadySeconds, { submit, noteHtml = '' }) {
        const startMinutes = parseClock(WORK_TIME.start);
        const maxMinutes = parseClock(WORK_TIME.end) - startMinutes;
        const normSeconds = DAILY_NORM_SECONDS;
        // Обед задан не всегда (Config отдаёт пустые строки, если он не влезает в рабочий день)
        const hasLunch = Boolean(WORK_TIME.lunch_start && WORK_TIME.lunch_end);
        const lunchFrom = hasLunch ? parseClock(WORK_TIME.lunch_start) - startMinutes : 0;
        const lunchTo = hasLunch ? parseClock(WORK_TIME.lunch_end) - startMinutes : 0;

        /** Отработанные минуты для позиции бегунка: попавший в интервал обед не работа */
        const workedMinutes = (position) =>
            position - Math.max(0, Math.min(position, lunchTo) - lunchFrom);

        /** Обратное к workedMinutes: позиция бегунка, при которой отработано worked минут */
        const positionFor = (worked) =>
            hasLunch && worked > lunchFrom ? worked + (lunchTo - lunchFrom) : worked;

        // Стартовая позиция — ближайший шаг сетки не выше уже затреканного, чтобы первый же
        // сдвиг вправо добавлял время, а не «догонял» дробный остаток
        const baseMinutes = Math.min(
            maxMinutes,
            positionFor(Math.floor(alreadySeconds / 60 / TRACK_STEP_MINUTES) * TRACK_STEP_MINUTES)
        );

        let closeModal = null;
        let addedMinutes = 0;

        return showModal(
            'Затрекать время 🕰',
            noteHtml +
                '<div class="worktime">' +
                `<span class="worktime-edge">${escapeHtml(WORK_TIME.start)}</span>` +
                '<div class="worktime-track">' +
                // Прерывистая синяя полоска-подсказка: от конца уже затреканного (зелёного)
                // до текущего времени — докуда нужно довести бегунок, чтобы время под ним
                // стало зелёным (см. worktime-clock--ok/--warn в render()). Стоит в разметке
                // до бегунка, чтобы белый круг бегунка (часть самого <input>) рисовался поверх
                // неё, а не наоборот.
                '<span class="worktime-now-guide" data-worktime-now-guide></span>' +
                // Второй сегмент — продолжение полоски ПОСЛЕ обеда, когда обед попадает
                // внутрь диапазона «от бегунка до сейчас» (см. render()): полоска рвётся на
                // время обеда, а не рисуется одним прямоугольником поверх оранжевой зоны.
                '<span class="worktime-now-guide worktime-now-guide--no-mask" data-worktime-now-guide-2></span>' +
                '<span class="worktime-bubble" data-worktime-bubble></span>' +
                // Ползунок покрывает весь рабочий день (min=0 — его начало), чтобы уже
                // затреканное время было видно зелёной частью заполнения; левее него бегунок
                // не пускает render() — «раз-трекать» назад нельзя
                '<input type="range" class="worktime-range" data-worktime-range ' +
                `min="0" max="${maxMinutes}" step="${TRACK_STEP_MINUTES}" value="${baseMinutes}">` +
                '<span class="worktime-clock" data-worktime-clock></span>' +
                '</div>' +
                `<span class="worktime-edge">${escapeHtml(WORK_TIME.end)}</span>` +
                '</div>',
            [
                { label: 'Отмена', value: null },
                {
                    label: 'Затрекать',
                    primary: true,
                    keepOpen: true, // модалка закрывается вручную через close() только после успешного ответа Jira
                    onClick: async (buttonEl) => {
                        setButtonLoading(buttonEl, true);
                        try {
                            await submit(addedMinutes);
                            loadTodayTimeSpent(); // индикатор должен сразу учесть новый worklog

                            // Поздравление — только в момент первого за сегодня достижения нормы:
                            // если норма была выполнена уже до этого трека, считаем что поздравление
                            // уже показывалось, и дальнейший трек (сверхурочные) его не повторяет.
                            const totalSecondsToday = alreadySeconds + addedMinutes * 60;
                            const justReachedNorm = alreadySeconds < normSeconds && totalSecondsToday >= normSeconds;

                            closeModal(true);
                            if (justReachedNorm) {
                                showCongratsModal(totalSecondsToday);
                            }
                        } catch (e) {
                            showToast(e.message || 'Не удалось затрекать время в Jira');
                            setButtonLoading(buttonEl, false);
                        }
                    },
                },
            ],
            (bodyEl, close) => {
                closeModal = close;

                const range = bodyEl.querySelector('[data-worktime-range]');
                const bubble = bodyEl.querySelector('[data-worktime-bubble]');
                const clock = bodyEl.querySelector('[data-worktime-clock]');
                const nowGuide = bodyEl.querySelector('[data-worktime-now-guide]');
                const nowGuide2 = bodyEl.querySelector('[data-worktime-now-guide-2]');

                // Бегунок не выходит за края трека, поэтому его центр — не просто доля ширины
                const usableWidth = () => range.clientWidth - TRACK_THUMB_SIZE;
                const centerOf = (minutes) =>
                    TRACK_THUMB_SIZE / 2 + (maxMinutes === 0 ? 0 : minutes / maxMinutes) * usableWidth();

                /** Последнее валидное значение — по нему видно, в какую сторону едет бегунок,
                 * когда его надо перекинуть через обед (стоять внутри обеда бессмысленно:
                 * отработанное время там не растёт) */
                let lastValue = baseMinutes;

                function normalizePosition(raw) {
                    const clamped = Math.min(maxMinutes, Math.max(baseMinutes, raw));
                    if (!hasLunch || clamped <= lunchFrom || clamped >= lunchTo) return clamped;
                    return clamped > lastValue ? lunchTo : lunchFrom;
                }

                function render() {
                    const value = normalizePosition(Number(range.value));
                    if (Number(range.value) !== value) {
                        range.value = value; // левее затреканного и внутрь обеда бегунок не пускаем
                    }
                    lastValue = value;
                    const addedSeconds = Math.max(0, workedMinutes(value) * 60 - alreadySeconds);
                    addedMinutes = Math.round(addedSeconds / 60);

                    const thumbCenter = centerOf(value);
                    // Границы заполнения — в пикселях, а не в процентах: только так стык зелёного
                    // (уже затреканного) и синего (добавляемого) попадает точно под центр бегунка.
                    // Исключение — baseMinutes === 0: centerOf(0) не 0, а половина ширины бегунка
                    // (танцует вокруг его центра), так что без затреканного времени всё равно
                    // рисовалась бы полоска «фантомного» зелёного слева от бегунка после того как
                    // его увели вправо. При нуле уже-затреканного зелёного быть не должно вообще.
                    range.style.setProperty('--base', `${baseMinutes === 0 ? 0 : centerOf(baseMinutes)}px`);
                    range.style.setProperty('--fill', `${thumbCenter}px`);
                    if (hasLunch) {
                        range.style.setProperty('--lunch-a', `${centerOf(lunchFrom)}px`);
                        range.style.setProperty('--lunch-b', `${centerOf(lunchTo)}px`);
                    }

                    clock.style.left = `${thumbCenter}px`;
                    clock.textContent = formatTimeOfDay(startMinutes + value);
                    // Позиция позже текущего времени — зелёная, не позже — оранжевая.
                    // Время берём в момент рендера, за время открытой модалки оно уходит вперёд.
                    const now = new Date();
                    clock.className =
                        'worktime-clock ' +
                        (now.getHours() * 60 + now.getMinutes() < startMinutes + value
                            ? 'worktime-clock--ok'
                            : 'worktime-clock--warn');

                    // Полоска-подсказка: от текущего положения бегунка до реального текущего
                    // времени — докуда ещё нужно дотянуть, чтобы время под бегунком стало
                    // зелёным. Двигается вместе с бегунком при перетаскивании.
                    const nowMinutes = Math.min(
                        maxMinutes,
                        Math.max(0, now.getHours() * 60 + now.getMinutes() - startMinutes)
                    );
                    if (nowMinutes > value) {
                        // Начинается ровно от ЦЕНТРА бегунка (не от его левого/правого края).
                        // Бегунок — круг, но его hit-область в браузере квадратная 26×26, и в
                        // «уголках» между кругом и этим квадратом видно то, что нарисовано под
                        // бегунком — если начинать полосу правее круга целиком, там был виден
                        // голый жёлоб вместо штриховки. Раз полоса — позиционированный
                        // элемент, она в любом случае рисуется поверх нативного бегунка, поэтому
                        // сам круг вырезаем маской (.worktime-now-guide), а не смещением: маска
                        // вырезает только ПРАВУЮ половину круга (т.к. полоса начинается ровно на
                        // его середине), левая половина бегунка полосой вообще не занята.
                        const guideFrom = thumbCenter;
                        const guideTo = centerOf(nowMinutes);
                        // Обед не идёт в трек и не должен перекрываться штриховкой — если он
                        // попадает внутрь диапазона [guideFrom, guideTo], рвём полоску на две:
                        // до обеда (с вырезом под бегунок) и после обеда (без выреза, бегунка
                        // там рядом не бывает — normalizePosition не даёт бегунку стоять в обеде)
                        const lunchFromPx = hasLunch ? centerOf(lunchFrom) : null;
                        const lunchToPx = hasLunch ? centerOf(lunchTo) : null;
                        const splitByLunch =
                            hasLunch && lunchFromPx > guideFrom && lunchFromPx < guideTo;

                        nowGuide.style.display = 'block';
                        nowGuide.style.left = `${guideFrom}px`;
                        nowGuide.style.width =
                            `${Math.max(0, (splitByLunch ? lunchFromPx : guideTo) - guideFrom)}px`;

                        if (splitByLunch && lunchToPx < guideTo) {
                            nowGuide2.style.display = 'block';
                            nowGuide2.style.left = `${lunchToPx}px`;
                            nowGuide2.style.width = `${guideTo - lunchToPx}px`;
                        } else {
                            nowGuide2.style.display = 'none';
                        }
                    } else {
                        nowGuide.style.display = 'none';
                        nowGuide2.style.display = 'none';
                    }

                    if (addedSeconds > 0) {
                        bubble.textContent =
                            `+${formatClock(addedSeconds)} (${formatClock(alreadySeconds + addedSeconds)})`;
                        bubble.className = 'worktime-bubble worktime-bubble--adding';
                    } else {
                        bubble.textContent = formatClock(alreadySeconds);
                        bubble.className =
                            'worktime-bubble ' +
                            (alreadySeconds >= normSeconds ? 'worktime-bubble--met' : 'worktime-bubble--under');
                    }
                    // Длинный пузырёк у краёв трека вылез бы за модалку — придерживаем его внутри
                    const half = bubble.offsetWidth / 2;
                    bubble.style.left =
                        `${Math.min(Math.max(thumbCenter, half), range.clientWidth - half)}px`;

                    // Кнопки showModal() создаёт после onRender, поэтому ищем их при каждом рендере
                    const primaryEl = modalActionsEl.querySelector('.btn-primary');
                    primaryEl.disabled = addedMinutes === 0;

                    // Итог по центру ряда действий: сколько реально уйдёт в Jira (без обеда).
                    // Вставляется здесь же — на момент onRender кнопок в DOM ещё нет.
                    let summaryEl = modalActionsEl.querySelector('[data-worktime-summary]');
                    if (!summaryEl) {
                        summaryEl = document.createElement('span');
                        summaryEl.className = 'worktime-summary';
                        summaryEl.setAttribute('data-worktime-summary', '');
                        modalActionsEl.insertBefore(summaryEl, primaryEl);
                    }
                    summaryEl.textContent = `Будет затрекано: ${formatHoursMinutes(addedSeconds)}`;
                }

                // Подсказки по цветам жёлоба. Сам жёлоб — один <input>, а не набор элементов
                // (см. комментарий у .worktime-range), поэтому обычный data-tooltip на всю
                // область не подходит — зон несколько (зелёная/синяя/серая/обед/полоска-гид).
                // Определяем зону под курсором на mousemove и водим тем же tooltipEl напрямую,
                // а не изобретаем второй вид подсказки.
                function zoneTooltipText(px) {
                    const minutes = maxMinutes === 0
                        ? 0
                        : Math.min(
                            maxMinutes,
                            Math.max(0, ((px - TRACK_THUMB_SIZE / 2) / usableWidth()) * maxMinutes)
                        );
                    const value = Number(range.value);
                    const now = new Date();
                    const nowMinutes = Math.min(
                        maxMinutes,
                        Math.max(0, now.getHours() * 60 + now.getMinutes() - startMinutes)
                    );
                    if (hasLunch && minutes > lunchFrom && minutes < lunchTo) {
                        return 'Обеденный перерыв — не идёт в трек';
                    }
                    if (minutes > value && minutes < nowMinutes) {
                        return 'Уже наступило, но ещё не затрекано';
                    }
                    if (minutes <= baseMinutes) {
                        return 'Уже сохранено в Jira';
                    }
                    if (minutes <= value) {
                        return 'Будет добавлено при сохранении';
                    }
                    return 'Ещё не отработано';
                }

                // Во время перетаскивания бегунка подсказки по зонам мешают — прячем их
                // до отпускания кнопки мыши
                let draggingRange = false;
                range.addEventListener('mousedown', () => {
                    draggingRange = true;
                    tooltipEl.classList.remove('visible');
                });
                document.addEventListener('mouseup', () => {
                    draggingRange = false;
                });

                range.addEventListener('mousemove', (e) => {
                    if (draggingRange) return;
                    const rect = range.getBoundingClientRect();
                    const px = e.clientX - rect.left;
                    // Над самим круглым бегунком подсказку по зоне не показываем — там уже
                    // свой смысл (перетаскивание), а не цвет жёлоба под ним
                    if (Math.abs(px - centerOf(Number(range.value))) <= TRACK_THUMB_SIZE / 2) {
                        tooltipEl.classList.remove('visible');
                        return;
                    }
                    tooltipEl.textContent = zoneTooltipText(px);
                    const tipWidth = tooltipEl.offsetWidth;
                    const tipHeight = tooltipEl.offsetHeight;
                    const margin = 8;
                    let top = rect.bottom + 6;
                    if (top + tipHeight + margin > window.innerHeight) top = rect.top - tipHeight - 6;
                    const left = Math.min(
                        Math.max(margin, e.clientX - tipWidth / 2),
                        window.innerWidth - tipWidth - margin
                    );
                    tooltipEl.style.top = `${Math.max(margin, top)}px`;
                    tooltipEl.style.left = `${left}px`;
                    tooltipEl.classList.add('visible');
                });
                range.addEventListener('mouseleave', () => tooltipEl.classList.remove('visible'));

                // Перетаскивание, клик по треку и стрелки клавиатуры — нативные, render()
                // только доводит значение до допустимого (граница затреканного, обед)
                range.addEventListener('input', render);

                // Первый рендер — только после показа модалки: у скрытого ползунка ширина
                // равна нулю, и позиции пузырька/времени посчитались бы неверно
                requestAnimationFrame(render);
            }
        );
    }

    /** Цитаты-заглушка, если api/generate_motivation_quote.php упал (недоступен сервис цитат) —
     * модалка поздравления не должна зависеть от внешней сети */
    const MOTIVATION_QUOTES_FALLBACK = [
        'Сегодняшняя дисциплина — завтрашняя свобода.',
        'Маленькие дела, сделанные каждый день, складываются в большие результаты.',
        'Ты не обязан быть быстрым. Ты обязан не останавливаться.',
        'Каждый закрытый таск — кирпичик в стене того, что ты строишь.',
        'Хорошо сделанная работа сама себе награда — а премия просто приятный бонус.',
    ];

    /** Разметка списка задач со ссылками на Jira — общая для модалки задач за сегодня,
     * модалки поздравления (там без статуса: важен сам список, а места в окне 500×500 меньше)
     * и модалок показателей дашборда (там без времени — его в показателе нет).
     * CSS-классы остались историческими (.today-task-*), разметка при этом общая. */
    function taskListHtml(tasks, options) {
        const withStatus = !(options && options.withStatus === false);
        const withTime = !(options && options.withTime === false);
        return '<div class="today-tasks-list">' +
            tasks
                .map(
                    (task) =>
                        '<a class="today-task-item" href="' +
                        escapeHtml(safeHttpUrl(task.link)) +
                        '" target="_blank" rel="noopener">' +
                        `<span class="today-task-id">${escapeHtml(task.task_id)}</span>` +
                        `<span class="today-task-title">${escapeHtml(task.title)}</span>` +
                        (withStatus ? `<span class="today-task-status">${escapeHtml(task.status || '')}</span>` : '') +
                        (withTime ? `<span class="today-task-time">${escapeHtml(formatDuration(task.seconds))}</span>` : '') +
                        '</a>'
                )
                .join('') +
            '</div>';
    }

    /**
     * Модалка поздравления — показывается, когда трек времени за день впервые за сегодня
     * достигает нормы [worktime].daily_hours (см. вызов в openQuickTrackModal). Заработок,
     * цитата и список сегодняшних задач подгружаются параллельно и независимо друг от друга:
     * ошибка одного запроса не должна прятать другие и не должна блокировать саму модалку
     * (цитата на этот случай заменяется локальной заглушкой, а строки заработка и списка
     * задач просто не показываются).
     */
    async function showCongratsModal(totalSecondsToday) {
        showGlobalLoader();
        let earnings, quote, breakdown;
        try {
            [earnings, quote, breakdown] = await Promise.all([
                apiCall('../api/calc_earnings.php', { seconds: totalSecondsToday }).catch(() => null),
                apiCall('../api/generate_motivation_quote.php', {}).catch(() => null),
                apiCall('../api/today_time_spent_breakdown.php', ensureTaskPayload()).catch(() => null),
            ]);
        } finally {
            hideGlobalLoader();
        }

        const earningsHtml = earnings && earnings.earnings > 0
            ? `<p class="congrats-earnings">Ты заработал сегодня <strong>+${escapeHtml(formatMoney(earnings.earnings, earnings.currency_label))}</strong> 💸</p>`
            : '';
        const quoteText = (quote && quote.quote) ||
            MOTIVATION_QUOTES_FALLBACK[Math.floor(Math.random() * MOTIVATION_QUOTES_FALLBACK.length)];
        const quoteAuthorHtml = quote && quote.quote && quote.author
            ? `<span class="congrats-quote-author">— ${escapeHtml(quote.author)}</span>`
            : '';

        // Итог считаем по самой разбивке, а не по totalSecondsToday: так сумма всегда сходится
        // с показанным списком, даже если между треком и запросом разбивки время изменилось
        const todayTasks = (breakdown && breakdown.tasks) || [];
        const tasksSeconds = todayTasks.reduce((sum, task) => sum + (task.seconds || 0), 0);
        const tasksHtml = todayTasks.length
            ? '<div class="congrats-tasks">' +
                `<p class="congrats-tasks-title">Задачи за сегодня: ${todayTasks.length} · ${escapeHtml(formatDuration(tasksSeconds))}</p>` +
                taskListHtml(todayTasks, { withStatus: false }) +
                '</div>'
            : '';

        await showModal(
            '🎉 Отличная работа! 🎉',
            '<div class="congrats-modal">' +
                '<div class="congrats-emoji">🎊🥳🎊</div>' +
                '<p class="congrats-text">Ты сегодня хорошо поработал!</p>' +
                earningsHtml +
                tasksHtml +
                `<p class="congrats-quote">«${escapeHtml(quoteText)}»${quoteAuthorHtml}</p>` +
                '</div>',
            [{ label: 'Отдыхать', primary: true, value: true }]
        );
    }

    trackTimeBtn.addEventListener('click', async () => {
        // Затреканное за сегодня перечитываем перед показом: от него считается и подсветка
        // относительно нормы, и то, сколько добавится при сохранении
        setButtonLoading(trackTimeBtn, true);
        let alreadySeconds = 0;
        try {
            const data = await apiCall('../api/today_time_spent.php', ensureTaskPayload());
            alreadySeconds = data.time_spent_seconds || 0;
            applyTodayTimeSpent(alreadySeconds);
        } catch (e) {
            showToast(e.message || 'Не удалось получить затреканное сегодня время');
            return;
        } finally {
            setButtonLoading(trackTimeBtn, false);
        }
        await openQuickTrackModal(alreadySeconds, {
            submit: async (minutes) => {
                await apiCall('../api/log_time_quick.php', { task_id: state.task.id, minutes });
                showToast(`В Jira затрекано ${formatDuration(minutes * 60)}`);
            },
        });
    });

    todayTasksBtn.addEventListener('click', async () => {
        setButtonLoading(todayTasksBtn, true);
        let tasks = [];
        try {
            const data = await apiCall('../api/today_time_spent_breakdown.php', ensureTaskPayload());
            tasks = data.tasks || [];
        } catch (e) {
            showToast(e.message || 'Не удалось получить список задач за сегодня');
            return;
        } finally {
            setButtonLoading(todayTasksBtn, false);
        }

        const bodyHtml = '<div class="today-tasks-modal">' +
            (tasks.length === 0
                ? '<p class="today-tasks-empty">Сегодня время ещё не затрекано.</p>'
                : taskListHtml(tasks)) +
            '</div>';

        const title = tasks.length === 0 ? 'Задачи за сегодня 🍯' : `Задачи за сегодня (${tasks.length}) 🍯`;
        await showModal(title, bodyHtml, [{ label: 'Закрыть', primary: true }]);
    });

    /** Возврат к окну — момент «я вернулся из Jira, где мог затрекать время руками»,
     * но переключаются между окнами часто, а время в Jira так часто не меняется:
     * повторный запрос не чаще раза в BACKGROUND_REFRESH_MS (слушатели — в инициализации) */
    function refreshTodayTimeSpentOnReturn() {
        if (Date.now() - todayTimeLoadedAt < BACKGROUND_REFRESH_MS) return;
        loadTodayTimeSpent();
    }

    // Клик по самому индикатору — явный запрос пользователя, поэтому идёт мимо троттлинга
    todayTimeEl.addEventListener('click', () => loadTodayTimeSpent());

    // Давность обновления пересчитывается в момент наведения — так подсказка всегда свежая
    // и не нужен таймер, тикающий в фоне ради текста, который почти никто не смотрит
    todayTimeEl.addEventListener('mouseenter', () => {
        todayTimeEl.dataset.tooltip = `Затрекано времени (обновлено ${formatAgo(todayTimeLoadedAt)})`;
    });

    // ==================== Дашборд показателей (экран ввода ссылки) ====================

    /**
     * Показатели дашборда: карта `ключ ответа api/dashboard.php → как его показать`.
     * Оба показателя считаются одинаково (задачи на мне, висящие в статусе дольше порога
     * рабочих часов) и отличаются только id элементов, подписью и заголовком модалки —
     * поэтому рендер, тултип и список задач у них общие. Новый показатель = ещё одна запись
     * здесь плюс плитка в public/index.php, править код ниже не требуется.
     */
    const DASHBOARD_METRICS = [
        {
            key: 'stale_pull_requests',
            id: 'metric-stale-pr',
            // Подпись зависит от порога часов — он приходит с сервера, на фронте не зашит
            label: (metric) => `Зависшие PR > ${metric.hours} ч`,
            modalTitle: 'Зависшие PR',
            modalIcon: '⏳',
        },
        {
            key: 'stale_blocked',
            id: 'metric-stale-blocked',
            label: (metric) => `Blocked > ${metric.hours} ч`,
            modalTitle: 'Зависшие Blocked',
            modalIcon: '🚧',
        },
    ].map((view) => ({
        ...view,
        el: document.getElementById(view.id),
        valueEl: document.getElementById(`${view.id}-value`),
        labelEl: document.getElementById(`${view.id}-label`),
        /** Последние полученные данные показателя — из них берётся список задач для модалки
         * по клику, чтобы не дёргать Jira второй раз за теми же данными */
        data: null,
    }));

    let dashboardLoadedAt = 0;
    let dashboardLoading = false;

    /** Короткие названия дней недели по номеру ISO-8601 (1 = Пн) — для подписи нерабочих дней */
    const WEEKDAY_SHORT = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    /**
     * «(Сб, Вс не считаем)» — какие дни недели не идут в счёт часов «зависания». Список
     * приходит с сервера ([worktime].non_working_days), поэтому текст честен и при другой
     * настройке: дни не заданы — приписки нет вообще.
     */
    function daysOffNote(metric) {
        const days = (metric && metric.non_working_weekdays) || [];
        const names = days.map((day) => WEEKDAY_SHORT[day - 1]).filter(Boolean);

        return names.length === 0 ? '' : ` (${names.join(', ')} не считаем)`;
    }

    /**
     * Красная точка на кнопке возврата к экрану ввода ссылки: внутри задачи дашборд не виден,
     * а показатели обновляются в фоне — иначе о ненулевом показателе никак не узнать, не выйдя
     * из задачи. Считается по всем показателям ответа (у каждого есть `count`), поэтому новый
     * показатель попадает в точку сам, без правок здесь.
     */
    function updateDashboardAlert(data) {
        const hasAlert = Object.values(data || {})
            .some((metric) => metric && Number(metric.count) > 0);
        changeTaskBtn.classList.toggle('has-alert', hasAlert);
    }

    function applyDashboard(data) {
        dashboardLoadedAt = Date.now();
        dashboardEl.classList.remove('loading');
        updateDashboardAlert(data);

        DASHBOARD_METRICS.forEach((view) => {
            view.data = data[view.key] || null;
            if (!view.data) return;

            view.valueEl.textContent = String(view.data.count || 0);
            // Порог, название статуса и нерабочие дни приходят с сервера (DashboardService,
            // [atlassian]/[worktime]/[dashboard] в конфиге) — на фронте их не зашиваем
            view.labelEl.textContent = view.label(view.data);
            view.el.dataset.tooltip =
                `Задачи в статусе «${view.data.status}» дольше ${view.data.hours} ч`
                + daysOffNote(view.data);
        });
    }

    /**
     * Подтягивает показатели дашборда. Как и индикатор затреканного времени, ничего не
     * блокирует и при ошибке (нет интеграции с Jira, недоступна сеть) просто скрывает блок —
     * показывать в нём тогда нечего.
     */
    async function loadDashboard() {
        if (dashboardLoading) return;
        dashboardLoading = true;
        dashboardEl.classList.remove('hidden');
        setButtonLoading(dashboardRefreshBtn, true);
        try {
            applyDashboard(await apiCall('../api/dashboard.php', {}));
        } catch (e) {
            dashboardEl.classList.add('hidden');
            updateDashboardAlert(null);
        } finally {
            setButtonLoading(dashboardRefreshBtn, false);
            dashboardLoading = false;
        }
    }

    /** Возврат к окну — тот же приём и тот же троттлинг, что у индикатора времени: PR
     * ревьювят и мерджат в другой вкладке, поэтому показатели к возвращению уже устарели */
    function refreshDashboardOnReturn() {
        if (Date.now() - dashboardLoadedAt < BACKGROUND_REFRESH_MS) return;
        loadDashboard();
    }

    // Одна кнопка актуализации на весь блок — она перезапрашивает api/dashboard.php целиком,
    // то есть все показатели сразу
    dashboardRefreshBtn.addEventListener('click', () => loadDashboard());

    // Давность обновления пересчитывается в момент наведения — как у индикатора времени
    dashboardRefreshBtn.addEventListener('mouseenter', () => {
        dashboardRefreshBtn.dataset.tooltip = dashboardLoadedAt === 0
            ? 'Обновить показатели'
            : `Обновить показатели (обновлено ${formatAgo(dashboardLoadedAt)})`;
    });

    // Клик по показателю — список задач за числом, каждая строка ведёт на задачу в Jira
    DASHBOARD_METRICS.forEach((view) => {
        view.el.addEventListener('click', async () => {
            const tasks = (view.data && view.data.tasks) || [];
            const hours = (view.data && view.data.hours) || 0;
            const status = (view.data && view.data.status) || '';

            const bodyHtml = '<div class="today-tasks-modal">' +
                (tasks.length === 0
                    ? `<p class="today-tasks-empty">Нет задач, зависших в статусе «${escapeHtml(status)}» дольше ${hours} ч${escapeHtml(daysOffNote(view.data))}.</p>`
                    : taskListHtml(tasks, { withStatus: false, withTime: false })) +
                '</div>';

            const title = tasks.length === 0
                ? `${view.modalTitle} ${view.modalIcon}`
                : `${view.modalTitle}: ${tasks.length} ${view.modalIcon}`;
            await showModal(title, bodyHtml, [{ label: 'Закрыть', primary: true }]);
        });
    });

    // ==================== Инициализация ====================

    initTheme();
    loadTodayTimeSpent();
    loadDashboard();
    loadClaudeSettings();

    /** Возврат к окну обновляет и индикатор времени, и показатели дашборда — у каждого свой
     * троттлинг, но событие и проверка видимости общие */
    function refreshOnReturn() {
        if (document.visibilityState !== 'visible') return;
        refreshTodayTimeSpentOnReturn();
        refreshDashboardOnReturn();
    }

    // Оба события нужны: visibilitychange ловит возврат к свёрнутому окну/неактивной вкладке,
    // а focus — переход в другое приложение (окно при этом остаётся visible, и visibilitychange молчит)
    document.addEventListener('visibilitychange', refreshOnReturn);
    window.addEventListener('focus', refreshOnReturn);

    const savedLink = localStorage.getItem(TASK_LINK_STORAGE_KEY);
    if (savedLink) {
        linkScreen.classList.add('hidden'); // прячем экран ввода на время подгрузки сохранённой задачи
        // Оба экрана скрыты до ответа Jira — без прелоадера пользователь увидит пустой экран
        showGlobalLoader();
        restoreTask(savedLink).finally(hideGlobalLoader);
    } else {
        showLinkScreen();
    }
})();
