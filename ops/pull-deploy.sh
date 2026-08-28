#!/bin/bash
# Выкладка сайта: сервер сам забирает код из GitHub.
#
# Почему так, а не заливкой с раннера (замерено 28.08.2026):
#   - FTP Макхоста рвёт передачи с раннера GitHub — «426 Transfer aborted.
#     Interrupted system call». Рвётся почти каждая передача, и между
#     подходами прогресса нет, поэтому повтор всей выкладки не спасает;
#   - SamKirkland/FTP-Deploy-Action при обрыве оставляет файл усечённым
#     до нуля байт. Так на выкладке кабинета дважды обнулился .htaccess
#     и открыл наружу документы пользователей. Для сайта нулевой .htaccess
#     означает отпавший HTTPS-редирект и открытые db/, ops/, tests/, api/lib/;
#   - SSH из Actions не проходит вовсе: TCP-порт 22 открыт, но обмен ключами
#     замирает на SSH2_MSG_KEX_ECDH_REPLY. С нашей машины тот же ключ работает,
#     то есть хостинг фильтрует адреса раннеров;
#   - lftp с временными файлами и переподключениями упирается в то же самое.
#
# А обратное направление свободно: сервер тянет весь репозиторий одним
# запросом к api.github.com за 0,7 с. Поэтому раннер только дёргает
# deployhook.php, а работу делает сервер.
#
# Тот же приём, что в репозитории кабинета (lk), — разбор там же.

set -euo pipefail

REPO="${DEPLOY_REPO:-trexgo-app/trexgo-site}"
REF="${DEPLOY_REF:-main}"
TARGET="${DEPLOY_TARGET:-/home/httpd/vhosts/trexgo.ru/httpdocs}"
# production | stage. Определяет --delete и применение ops/stage-overlay/
# ниже. Значение приходит из deployhook.php, а не выбирается этим скриптом —
# он только выполняет то, что подписано и проверено в хуке.
DEPLOY_ENV="${DEPLOY_ENV:-production}"
# Отдельная рабочая папка на env: без этого одновременный запуск production
# и stage (это разные concurrency-группы в GitHub Actions, друг друга не
# ждут) делит один WORK — rm -rf при старте или в trap EXIT одного процесса
# сносит файлы, которые в этот момент читает rsync другого.
WORK="/home/httpd/vhosts/trexgo.ru/private/deploy-work-site-${DEPLOY_ENV}"
# Файл-замок на env: подписанный запрос ещё действителен 300 секунд (окно
# в deployhook.php) и теоретически может быть отправлен повторно. Без замка
# это запустило бы вторую выкладку того же окружения поверх первой — обе
# пишут в один WORK и в один TARGET. concurrency в deploy.yml/stage.yml
# защищает только запросы, прошедшие через сам Actions; замок — от прямого
# повтора запроса к deployhook.php в обход Actions.
LOCK="/home/httpd/vhosts/trexgo.ru/private/deploy-lock-${DEPLOY_ENV}"
TOKEN="${DEPLOY_TOKEN:?нет DEPLOY_TOKEN}"

# Что к сайту не относится и на хостинг не попадает. Список повторяет
# бывший exclude из deploy.yml — при правке менять оба места незачем,
# теперь он живёт только здесь.
#
# Ведущая косая черта обязательна: без неё rsync применяет шаблон на любой
# глубине, и правило "README.md" молча съело ops/vendor/README.md при первой
# же выкладке. То же ждало бы любую вложенную папку docs/ или temp/.
# Исключения по имени без пути — только для мусора, который вычищаем везде
# (.DS_Store, Thumbs.db, node_modules).
EXCLUDE=(
  "/.git" "/.gitignore" "/.gitattributes" "/.github" "/.claude"
  "/CLAUDE.md" "/README.md" "/AGENTS.md"
  "/docs" "/config-snapshots" "/temp"
  "/vercel.json"
  "node_modules" ".DS_Store" "Thumbs.db"
)

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# Явный список вместо if/else: любое незнакомое значение (опечатка,
# будущий третий env) должно остановить выкладку, а не тихо съехать
# в одну из веток по умолчанию — от этого зависит, что --delete и
# оверлей никогда не применятся к production по ошибке.
case "$DEPLOY_ENV" in
  production|stage) ;;
  *) log "ОШИБКА: неизвестный DEPLOY_ENV='$DEPLOY_ENV' (ожидались production или stage)"; exit 1 ;;
esac

exec 9>"$LOCK"
if ! flock -n 9; then
  log "ОШИБКА: выкладка $DEPLOY_ENV уже идёт (замок $LOCK занят)"
  exit 1
fi

rm -rf "$WORK"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT

