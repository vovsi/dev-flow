<?php

declare(strict_types=1);

namespace App;

/**
 * Блок стандартных секций описания задачи (Results/Testing/…): текст для записи в Jira,
 * поиск уже дописанного блока в описании и его замена отредактированным текстом. Всё это
 * живёт в одном месте намеренно — список секций у операций общий, разъехавшись, они начали
 * бы плодить дубли в описании.
 */
final class JiraDescriptionService
{
    /** Секции, в которые дописываются пункты, введённые в других шагах чек-листа (см. withItems) */
    public const SECTION_DATABASE = 'Database';
    public const SECTION_CONFIG = 'Config';
    public const SECTION_PULL_REQUESTS = 'Pull Requests';

    /**
     * Разбиение текста на строки. Именно перечисление переводов строки, а не `\R`: без
     * модификатора `u` тот считает переводом строки и одиночный байт 0x85 (U+0085 NEL) —
     * а это второй байт, например, кириллической «х» (D1 85), то есть описание с ней
     * разрезалось посреди символа и ответ эндпоинта перестал быть валидным UTF-8 (json_encode
     * отдавал false → пустое тело ответа). Модификатор `u` тут не годится: описание приходит
     * из Jira как есть, и на битой последовательности preg_* вернул бы false вместо текста.
     */
    private const LINE_BREAK_PATTERN = '~\r\n|\r|\n~';

    /**
     * Пункт нумерованного списка внутри секции: отступ, маркер, текст. Маркеров два вида, и
     * оба реальные — «#» пишет наш шаблон (template()) и сама wiki-разметка Jira, а «1. »
     * остаётся в описаниях задач, блок которых был дописан прежним шаблоном. Понимать нужно
     * оба: иначе чужой список не считался бы списком вовсе, и введённая ссылка на PR вставала
     * бы отдельной строкой рядом с ним.
     */
    private const ITEM_LINE_PATTERN = '~^(\s*)(\d+[.)]|#+)\s*(.*)$~';

    /** Текст «пустого» пункта: шаблонный пункт без текста и прочерк вместо него */
    private const ITEM_PLACEHOLDERS = ['', '-', '--', '–', '—'];

    /** Пункт, который целиком состоит из одной ссылки, — такой оформляется smart-link'ом */
    private const ITEM_URL_PATTERN = '~^https?://\S+$~';

    /**
     * Секции блока и «пустой» пункт каждой; порядок = порядок в описании задачи. Маркер
     * пункта — «#», нумерованный список самой wiki-разметки Jira: так блок выглядит в задаче
     * так же, как если бы список набрали в Jira руками. У Database и Config вместо пустого
     * пункта прочерк — в этих секциях чаще всего писать нечего, и прочерк отличает «ничего
     * делать не надо» от «забыли заполнить».
     */
    private const SECTIONS = [
        'Results' => '# ',
        'Testing' => '# ',
        self::SECTION_DATABASE => '# -',
        self::SECTION_CONFIG => '# -',
        self::SECTION_PULL_REQUESTS => '#',
    ];

    /**
     * Текст блока для записи в Jira. Разметка — wiki (*жирный*), а не HTML: описание пишется
     * через REST API v2, который сам конвертирует wiki-строку в формат задачи, а HTML показал
     * бы тегами как есть.
     */
    public static function template(): string
    {
        $blocks = [];
        foreach (self::SECTIONS as $section => $item) {
            $blocks[] = "*{$section}*\n\n{$item}";
        }

        return implode("\n\n\n", $blocks);
    }

    /** Есть ли секции блока в описании задачи */
    public static function hasSections(?string $description): bool
    {
        return self::sectionsOffset($description) !== null;
    }

    /**
     * Уже дописанный в описание блок секций — от первого заголовка секции и до конца описания
     * (блок всегда дописывается в конец, поэтому это хвост), либо null, если блока там нет.
     * Возвращается как есть, исходной разметкой: этот же текст пользователь правит и пишет
     * обратно в Jira.
     */
    public static function extractSections(?string $description): ?string
    {
        $offset = self::sectionsOffset($description);

        return $offset === null ? null : rtrim(substr((string) $description, $offset));
    }

