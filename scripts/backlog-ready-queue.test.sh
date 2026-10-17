#!/usr/bin/env bash
set -euo pipefail

# Usage: ./scripts/backlog-ready-queue.test.sh
#
# Offline contract test for scripts/backlog-ready-queue.sh: `gh` is replaced by
# a fixture-driven executable, no network and no token. Run by
# tests/Security/BacklogProjectScriptsTest.php, so a green CI run cannot have
# skipped it.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mock="$(mktemp -d)"
trap 'rm -rf "${mock}"' EXIT

REPO='xdubois-57/scoutmagic'

# node <number> <repo> <state> <status> <priority> [agent]
node() {
  jq -nc --argjson n "$1" --arg repo "$2" --arg state "$3" --arg status "$4" --arg prio "$5" --arg agent "${6:-}" '
    { id: "ITEM\($n)",
      content: {__typename: "Issue", number: $n, title: "Issue \($n)", url: "https://github.com/\($repo)/issues/\($n)",
                state: $state, stateReason: null, repository: {nameWithOwner: $repo}},
      fieldValues: {pageInfo: {hasNextPage: false}, nodes:
        ([ {name: $status, field: {name: "Status"}} ]
         + (if $prio != "" then [{name: $prio, field: {name: "Priority"}}] else [] end)
         + (if $agent != "" then [{text: $agent, field: {name: "Agent"}}] else [] end))} }'
}

page() { # page <has_next> <cursor> <nodes...>
  local has="$1" cursor="$2"; shift 2
  printf '%s\n' "$@" | jq -sc --argjson has "${has}" --arg cur "${cursor}" \
    '{data: {user: {projectV2: {id: "PROJECT", items: {pageInfo: {hasNextPage: $has, endCursor: $cur}, nodes: .}}}}}'
}

{
  page true CURSOR2 \
    "$(node 5 "${REPO}" OPEN Ready 'P0 - Critique')" \
    "$(node 2 "${REPO}" OPEN Ready 'P2 - Normale')" \
    "$(node 4 "${REPO}" OPEN Ready P2)" \
    "$(node 3 "${REPO}" OPEN Ready 'P1 - Haute')" \
    "$(node 9 "${REPO}" OPEN Todo P0)" \
    "$(node 10 "${REPO}" CLOSED Ready P0)" \
    "$(node 11 other/repo OPEN Ready P0)"
} >"${mock}/page1.json"
{
  page false '' \
    "$(node 20 "${REPO}" OPEN Ready P3)" \
    "$(node 21 "${REPO}" OPEN Todo P0)" \
    "$(node 30 "${REPO}" OPEN Ready P0)" \
    "$(node 31 "${REPO}" OPEN Todo P1)" \
    "$(node 32 "${REPO}" OPEN Ready P3)" \
    "$(node 40 "${REPO}" OPEN Ready P1)" \
    "$(node 50 "${REPO}" OPEN Ready P2)" \
    "$(node 51 "${REPO}" CLOSED Done P2)" \
    "$(node 60 "${REPO}" OPEN Ready P1)" \
    "$(node 70 "${REPO}" OPEN 'In Progress' P1 'Claude A1')" \
    "$(node 80 "${REPO}" OPEN Ready '')" \
    "$(jq -nc '{id: "DRAFT", content: {__typename: "DraftIssue"}, fieldValues: {pageInfo: {hasNextPage: false}, nodes: []}}')"
} >"${mock}/page2.json"

# blocked_by responses, one array per page as `gh --paginate --slurp` prints them.
dep() { # dep <number> <state> [repository_url]
  jq -nc --argjson n "$1" --arg s "$2" --arg r "${3:-https://api.github.com/repos/${REPO}}" \
    '{number: $n, state: $s, html_url: "https://github.com/x/y/issues/\($n)", repository_url: $r}'
}
write_deps() { jq -sc '[.]' >"${mock}/deps-$1.json"; }
dep 2 open | write_deps 5
dep 4 open | write_deps 2
dep 21 open | write_deps 20
dep 31 open | write_deps 30
dep 32 open | write_deps 31
{ dep 51 closed; } | write_deps 50
dep 99 open https://api.github.com/repos/other/repo | write_deps 60

cat >"${mock}/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
dir="$(dirname "$0")"
echo "$*" >>"${dir}/calls.log"
if [ -f "${dir}/fail-graphql" ] && [[ "$*" == *graphql* ]]; then
  echo 'HTTP 403: Resource not accessible by personal access token' >&2
  exit 1
