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
WORK="/home/httpd/vhosts/trexgo.ru/private/deploy-work-site"
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
# --delete намеренно нет: в веб-корне лежит и то, чего в репозитории нет и
# не должно быть — папка preview/ со сборками веток, файл подтверждения прав
# на сайт для Яндекса, index.html и mchost.php от хостера. Раньше их берёг
# список файлов, который вёл FTP-action; теперь бережёт отсутствие --delete.
# Цена — удалённый из репозитория файл на сервере остаётся: убирать руками.
[ "${DEPLOY_DRY_RUN:-}" = "1" ] && args+=(--dry-run --itemize-changes)

log "Раскладываю в $TARGET"
rsync "${args[@]}" "$SRC/" "$TARGET/"

# Коммит берём из имени папки архива: .git в tarball не входит.
log "Готово, коммит $(basename "$SRC" | sed 's/.*-//' | cut -c1-7)"
