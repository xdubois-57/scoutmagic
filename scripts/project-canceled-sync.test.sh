#!/usr/bin/env bash
set -euo pipefail

# Offline test for scripts/project-canceled-sync.sh: `gh` is a stateful
# fixture (closing an issue closes it for the next run). Run by
# tests/Security/BacklogProjectScriptsTest.php.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
REPO='xdubois-57/scoutmagic'

node() { # node <number> <repo> <state> <status> [agent]
  local number="$1" repo="$2" state="$3" status="$4" agent="${5:-}"
  jq -nc --argjson n "${number}" --arg repo "${repo}" --arg state "${state}" --arg status "${status}" --arg agent "${agent}" '
    { id: "ITEM\($n)",
      content: {__typename: "Issue", number: $n, title: "Issue \($n)", url: "u", state: $state, stateReason: null, repository: {nameWithOwner: $repo}},
      fieldValues: {pageInfo: {hasNextPage: false}, nodes:
        ([ {name: $status, field: {name: "Status"}} ] + (if $agent != "" then [{text: $agent, field: {name: "Agent"}}] else [] end))} }'
}
printf '%s\n' \
  "$(node 1 "${REPO}" OPEN Canceled)" \
  "$(node 2 "${REPO}" OPEN Canceled 'Claude A1')" \
  "$(node 3 "${REPO}" CLOSED Canceled)" \
  "$(node 4 "${REPO}" OPEN Ready)" \
  "$(node 5 other/repo OPEN Canceled)" \
  "$(node 6 "${REPO}" OPEN Done)" \
  "$(node 7 "${REPO}" OPEN Canceled)" \
  "$(node 8 "${REPO}" OPEN 'In Progress' 'Claude B1')" \
  "$(jq -nc '{id: "D", content: {__typename: "DraftIssue"}, fieldValues: {pageInfo: {hasNextPage: false}, nodes: []}}')" \
  | jq -sc '{data: {user: {projectV2: {id: "P", items: {pageInfo: {hasNextPage: false, endCursor: ""}, nodes: .}}}}}' >"${tmp}/page.json"

mkdir -p "${tmp}/state"
for n in 1 2 3 4 5 6 7 8; do echo open >"${tmp}/state/${n}"; done
echo closed >"${tmp}/state/3"

cat >"${tmp}/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
dir="$(dirname "$0")"
echo "$*" >>"${dir}/calls.log"
case "$*" in
  *graphql*)
    [[ -f "${dir}/fail-read" ]] && { echo 'HTTP 401' >&2; exit 1; }
    cat "${dir}/page.json" ;;
  *comments*)
    n="$(sed -E 's#.*issues/([0-9]+)/comments.*#\1#' <<<"$*")"
    cat >"${dir}/comment-${n}.json"
    [[ "${n}" = "1" ]] && [[ -f "${dir}/fail-comment-1" ]] && exit 1
    exit 0 ;;
  *PATCH*)
    n="$(sed -E 's#.*issues/([0-9]+) .*#\1#' <<<"$*")"
    [[ -f "${dir}/fail-close-${n}" ]] && exit 1
    echo "$*" >"${dir}/patch-${n}.txt"; echo closed >"${dir}/state/${n}"; exit 0 ;;
  *issues/*)
    n="$(sed -E 's#.*issues/([0-9]+).*#\1#' <<<"$*")"
    jq -nc --arg s "$(cat "${dir}/state/${n}")" '{state: $s}' ;;
  *) echo "Unexpected gh call: $*" >&2; exit 2 ;;
esac
MOCK
chmod +x "${tmp}/gh"

fail() { echo "FAIL: $*" >&2; exit 1; }
sync() { PATH="${tmp}:${PATH}" GH_TOKEN=x bash "${root}/scripts/project-canceled-sync.sh" "${@:-all}" >"${tmp}/out" 2>&1; }

sync || { cat "${tmp}/out"; fail 'the sync failed'; }
for n in 1 2 7; do
  grep -q 'state=closed -f state_reason=not_planned' "${tmp}/patch-${n}.txt" || fail "#${n} was not closed as not planned"
  jq -e '.body | test("Canceled") and (test("corrig|Corrig|fixed") | not)' "${tmp}/comment-${n}.json" >/dev/null \
    || fail "#${n}: the comment is missing, or claims a fix"
done
# Ready → Canceled and In Progress → Canceled alike; everything else is untouched.
for n in 3 4 5 6 8; do
  [[ ! -f "${tmp}/patch-${n}.txt" ]] || fail "#${n} must not be closed"
done
grep -q 'Priority\|labels' "${tmp}/calls.log" && fail 'the sync touched priorities or labels'

# Repeating it has no effect at all.
before="$(wc -l <"${tmp}/calls.log")"
rm -f "${tmp}"/patch-*.txt
sync
[[ ! -f "${tmp}/patch-1.txt" ]] || fail 'a second run closed again'
grep -q 'comments' <(tail -n +"$((before + 1))" "${tmp}/calls.log") && fail 'a second run commented again'

# One failure is reported and does not stop the rest.
for n in 1 2 7; do echo open >"${tmp}/state/${n}"; done
touch "${tmp}/fail-close-2"
rm -f "${tmp}"/patch-*.txt
if sync; then fail 'a failed closure passed as success'; fi
[[ -f "${tmp}/patch-1.txt" ]] && [[ -f "${tmp}/patch-7.txt" ]] || fail 'a failure on #2 stopped the others'
grep -q 'Could not close #2' "${tmp}/out" || fail 'the failure is not reported'
rm -f "${tmp}/fail-close-2"

# An issue that cannot be explained stays open.
for n in 1 2 7; do echo open >"${tmp}/state/${n}"; done
touch "${tmp}/fail-comment-1"
rm -f "${tmp}"/patch-*.txt
if sync; then fail 'a failed comment passed as success'; fi
[[ ! -f "${tmp}/patch-1.txt" ]] || fail '#1 was closed without an explanation'
rm -f "${tmp}/fail-comment-1"

# A Project that cannot be read closes NOTHING and says so.
for n in 1 2 7; do echo open >"${tmp}/state/${n}"; done
touch "${tmp}/fail-read"
rm -f "${tmp}"/patch-*.txt
if sync; then fail 'an unreadable Project passed as success'; fi
ls "${tmp}"/patch-*.txt >/dev/null 2>&1 && fail 'something was closed although the Project was unreadable'
grep -q 'cannot read the Project' "${tmp}/out" || fail 'the read failure is not reported'

rm -f "${tmp}/fail-read"

# The two phases hold different credentials: `list` only reads (it never
# comments or closes) and `close` never reads the Project.
for n in 1 2 7; do echo open >"${tmp}/state/${n}"; done
: >"${tmp}/calls.log"; rm -f "${tmp}"/patch-*.txt
sync list || fail 'list failed'
[[ "$(tr '\n' ' ' <"${tmp}/out")" = '1 2 7 ' ]] || { cat "${tmp}/out"; fail 'list must print exactly the open Canceled issues'; }
grep -q 'PATCH\|comments' "${tmp}/calls.log" && fail 'list wrote'
: >"${tmp}/calls.log"
sync close 2 || fail 'close failed'
grep -q 'graphql' "${tmp}/calls.log" && fail 'close read the Project'
[[ -f "${tmp}/patch-2.txt" ]] && [[ ! -f "${tmp}/patch-1.txt" ]] || fail 'close touched an issue other than the one named'
if sync close 'x1'; then fail 'a bad issue number was accepted'; fi
if sync frob; then fail 'an unknown mode was accepted'; fi

echo 'Canceled sync tests passed.'