fi
if [ -f "${dir}/fail-deps" ] && [[ "$*" == *blocked_by* ]]; then
  echo 'boom' >&2
  exit 1
fi
case "$*" in
  *graphql*after=*) cat "${dir}/page2.json" ;;
  *graphql*)        cat "${dir}/page1.json" ;;
  *--slurp*issues/*/dependencies/blocked_by*)
    n="$(sed -E 's#.*issues/([0-9]+)/dependencies.*#\1#' <<<"$*")"
    if [ -f "${dir}/deps-${n}.json" ]; then cat "${dir}/deps-${n}.json"; else echo '[[]]'; fi ;;
  *git/ref/heads/claude/issue-40*) exit 0 ;;
  *git/ref/heads/claude/issue-*)   echo 'gh: Not Found (HTTP 404)' >&2; exit 1 ;;
  *) echo "Unexpected gh call: $*" >&2; exit 2 ;;
esac
MOCK
chmod +x "${mock}/gh"

fail() { echo "FAIL: $*" >&2; exit 1; }

out="$(PATH="${mock}:${PATH}" bash "${root}/scripts/backlog-ready-queue.sh")"

check() { # check <description> <jq expression>
  printf '%s' "${out}" | jq -e "$2" >/dev/null || { printf '%s\n' "${out}" | jq . >&2; fail "$1"; }
}

# 4 and 32 inherit P0 (4 through two Ready links, 32 through a link that is
# NOT Ready); 4 unblocks two Ready issues, 32 one, so 4 goes first.
check 'executable order' '[.ready[].number] == [4, 32, 3, 50, 80]'
check '4 inherits P0 and unblocks two' '.ready[0] | .effective_priority == "P0" and .unlocks == 2 and .priority == "P2"'
check '32 inherits P0 through a non-Ready middle link' '.ready[1] | .effective_priority == "P0" and .unlocks == 1 and .priority == "P3"'
check 'a closed blocker does not block' '.ready | any(.number == 50)'
check 'an issue without Priority is last, not excluded' '.ready[-1] | .number == 80 and .effective_priority == null'
check 'a peer reservation is set aside, not offered' '[.reserved[].number] == [40] and (.ready | all(.number != 40))'
check 'blocked issues' '[.blocked[].number] == [2, 5, 20, 30, 60]'
check 'Todo is never offered' '(.ready + .reserved + .blocked | all(.number != 9))'
check 'closed and foreign-repo Ready items are ignored' '(.ready + .reserved + .blocked | all(.number != 10 and .number != 11))'
check 'drafts and pull requests are ignored' '(.ready | length) == 5'
check 'a blocker in another repository blocks' '.blocked[] | select(.number == 60) | .blockers[0].status == "other repository"'
check 'non-Ready prerequisites are reported, not promoted' '[.needs_ready[] | {number, blocks}] == [{"number": 21, "blocks": [20]}, {"number": 31, "blocks": [30]}]'
check 'in progress carries the agent' '.in_progress == [{"number": 70, "title": "Issue 70", "url": "https://github.com/'"${REPO}"'/issues/70", "agent": "Claude A1"}]'
grep -q 'after=CURSOR2' "${mock}/calls.log" || fail 'the second Project page was never requested'

# A Project that cannot be read is an ERROR — never an empty queue, never a
# fall-back to labels.
touch "${mock}/fail-graphql"
if PATH="${mock}:${PATH}" bash "${root}/scripts/backlog-ready-queue.sh" >"${mock}/err.out" 2>&1; then
  fail 'a Project read failure was mistaken for an empty queue'
fi
grep -q 'UNKNOWN, not empty' "${mock}/err.out" || fail 'the failure does not say the queue is unknown'
if grep -q 'status:accepted\|labels=' "${mock}/calls.log"; then
  fail 'the queue fell back to repository labels'
fi
rm -f "${mock}/fail-graphql"

# A dependency list that cannot be read stops the run rather than being read as
# "no blockers".
touch "${mock}/fail-deps"
if PATH="${mock}:${PATH}" bash "${root}/scripts/backlog-ready-queue.sh" >"${mock}/err.out" 2>&1; then
  fail 'an unreadable dependency list was read as no blockers'
fi
grep -q 'refusing to guess' "${mock}/err.out" || fail 'the dependency failure is not reported'

echo 'Backlog Ready queue tests passed.'
