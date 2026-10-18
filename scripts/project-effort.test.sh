#!/usr/bin/env bash
set -euo pipefail

# Offline contract tests for the project writer. No GitHub credentials or
# network connection are used: gh is replaced by a fixture-driven executable.
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
cat > "$tmp/gh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
# Keep one log line per gh invocation, even for multiline GraphQL.
# Retain the operation name so tests can distinguish add from update.
printf '%s' "$*" | tr '\n' ' ' >> "$GH_TEST_CALLS"
printf '\n' >> "$GH_TEST_CALLS"
case "$*" in
  *addProjectV2ItemById*) printf 'ITEM\n' ;;
  *updateProjectV2ItemFieldValue*) printf 'ITEM\n' ;;
  *'query($item: ID!)'*) printf '%s\n' "$GH_TEST_ITEM_RESPONSE" ;;
  *) printf '%s\n' "$GH_TEST_RESPONSE" ;;
esac
MOCK
chmod +x "$tmp/gh"

snapshot() {
  jq -n --arg has_item "$1" --arg existing "$2" --arg field "$3" '
  {data:{
    user:{projectV2:{id:"PROJECT",fields:{pageInfo:{hasNextPage:false},
      nodes:(if $field == "yes" then
        [{id:"FIELD",name:"Effort",options:[{id:"OPT-S",name:"S"},{id:"OPT-M",name:"M"},{id:"OPT-L",name:"L"}]}]
      else [] end)}}},
    repository:{issue:{id:"ISSUE",projectItems:{pageInfo:{hasNextPage:false},
      nodes:(if $has_item == "yes" then
        [{id:"ITEM",project:{id:"PROJECT"},fieldValues:{
          pageInfo:{hasNextPage:false},
          nodes:(if $existing == "yes" then [{optionId:"OPT-M",field:{id:"FIELD"}}] else [] end)
        }}] else [] end)}}}
  }}'
}

item_state() {
  jq -n --arg exists "$1" '{
    data: {
      node: {
        id: "ITEM",
        fieldValues: {
          pageInfo: {hasNextPage: false},
          nodes: (if $exists == "yes" then
            [{optionId: "OPT-L", field: {id: "FIELD"}}]
          else [] end)
        }
      }
    }
  }'
}

run_writer() {
  : > "$tmp/calls"
  PATH="$tmp:$PATH" GH_TOKEN=fixture GITHUB_REPOSITORY=xdubois-57/scoutmagic \
    GH_TEST_RESPONSE="$1" GH_TEST_ITEM_RESPONSE="${2:-$(item_state no)}" GH_TEST_CALLS="$tmp/calls" \
    bash "$root/scripts/project-effort.sh" 909 M
}
# A manually set value must never be rewritten.
run_writer "$(snapshot yes yes yes)" > "$tmp/out"
grep -q 'already has Effort' "$tmp/out"
[ "$(wc -l < "$tmp/calls")" -eq 1 ]

# An unset field on an existing item is written, without creating a duplicate.
run_writer "$(snapshot yes no yes)" > "$tmp/out"
grep -q 'Set Effort=M' "$tmp/out"
[ "$(wc -l < "$tmp/calls")" -eq 3 ]
grep -q 'updateProjectV2ItemFieldValue' "$tmp/calls"
if grep -q 'addProjectV2ItemById' "$tmp/calls"; then echo "An existing item was added again." >&2; exit 1; fi
# Priority belongs to the maintainer alone (issue #923): the only field this
# writer ever addresses is the Effort one it resolved by name, and no call
# so much as mentions Priority.
grep 'updateProjectV2ItemFieldValue' "$tmp/calls" | grep -q 'field=FIELD'
if grep -qi 'priority' "$tmp/calls"; then echo "A call mentions Priority." >&2; exit 1; fi
if grep -qi 'priority' "$root/scripts/project-effort.sh"; then echo "The writer mentions Priority." >&2; exit 1; fi

# A missing item is added to the intended project before writing Effort.
run_writer "$(snapshot no no yes)" > "$tmp/out"
[ "$(wc -l < "$tmp/calls")" -eq 4 ]
grep -q 'addProjectV2ItemById' "$tmp/calls"
grep -q 'updateProjectV2ItemFieldValue' "$tmp/calls"

# A project default (or another actor) may fill Effort when the item is added.
run_writer "$(snapshot no no yes)" "$(item_state yes)" > "$tmp/out"
grep -q 'has acquired an Effort value' "$tmp/out"
[ "$(wc -l < "$tmp/calls")" -eq 3 ]
if grep -q 'updateProjectV2ItemFieldValue' "$tmp/calls"; then echo "An acquired Effort was overwritten." >&2; exit 1; fi
# Missing project configuration fails clearly without any mutation.
if run_writer "$(snapshot yes no no)" > "$tmp/out" 2>&1; then
  echo "Missing Effort field was silently accepted." >&2
  exit 1
fi
[ "$(wc -l < "$tmp/calls")" -eq 1 ]
grep -q 'Effort field missing' "$tmp/out"

# Agent output is always a closed enum; shell validation is the last gate.
: > "$tmp/calls"
if PATH="$tmp:$PATH" GH_TOKEN=fixture GITHUB_REPOSITORY=xdubois-57/scoutmagic \
    GH_TEST_CALLS="$tmp/calls" bash "$root/scripts/project-effort.sh" 909 hacked > "$tmp/out" 2>&1; then
  echo "Invalid effort value was accepted." >&2
  exit 1
fi
[ ! -s "$tmp/calls" ]

echo "Project Effort tests passed."