log "Скачиваю $REPO@$REF"
code=$(curl -sS -L \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/vnd.github+json" \
  -o "$WORK/repo.tar.gz" -w '%{http_code}' --max-time 120 \
  "https://api.github.com/repos/$REPO/tarball/$REF")

if [ "$code" != "200" ]; then
  log "ОШИБКА: GitHub ответил $code"
  exit 1
fi

tar xzf "$WORK/repo.tar.gz" -C "$WORK"
# Имя корневой папки архива GitHub заранее неизвестно (в нём хеш коммита),
# поэтому находим её среди распакованного, а не угадываем маской: маска '*-*'
# ловила и вложенные папки и уводила выкладку не туда.
#
# Через `tar tzf | head -1` не делаем: head закрывает канал, tar получает
# SIGPIPE, и при `set -o pipefail` весь скрипт молча падает с «tar: write error».
SRC=""
for d in "$WORK"/*/; do
  [ -d "$d" ] || continue
  SRC="${d%/}"
  break
done
[ -n "$SRC" ] || { log "ОШИБКА: в архиве нет папки репозитория"; exit 1; }

# Проверка целостности до того, как что-то трогать на бою: архив либо
# распаковался целиком, либо мы не выкладываем ничего. Это то, чего
# принципиально не мог дать пофайловый FTP.
for f in .htaccess index.html styles.css script.js api/leads.php; do
  [ -s "$SRC/$f" ] || { log "ОШИБКА: $f отсутствует или пуст в архиве"; exit 1; }
done

args=(-a --no-perms --no-owner --no-group --delay-updates)
for e in "${EXCLUDE[@]}"; do args+=(--exclude="$e"); done
dry=()
[ "${DEPLOY_DRY_RUN:-}" = "1" ] && dry=(--dry-run --itemize-changes)
# "${dry[@]}" на пустом массиве падает под `set -u` на bash < 4.4 (сервер —
# 4.1) с «unbound variable»: старый bash не отличает пустой массив от
# неустановленной переменной при таком раскрытии. Везде ниже используется
# "${dry[@]+"${dry[@]}"}" — раскрывается в ничто, если dry пуст, и в элементы
# массива иначе; работает и на старом, и на новом bash.

if [ "$DEPLOY_ENV" = "stage" ]; then
  # Раньше: rsync клал в TARGET боевое дерево (настоящий api/leads.php,
  # .htaccess без пароля), и только следующей командой скрипта поверх
  # накладывался stage-overlay. Между этими двумя шагами — а на реальной
  # передаче файлов это не мгновение, а секунды — stage.trexgo.ru отдавал
  # боевой сайт без пароля и с рабочей формой. При обрыве ровно между
  # шагами это состояние оставалось надолго.
  #
  # Чинится не флагом rsync (атомарной подмены каталога средствами панели
  # хостинга проверить нельзя — неизвестно, переживёт ли TARGET замену на
  # symlink), а порядком: overlay накладывается на копию ДО того, как
  # что-либо попадает в TARGET. Единственный rsync, который трогает TARGET,
  # запускается уже с готовым результатом — .htaccess в нём с первого байта
  # содержит Basic Auth, api/leads.php с первого байта — заглушка.
  FINAL="$WORK/final"
  mkdir -p "$FINAL"
  log "Собираю stage во временной папке"
  rsync "${args[@]}" "$SRC/" "$FINAL/"

  OVERLAY="$SRC/ops/stage-overlay"
  [ -d "$OVERLAY" ] || { log "ОШИБКА: DEPLOY_ENV=stage, но ops/stage-overlay отсутствует в архиве"; exit 1; }

  log "Накладываю stage-overlay на временную копию"
  cp "$OVERLAY/robots.txt" "$FINAL/robots.txt"
  cat "$OVERLAY/htaccess-append" >> "$FINAL/.htaccess"
  mkdir -p "$FINAL/api"
  cp "$OVERLAY/api/leads.php" "$FINAL/api/leads.php"
  cp "$OVERLAY/stage-badge.js" "$FINAL/stage-badge.js"

  shortsha=$(basename "$SRC" | sed 's/.*-//' | cut -c1-7)
  printf '{"ref":"%s","commit":"%s","deployed_at":"%s"}\n' \
    "$REF" "$shortsha" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$FINAL/stage-meta.json"

  # sed -i без суффикса бэкапа: GNU и BSD sed расходятся именно в этом
  # флаге, а править файлы во временной папке безопасно — второй копии
  # не остаётся и трогать нечего, кроме них самих.
  #
  # Вырезаем блок Яндекс.Метрики целиком (не просто меняем ID на другой
  # счётчик — заводить второй счётчик ради тестового трафика ни к чему):
  # без этого на stage работал тот же боевой счётчик, что и на production,
  # и каждый визит на stage (плюс clickmap/webvisor) писался в боевую
  # аналитику. Адресный диапазон sed через `\%...%` вместо `/.../` —
  # в самой метке есть `/`, и с `/`-разделителем её пришлось бы экранировать.
  find "$FINAL" -maxdepth 1 -name '*.html' -exec \
    sed -i '\%<!-- Yandex.Metrika counter -->%,\%<!-- /Yandex.Metrika counter -->%d' {} +

  # Без счётчика reachMetrikaGoal() в script.js — единственный оставшийся
  # источник обращений к боевой Метрике с stage — молча ничего не делает:
  # window.ym там просто не появится (typeof window.ym === 'function' — false).

  find "$FINAL" -maxdepth 1 -name '*.html' -exec \
    sed -i 's#</body>#<script src="/stage-badge.js" defer></script></body>#' {} +

  # Fail-closed gate: overlay приходит из выкладываемой фиче-ветки, а не
  # из доверенного места — пустой htaccess-append, откатанная заглушка
  # api/leads.php или переименованные маркеры Метрики (sed выше отработает
  # "успешно" и на них, просто ничего не вырежет) ушли бы в TARGET
  # незамеченными. Проверки stage.yml увидят проблему только ПОСЛЕ
  # публикации и ничего не откатят — здесь же TARGET ещё не тронут.
  gate_fail=0
  gate() { log "ОШИБКА gate: $*"; gate_fail=1; }

  # Якорные regex на начало строки (после пробелов), а не поиск подстроки
  # где угодно: закомментированная "# AuthUserFile ..." или "# Require
  # valid-user" раньше проходила бы gate, хотя Apache её не применяет.
  grep -qE '^[[:space:]]*AuthUserFile[[:space:]]' "$FINAL/.htaccess" \
    || gate ".htaccess без активной AuthUserFile — Basic Auth не наложился"
  grep -qE '^[[:space:]]*Require[[:space:]]+valid-user[[:space:]]*$' "$FINAL/.htaccess" \
    || gate ".htaccess без активной Require valid-user"
  grep -qx 'Disallow: /' "$FINAL/robots.txt" || gate "robots.txt не запрещает индексацию целиком"

  # Сверка по хешу с эталоном заглушки, а не поиск строки внутри файла:
  # искомая подстрока (даже якорная) всё ещё берётся из того же архива,
  # что и сам файл — в закомментированном виде она проходила бы проверку,
  # даже если api/leads.php на самом деле боевой обработчик. Эталон лежит
  # прямо здесь, в pull-deploy.sh, который сам требует ручной укладки на
  # сервер при любой правке (см. шапку файла и rules/deploy.md) — то есть
  # не может быть подменён одной лишь правкой фиче-ветки.
  STAGE_STUB_SHA256="4e88105b9a5003d482bc85757dff04254a3d93d31dbf048773443eaafb57c42a"
  actual_sha=$(sha256sum "$FINAL/api/leads.php" | cut -d' ' -f1)
  [ "$actual_sha" = "$STAGE_STUB_SHA256" ] \
    || gate "api/leads.php не совпадает по хешу с эталонной stage-заглушкой (получено $actual_sha)"

  if find "$FINAL" -maxdepth 1 -name '*.html' -print0 \
       | xargs -0 grep -l -e 'mc\.yandex\.ru' -e '111364095' -e 'Yandex\.Metrika' 2>/dev/null | grep -q .; then
    gate "боевая Яндекс.Метрика осталась в HTML после вырезания"
  fi

  [ "$gate_fail" = 0 ] || { log "ОШИБКА: инварианты stage не выполнены, TARGET не тронут"; exit 1; }

  log "Раскладываю в $TARGET (env=stage)"
  rsync "${args[@]}" --delete "${dry[@]+"${dry[@]}"}" "$FINAL/" "$TARGET/"
else
  # Для production нет overlay и нечего собирать отдельно — раскладываем
  # прямо из скачанного архива, как раньше.
  #
  # --delete намеренно нет: в веб-корне лежит и то, чего в репозитории нет
  # и не должно быть — файл подтверждения прав на сайт для Яндекса, index.html
  # и mchost.php от хостера. Раньше их берёг список файлов, который вёл
  # FTP-action; теперь бережёт отсутствие --delete. Цена — удалённый из
  # репозитория файл на сервере остаётся: убирать руками.
  #
  # Папка preview/ (сборки веток) в этот список раньше тоже входила — с
  # переходом на stage.trexgo.ru (28.08.2026) она удалена с сервера руками,
  # /preview/ в выкладке больше не участвует.
  log "Раскладываю в $TARGET (env=production)"
  rsync "${args[@]}" "${dry[@]+"${dry[@]}"}" "$SRC/" "$TARGET/"
fi

# Коммит берём из имени папки архива: .git в tarball не входит.
log "Готово, коммит $(basename "$SRC" | sed 's/.*-//' | cut -c1-7)"
