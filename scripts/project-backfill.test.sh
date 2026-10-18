#!/usr/bin/env bash
set -euo pipefail

# Offline test for scripts/project-backfill.sh, with stub helpers, and a
# check of the data file itself. Run by tests/Security/BacklogProjectScriptsTest.php.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# --- the committed data ----------------------------------------------------
data="${root}/scripts/project-backfill.json"
jq -e '.efforts | length > 0 and all(.[]; . == "S" or . == "M" or . == "L" or . == "unknown")' "${data}" >/dev/null \
  || fail 'an effort in the data file is not S, M, L or unknown'
jq -e 'has("priority") or (.efforts | has("priority")) or ([.. | strings] | any(test("^P[0-3]$")))' "${data}" >/dev/null \
  && fail 'the data file carries a priority'
jq -e '.dependencies | any(.issue == 740 and .blocked_by == 724)' "${data}" >/dev/null \
  || fail '#740 is no longer recorded as blocked by #724'
jq -e '.dependencies | all(.[]; (.evidence | length) > 0 and .issue != .blocked_by)' "${data}" >/dev/null \
  || fail 'a dependency without evidence, or on itself'

# --- the script, with stub helpers -------------------------------------------
cat >"${tmp}/data.json" <<'JSON'
{"efforts": {"101": "M", "102": "unknown", "103": "L", "104": "S"},
 "dependencies": [{"issue": 740, "blocked_by": 724, "evidence": "Dépend de #724"}, {"issue": 9, "blocked_by": 8, "evidence": "x"}, {"issue": 11, "blocked_by": 10, "evidence": "y"}]}
JSON
cat >"${tmp}/effort.sh" <<'STUB'
#!/usr/bin/env bash
echo "effort $1 $2" >>"$(dirname "$0")/log"
[[ "$1" = 103 ]] && exit 1
exit 0
STUB
cat >"${tmp}/deps.sh" <<'STUB'
#!/usr/bin/env bash
echo "deps $1 $2" >>"$(dirname "$0")/log"
[[ "$1" = 9 ]] && exit 1
exit 0
STUB
run() {
  local mode="$1"
  : >"${tmp}/log"
  set +e
  BACKFILL_DATA="${tmp}/data.json" BACKFILL_EFFORT_SCRIPT="${tmp}/effort.sh" BACKFILL_DEPENDENCIES_SCRIPT="${tmp}/deps.sh" \
    bash "${root}/scripts/project-backfill.sh" "${mode}" >"${tmp}/out" 2>&1
  rc=$?; set -e
}

run effort
[[ "${rc}" -eq 1 ]] || fail 'a failed issue must fail the run'
grep -q 'effort 101 M' "${tmp}/log" && grep -q 'effort 104 S' "${tmp}/log" || fail 'a failure on #103 stopped the others'
grep -q 'effort 102' "${tmp}/log" && fail 'unknown was written'
grep -q 'deps' "${tmp}/log" && fail 'the effort mode touched dependencies'
grep -q '#102: no defensible estimate' "${tmp}/out" || fail 'unknown is not reported'

run dependencies
[[ "${rc}" -eq 1 ]] || fail 'a failed dependency must fail the run'
grep -q 'deps 740 \[{"issue":724' "${tmp}/log" || fail '#740 -> #724 was not submitted'
grep -q 'deps 9 ' "${tmp}/log" || fail 'the failing dependency was never attempted'
grep -q 'deps 11 ' "${tmp}/log" || fail 'a failure on #9 stopped the dependencies after it'
grep -q 'effort' "${tmp}/log" && fail 'the dependencies mode touched Effort'

# Unreadable data fails the run: it must not look like a backfill with nothing to do.
printf 'not json' >"${tmp}/broken.json"
for mode in effort dependencies; do
  set +e
  BACKFILL_DATA="${tmp}/broken.json" BACKFILL_EFFORT_SCRIPT="${tmp}/effort.sh" BACKFILL_DEPENDENCIES_SCRIPT="${tmp}/deps.sh" \
    bash "${root}/scripts/project-backfill.sh" "${mode}" >"${tmp}/out" 2>&1
  rc=$?; set -e
  [[ "${rc}" -eq 1 ]] || fail "unreadable data passed as a successful ${mode} backfill"
done

set +e; bash "${root}/scripts/project-backfill.sh" bogus >/dev/null 2>&1; rc=$?; set -e
[[ "${rc}" -eq 2 ]] || fail 'an unknown mode was accepted'

echo 'Project backfill tests passed.'
