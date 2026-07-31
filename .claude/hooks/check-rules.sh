#!/usr/bin/env bash
# SessionStart-хук: сообщает, если локальная копия правил отстала от origin.
#
# Правила лежат в соседнем репозитории (../rules внутри trexgo-workspace).
# Устаревшая копия опасна тем, что выглядит рабочей: агент уверенно следует
# процессу месячной давности, и снаружи это никак не видно.
#
# Вывод попадает в контекст сессии. Пишем фактом, а не командой: повелительные
# формулировки из хука могут быть приняты за инъекцию.
# Ничего не блокирует — при любой проблеме молча выходим с кодом 0.

set -u

# Корень рабочей папки — на уровень выше site/. Рабочая директория хука
# не гарантирована, поэтому отталкиваемся от CLAUDE_PROJECT_DIR.
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
WORKSPACE="$(cd "$PROJECT_DIR/.." 2>/dev/null && pwd)" || exit 0

# Правила лежат в корневом репозитории, а не в отдельном
[ -d "$WORKSPACE/rules" ] || exit 0
[ -d "$WORKSPACE/.git" ] || exit 0

command -v git >/dev/null 2>&1 || exit 0

# Тихо и с таймаутом: нет сети — просто выходим
git -C "$WORKSPACE" fetch --quiet --depth=1 origin >/dev/null 2>&1 || exit 0

BRANCH="$(git -C "$WORKSPACE" rev-parse --abbrev-ref HEAD 2>/dev/null)" || exit 0
[ -n "$BRANCH" ] && [ "$BRANCH" != "HEAD" ] || exit 0

BEHIND="$(git -C "$WORKSPACE" rev-list --count "HEAD..origin/$BRANCH" 2>/dev/null)" || exit 0
[ -n "$BEHIND" ] && [ "$BEHIND" -gt 0 ] 2>/dev/null || exit 0

# Отстали — сообщаем, что именно
CHANGED="$(git -C "$WORKSPACE" diff --name-only "HEAD..origin/$BRANCH" -- rules/ 2>/dev/null | head -5)"

echo "Локальная копия правил совместной работы отстаёт от origin на $BEHIND коммит(ов)."
if [ -n "$CHANGED" ]; then
  echo "Затронутые файлы правил:"
  echo "$CHANGED" | sed 's/^/  /'
fi
echo "Обновление: git -C \"$WORKSPACE\" pull, затем перезапуск сессии — правила читаются при старте."
echo "До обновления процесс в правилах может отличаться от актуального."

exit 0
