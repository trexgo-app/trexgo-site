# AGENTS.md

Shared instructions for Codex and Claude Code.

## Workspace rules

From any checkout or worktree, locate the primary checkout with
`git rev-parse --path-format=absolute --git-common-dir`: in these non-bare
repositories its parent is the primary checkout. Find `rules/collaboration.md`
in that checkout or its parent; that directory is the workspace. Resolve
workspace paths below against it, not against the current worktree.
Before editing, read collaboration.md and every rule file it lists. When
reading through a file tool, explicitly follow its `@...` imports.
If the workspace rules are unavailable, stop before editing and explain why.

## Карта проекта

Эта папка — один из репозиториев, лежащих рядом в общей рабочей папке:

| Папка | Что внутри | Видимость |
|---|---|---|
| `site/` | лендинг trexgo.ru — **эта папка** | **публичный** |
| `rules/` | как мы работаем вдвоём: ветки, деплой, stage, документация | приватный |
| `lk/` | бэкенд личного кабинета, PHP 8.1 + MySQL на Макхосте | приватный |
| `knowledge/` | вики по ЭПД, конкуренты, черновики текстов, стратегия | приватный |

Пути `../rules/` и `../knowledge/` ниже показаны для основного клона.
Из worktree разрешай их относительно найденного workspace по процедуре выше.

Факт про ЭТрН, ЭПЛ, титулы или регулирование — в `../knowledge/wiki/etrn/`, не выдумывать.
Что сознательно не вынесено на лендинг и почему — `../knowledge/content/landing/sources-and-approach.md`,
читать до правки текстов.

**Движение фактов одностороннее: из базы знаний в сайт.** Ничего приватного —
стратегии, гипотез, рисков, разбора конкурентов — здесь оказаться не должно.
Репозитории независимы: коммит сюда не попадает в базу знаний и наоборот. Ссылки вида
`../knowledge/...` работают в редакторе, но не на GitHub — в тексты сайта их не вставлять.

## Что это за репозиторий

