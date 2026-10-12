#!/usr/bin/env bash
set -euo pipefail
# All tests run offline; a fixture replaces gh. No GitHub token is needed.
root="$(cd "$(dirname "$0")/.." && pwd)"
mock="$(mktemp -d)"
trap 'rm -rf "$mock"' EXIT

cat > "$mock/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" = project ]; then
  cat <<'JSON'
{"totalCount":7,"items":[
{"status":"Ready","priority":"P0 - Critique","content":{"type":"Issue","url":"https://github.com/xdubois-57/scoutmagic/issues/5","title":"Dependent P0"}},
{"status":"Ready","priority":"P2 - Normale","content":{"type":"Issue","url":"https://github.com/xdubois-57/scoutmagic/issues/2","title":"Blocks P0"}},
{"status":"Ready","priority":"P1 - Haute","content":{"type":"Issue","url":"https://github.com/xdubois-57/scoutmagic/issues/3","title":"Independent P1"}},
{"status":"Ready","priority":"P2","content":{"type":"Issue","url":"https://github.com/xdubois-57/scoutmagic/issues/4","title":"Blocks P0 via 2"}},
{"status":"Todo","priority":"P0","content":{"type":"Issue","url":"https://github.com/xdubois-57/scoutmagic/issues/9","title":"Not Ready"}},
{"status":"Ready","priority":"P0","content":{"type":"Issue","url":"https://github.com/xdubois-57/scoutmagic/issues/10","title":"Closed"}},
{"status":"Ready","priority":"P0","content":{"type":"Issue","url":"https://github.com/other/repo/issues/11","title":"Foreign"}}
]}
JSON
  exit 0
fi
case "$*" in
  *issues/10\ --jq*) echo closed ;;
  *--jq*) echo open ;;
  *issues/5/dependencies/blocked_by*) echo '[[{"number":2,"state":"open"}]]' ;;
  *issues/2/dependencies/blocked_by*) echo '[[{"number":4,"state":"open"}]]' ;;
  *dependencies/blocked_by*) echo '[[]]' ;;
  *) echo "Unexpected request $*" >&2; exit 1 ;;
esac
MOCK
chmod +x "$mock/gh"

output="$(PATH="$mock:$PATH" bash "$root/scripts/backlog-ready-queue.sh")"
# 4 is elevated above P1 because it is a transitively required P0 blocker.
printf '%s' "$output" | jq -e '[.ready[].number] == [4,3] and [.blocked[].number] == [2,5]' >/dev/null
printf '%s' "$output" | jq -e '.ready[0].effective_rank == 0 and .ready[0].unlocks == 1' >/dev/null

# Auth errors must never silently fall back to status:accepted labels.
cat > "$mock/gh" <<'MOCK'
#!/usr/bin/env bash
exit 1
MOCK
chmod +x "$mock/gh"
if PATH="$mock:$PATH" bash "$root/scripts/backlog-ready-queue.sh" >/dev/null 2>&1; then
  echo "A Project read failure was mistaken for an empty queue." >&2
  exit 1
fi
echo "Backlog Ready queue tests passed."