    /**
     * Описание с заменённым блоком секций: текст выше первого заголовка секции (описание от
     * постановщика) сохраняется, хвост заменяется на $sections. Блока в описании нет —
     * $sections просто дописывается в конец.
     */
    public static function replaceSections(string $description, string $sections): string
    {
        $offset = self::sectionsOffset($description);
        $head = rtrim($offset === null ? $description : substr($description, 0, $offset));
        $sections = trim($sections);

        if ($head === '') {
            return $sections;
        }

        return $sections === '' ? $head : $head . "\n\n" . $sections;
    }

    /**
     * Блок секций с дописанными пунктами: карта «секция → текст пункта(ов)», введённый в других
     * шагах чек-листа (ссылка на PR, что сделать с базой, что с конфигом). Каждая непустая
     * строка текста становится отдельным номером списка своей секции: под первым номером ещё
     * ничего нет (пустой пункт шаблона или блока в описании не было вовсе) — пункт встаёт туда, под
     * ним уже что-то стоит — пункт добавляется следующим номером ниже, не затирая его (у
     * мультирепо-задачи в секции несколько PR, у выливки — несколько шагов). Уже упомянутый в
     * секции текст второй раз не добавляется, секции нет в блоке — блок не меняется.
     *
     * В Jira при этом ничего не пишется — текст только показывается в поле модалки, сохранять
     * его в описание или нет, решает пользователь.
     *
     * @param array<string, string> $itemsBySection
     */
    public static function withItems(string $sections, array $itemsBySection): string
    {
        if (trim($sections) === '') {
            return $sections;
        }

        $lines = preg_split(self::LINE_BREAK_PATTERN, $sections) ?: [];
        foreach ($itemsBySection as $section => $text) {
            foreach (self::splitItems($text) as $item) {
                $lines = self::appendToSection($lines, $section, $item);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Введённый пользователем текст → пункты списка: по одному на непустую строку. Своя
     * нумерация в начале строки убирается — номер в списке секции проставляется заново, иначе
     * при дописывании в непустую секцию номера пошли бы вразнобой.
     *
     * @return string[]
     */
    private static function splitItems(string $text): array
    {
        $items = [];
        foreach (preg_split(self::LINE_BREAK_PATTERN, $text) ?: [] as $line) {
            $line = trim(preg_replace('~^\s*(?:\d+[.)]|#+)\s*~', '', $line) ?? $line);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items;
    }

    /**
     * Пункт в том виде, в каком он пишется в описание: ссылка — wiki-разметкой smart-link
     * (`[url|url|smart-link]`), тем же способом, каким Jira сама оформляет вставленную в
     * описание ссылку. Иначе дописанная нами ссылка на PR оставалась в списке сырым текстом
     * рядом с уже оформленными — и выглядела, и раскрывалась в задаче по-другому. Текст, не
     * являющийся одной ссылкой (заметки о базе и конфиге, уже оформленная ссылка), не меняется.
     */
    private static function formatItem(string $item): string
    {
        return preg_match(self::ITEM_URL_PATTERN, $item) === 1
            ? "[{$item}|{$item}|smart-link]"
            : $item;
    }

    /**
     * Строки блока с дописанным в список секции пунктом (правила — см. withItems). Маркер
     * нового пункта — тот, которым секция уже размечена: «#» у wiki-списка Jira, следующий
     * номер у списка вида «1. ».
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function appendToSection(array $lines, string $section, string $item): array
    {
        $header = self::sectionHeaderIndex($lines, $section);
        if ($header === null) {
            return $lines;
        }

        $end = self::sectionEndIndex($lines, $header + 1);
        $firstEmpty = null;
        $firstEmptyLine = '';
        $lastItem = null;
        $indent = '';
        $wikiMarker = null;
        $maxNumber = 0;
        for ($i = $header + 1; $i < $end; $i++) {
            if (!preg_match(self::ITEM_LINE_PATTERN, $lines[$i], $matches)) {
                continue;
            }
            // Пункт уже в секции — второй раз его добавлять некуда
            if (str_contains($lines[$i], $item)) {
                return $lines;
            }
            $lastItem = $i;
            $indent = $matches[1];
            if (str_starts_with($matches[2], '#')) {
                // Новый пункт повторяет уже использованный в секции wiki-маркер, иначе один
                // список в описании оказался бы размечен двумя способами
                $wikiMarker ??= $matches[2];
            } else {
                $maxNumber = max($maxNumber, (int) $matches[2]);
            }
            if ($firstEmpty === null && in_array(trim($matches[3]), self::ITEM_PLACEHOLDERS, true)) {
                $firstEmpty = $i;
                $firstEmptyLine = $matches[1] . $matches[2] . ' ' . self::formatItem($item);
            }
        }

        // Пустой пункт заполняется, а не дублируется: строка собирается заново, потому что
        // заглушку-прочерк нужно заменить, а не дописать после неё
        if ($firstEmpty !== null) {
            $lines[$firstEmpty] = $firstEmptyLine;

            return $lines;
        }

        $marker = $wikiMarker ?? ($maxNumber + 1) . '.';
        array_splice(
            $lines,
            ($lastItem ?? $header) + 1,
            0,
            [$indent . $marker . ' ' . self::formatItem($item)]
        );

        return $lines;
    }

    /**
     * Блок секций с заметкой, дописанной в скобках в конец **последнего** пункта секции
     * «Pull Requests» (поле «Другое» шага «Закоммитить изменения»): это уточнение к самому
     * свежему PR, а не отдельный пункт списка, поэтому и отдельным номером не встаёт. Своего
     * пункта в секции нет, заметка пустая или уже дописана — блок не меняется.
     */
    public static function withPullRequestNote(string $sections, string $note): string
    {
        $note = self::inlineNote($note);
        if ($note === '' || trim($sections) === '') {
            return $sections;
        }

        $lines = preg_split(self::LINE_BREAK_PATTERN, $sections) ?: [];
        $header = self::sectionHeaderIndex($lines, self::SECTION_PULL_REQUESTS);
        if ($header === null) {
            return $sections;
        }

        $end = self::sectionEndIndex($lines, $header + 1);
        $last = null;
        for ($i = $header + 1; $i < $end; $i++) {
            // Пустой пункт (шаблонный или прочерк) за PR не считается: скобки с уточнением
            // без самой ссылки бессмысленны
            if (!preg_match(self::ITEM_LINE_PATTERN, $lines[$i], $matches)
                || in_array(trim($matches[3]), self::ITEM_PLACEHOLDERS, true)
            ) {
                continue;
            }
            if (str_contains($lines[$i], $note)) {
                return $sections;
            }
            $last = $i;
        }

        if ($last === null) {
            return $sections;
        }

        $lines[$last] = rtrim($lines[$last]) . " ({$note})";

        return implode("\n", $lines);
    }

    /**
     * Многострочная заметка в одну строку (строки через «; ») — она дописывается внутрь
     * скобок к пункту списка, и перевод строки разорвал бы его на два пункта.
     */
    private static function inlineNote(string $text): string
    {
        $parts = [];
        foreach (preg_split(self::LINE_BREAK_PATTERN, $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $parts[] = $line;
            }
        }

        return implode('; ', $parts);
    }

    /** Номер строки-заголовка конкретной секции среди строк блока или null, если её там нет */
    private static function sectionHeaderIndex(array $lines, string $section): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match(self::sectionLinePattern($section), $line)) {
                return $index;
            }
        }

        return null;
    }

    /** Номер строки, на которой кончается секция: заголовок следующей секции либо конец блока */
    private static function sectionEndIndex(array $lines, int $from): int
    {
        $count = count($lines);
        for ($i = $from; $i < $count; $i++) {
            if (preg_match(self::sectionLinePattern(), $lines[$i])) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * Позиция первого заголовка секции в описании (в байтах) или null, если блока нет.
     *
     * Название ищется отдельной строкой, а не подстрокой: слово «Results» вполне может
     * встретиться в тексте описания от постановщика, и это не значит, что блок уже добавлен.
     * Ищется именно смещение, а не номер строки, — по нему описание режется на «текст
     * постановщика» и «блок секций» без потери исходной разметки.
     */
    private static function sectionsOffset(?string $description): ?int
    {
        $text = (string) $description;
        if ($text === '') {
            return null;
        }

        if (!preg_match(self::sectionLinePattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return $matches[0][1];
    }

    /**
     * Строка-заголовок секции: вокруг названия может быть wiki-разметка заголовка (h3. Results)
     * или жирного (*Results*), маркер списка, а если описание пришло HTML-ом (renderedFields) —
     * ещё и теги. Без аргумента подходит любая секция блока, с аргументом — только названная.
     */
    private static function sectionLinePattern(?string $section = null): string
    {
        $names = $section !== null ? preg_quote($section, '~') : implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '~'),
            array_keys(self::SECTIONS)
        ));
        $wrap = '(?:<[^>]+>|h[1-6]\.|[ \t\r*_#|-])*';

        return "~^{$wrap}(?:{$names}){$wrap}$~mi";
    }
}