Лендинг TrexGo (https://trexgo.ru) — статический сайт из HTML/CSS/JS, без фреймворка и сборки.
Продукт: личный кабинет для выпуска электронных транспортных накладных (ЭТрН/ЭПЛ) с передачей
в ГИС ЭПД через партнёра — Платформу ОФД.

Весь контент — на русском языке.

**Здесь только лендинг.** Личный кабинет — отдельный репозиторий `trexgo-lk`. Не смешивать:
код кабинета, работа с API Платформы ОФД, база данных сюда не попадают никогда.

Текущее состояние проекта, адреса, проверки — [knowledge/documentation/site/operations/deployment/index.md](https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/operations/deployment/index.md).
Конфигурация хостинга — [knowledge/documentation/site/config-snapshots/mchost.md](https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/config-snapshots/mchost.md).

## Правила работы

Процесс — ветки, stage, деплой, что решается вдвоём — в правилах workspace.
Перед изменениями прочитай их по процедуре выше.

Одно правило повторено намеренно, потому что цена ошибки необратима:
**репозиторий публичный.** Ни паролей, ни ключей, ни токенов в коде. Доступы —
в GitHub Secrets.

## Gotchas

- **Правки показывать локально, а не пушить ради проверки.** Сайт статический:
  `python -m http.server 8000` из этой папки → `http://127.0.0.1:8000/`. Stage на
  хостинге — для финальной проверки; приёмка владельца нужна, когда это
  зафиксировано в плане. Порядок приёмки — `rules/workflow.md`. Что
  локально не проверяется — [knowledge/documentation/site/operations/deployment/index.md](https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/operations/deployment/index.md), «Локальный просмотр».
- **Репозиторий — источник правды.** Production-выкладка обновляет файлы репозитория,
  но не удаляет посторонние серверные файлы; stage использует `--delete`.
  Правки напрямую по FTP затрутся при следующем push. Канон — `../rules/deploy.md`.
- **Версия Apache неизвестна**, хостер её скрывает. В `.htaccess` писать только синтаксис,
  работающий и на 2.2, и на 2.4: `RewriteRule ... [F,L]`, не `Require all denied`.
- **Путь `/.well-known/` не блокировать** — через него Let's Encrypt продлевает сертификат.
- **PHP работает как FastCGI** — его настройки через `.htaccess` не меняются, только
  панель или `.user.ini`.
- **Кодировка базы по умолчанию cp1251.** Таблицы создавать явно в `utf8mb4`,
  иначе кириллица и эмодзи побьются.
- **Сертификат wildcard `*.trexgo.ru`** — поддомены первого уровня покрыты
  (в том числе `stage.trexgo.ru`), второго — нет. Сроки — [knowledge/documentation/site/config-snapshots/mchost.md](https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/config-snapshots/mchost.md).
- Форма заявки на stage — заглушка (`ops/stage-overlay/api/leads.php`), на боевом
  сайте работает по-настоящему.
- Блок Яндекс.Метрики на stage вырезается из HTML при выкладке (`pull-deploy.sh`) —
  просмотры и тестовые заявки не должны попадать в боевой счётчик. Номер счётчика
  и список целей — [knowledge/documentation/site/operations/deployment/index.md](https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/operations/deployment/index.md), «Форма заявки и аналитика».
- **Ссылки в HTML только относительные** (`privacy.html`, не `/privacy.html`) — иначе
  со stage они уводят на боевой сайт.
- **`stage.trexgo.ru` — рабочий стенд под Basic Auth**, закрыт от поисковиков
  (`ops/stage-overlay/`). Не добавлять в `sitemap.xml`, не ссылаться с боевых страниц.

## Тон и содержание

Аудитория — перевозчики, логисты, грузоотправители: люди занятые и практичные.

- Пишем по делу, без маркетингового шума и восклицаний.
- Не обещаем того, чего продукт не делает. Не выдумываем цифры, отзывы, клиентов и метрики —
  если нужен факт, спрашиваем у владельца.
- Термины отрасли (ЭТрН, ЭПЛ, ГИС ЭПД, титул, УКЭП) употребляем корректно, при первом
  упоминании расшифровываем.

## Структура

Страницы лежат плоско в корне: `index.html`, `waybills.html`, `perevozchiki.html`,
`gruzootpravitelyam.html`, `gruzopoluchatelyam.html`, `blog.html`, `article-etrn.html`,
`ai-analytics.html`, `privacy.html`. Картинки — в папках рядом.

Добавление страницы — по `rules/code.md`, раздел «Новая страница»;
согласование структуры — по `rules/workflow.md`.

Две оси навигации — продуктовая и ролевая («Кому подходит»), устройство раздела,
общие `role-landing.css` и `role-landing.js` для ролевых лендингов и почему их пока
нет в `sitemap.xml` — в [knowledge/documentation/site/operations/deployment/index.md](https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/operations/deployment/index.md).

## Чего не делать

Список — в `../rules/code.md` и `../rules/workflow.md`: фреймворк, зависимости,
юридические тексты, домен и DNS. Здесь не повторяется.

## Разработка и проверки

Start setup and checks at README.md. Read the relevant current operations guide
before changing configuration; config snapshots and development/agent-context.md
are historical evidence and must not override current rules. Keep executable
schemas, fixtures and generated contracts in this repository. Answer in Russian;
preserve unrelated edits, use isolated worktrees and inspect diff/status.
Pure documentation uses the workflow exception; runtime changes reach production
and are verified in the same session. Documentation checks remain advisory.

## Документация этого репозитория

Follow `../rules/docs.md`. Detailed documentation has one canonical home in the
private knowledge repository:
https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/index.md

Keep short setup/check instructions, executable resources and local agent rules
in site. Update the appropriate knowledge page in the same task when behavior,
configuration or operations change. Link paired repository changes and the
reviewed code revision. New detailed documents must not be added to site/docs.

Link directly to the final knowledge page. When moving a document, update its
known consumers and remove the old file; do not create redirect Markdown files
or keep a parallel tree of old addresses. Historical documentation belongs
in the corresponding archive in knowledge. Never publish private material in site.
