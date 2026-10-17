#!/usr/bin/env bash
# Shared helpers for the scripts that read or write ScoutMagic GitHub Project #1
# (issue #923). Sourced, never executed: it sets no shell options of its own.
#
# WHY GRAPHQL AND NOT `gh project item-list`: that command's JSON shape is not
# something an offline test can pin down, and a mock written from memory
# hides the difference (the first draft of the Ready queue passed a `--field`
# flag the command does not have, and its fixture agreed with it). The GraphQL
# schema is documented and exact, and it is the same call whether the caller
# is a developer's `gh` login or the Actions runner holding PROJECTS_TOKEN.
#
# NO CREDENTIAL IS READ HERE. Whatever `gh` is authenticated as does the work:
# the maintainer's login, or GH_TOKEN set by a workflow step that alone holds
# the PROJECTS_TOKEN secret. The model never gets either.

PROJECT_OWNER='xdubois-57'
PROJECT_NUMBER=1
PROJECT_REPO='xdubois-57/scoutmagic'

# Prints every ISSUE item of the Project as a JSON array, one object each:
# {item_id, issue, repo, state, state_reason, title, url, status, priority,
#  agent, effort}. Draft issues and pull requests are left out. Exits non-zero
# when the Project cannot be read completely — a truncated read must never
# look like a short backlog.
project_items() {
  local query cursor='' page items='[]' has_next

  read -r -d '' query <<'GRAPHQL' || true
query($owner: String!, $number: Int!, $after: String) {
  user(login: $owner) {
    projectV2(number: $number) {
      id
      items(first: 100, after: $after) {
        pageInfo { hasNextPage endCursor }
        nodes {
          id
          content {
            __typename
            ... on Issue {
              number
              title
              url
              state
              stateReason
              repository { nameWithOwner }
            }
          }
          fieldValues(first: 50) {
            pageInfo { hasNextPage }
            nodes {
              ... on ProjectV2ItemFieldSingleSelectValue {
                name
                field { ... on ProjectV2FieldCommon { name } }
              }
              ... on ProjectV2ItemFieldTextValue {
                text
                field { ... on ProjectV2FieldCommon { name } }
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL

  while :; do
    if [ -n "${cursor}" ]; then
      page="$(gh api graphql -f query="${query}" -f owner="${PROJECT_OWNER}" \
        -F number="${PROJECT_NUMBER}" -f after="${cursor}")" || return 1
    else
      page="$(gh api graphql -f query="${query}" -f owner="${PROJECT_OWNER}" \
        -F number="${PROJECT_NUMBER}")" || return 1
    fi

    if ! printf '%s' "${page}" | jq -e '.data.user.projectV2.items.nodes | type == "array"' >/dev/null 2>&1; then
      echo "ERROR: Cannot read ${PROJECT_OWNER}'s Project #${PROJECT_NUMBER} (no access, or it does not exist)." >&2
      return 1
    fi
    if printf '%s' "${page}" | jq -e 'any(.data.user.projectV2.items.nodes[]?; .fieldValues.pageInfo.hasNextPage == true)' >/dev/null 2>&1; then
      echo "ERROR: A Project item carries more field values than this reader inspects." >&2
      return 1
    fi

    items="$(jq -c --argjson acc "${items}" '
      def field($n): [.fieldValues.nodes[]? | select(.field.name == $n)] | first | (.name // .text // null);
      $acc + [ .data.user.projectV2.items.nodes[]
        | select(.content.__typename == "Issue")
        | { item_id: .id,
            issue: .content.number,
            repo: .content.repository.nameWithOwner,
            state: .content.state,
            state_reason: .content.stateReason,
            title: .content.title,
            url: .content.url,
            status: field("Status"),
            priority: field("Priority"),
            agent: field("Agent"),
            effort: field("Effort") } ]' <<<"${page}")"

    has_next="$(printf '%s' "${page}" | jq -r '.data.user.projectV2.items.pageInfo.hasNextPage')"
    if [ "${has_next}" != 'true' ]; then
      break
    fi
    cursor="$(printf '%s' "${page}" | jq -r '.data.user.projectV2.items.pageInfo.endCursor')"
    [ -n "${cursor}" ] && [ "${cursor}" != 'null' ] || { echo 'ERROR: Project pagination returned no cursor.' >&2; return 1; }
  done

  printf '%s\n' "${items}"
}

# Prints {project_id, fields: {<name>: {id, options: {<option name>: <id>}}}}
# for the single-select and text fields of the Project.
project_meta() {
  local query out
  read -r -d '' query <<'GRAPHQL' || true
query($owner: String!, $number: Int!) {
  user(login: $owner) {
    projectV2(number: $number) {
      id
      fields(first: 100) {
        pageInfo { hasNextPage }
        nodes {
          ... on ProjectV2SingleSelectField { id name options { id name } }
          ... on ProjectV2Field { id name dataType }
        }
      }
    }
  }
}
GRAPHQL
  out="$(gh api graphql -f query="${query}" -f owner="${PROJECT_OWNER}" -F number="${PROJECT_NUMBER}")" || return 1
  if ! printf '%s' "${out}" | jq -e '.data.user.projectV2.id and (.data.user.projectV2.fields.pageInfo.hasNextPage == false)' >/dev/null 2>&1; then
    echo "ERROR: Cannot read the fields of Project #${PROJECT_NUMBER} completely." >&2
    return 1
  fi
  printf '%s' "${out}" | jq -c '{
    project_id: .data.user.projectV2.id,
    fields: ([.data.user.projectV2.fields.nodes[]? | select(.name != null)
      | {key: .name, value: {id: .id, options: ([.options[]? | {key: .name, value: .id}] | from_entries)}}]
      | from_entries) }'
}

# project_set_option <project_id> <item_id> <field_id> <option_id>
project_set_option() {
  local out
  out="$(gh api graphql -f query='
mutation($project: ID!, $item: ID!, $field: ID!, $option: String!) {
  updateProjectV2ItemFieldValue(input: {projectId: $project, itemId: $item, fieldId: $field,
    value: {singleSelectOptionId: $option}}) { projectV2Item { id } }
}' -f project="$1" -f item="$2" -f field="$3" -f option="$4" \
    --jq '.data.updateProjectV2ItemFieldValue.projectV2Item.id // empty')" || return 1
  [ "${out}" = "$2" ]
}

# project_set_text <project_id> <item_id> <field_id> <text>
project_set_text() {
  local out
  out="$(gh api graphql -f query='
mutation($project: ID!, $item: ID!, $field: ID!, $text: String!) {
  updateProjectV2ItemFieldValue(input: {projectId: $project, itemId: $item, fieldId: $field,
    value: {text: $text}}) { projectV2Item { id } }
}' -f project="$1" -f item="$2" -f field="$3" -f text="$4" \
    --jq '.data.updateProjectV2ItemFieldValue.projectV2Item.id // empty')" || return 1
  [ "${out}" = "$2" ]
}

# project_clear_field <project_id> <item_id> <field_id>
project_clear_field() {
  local out
  out="$(gh api graphql -f query='
mutation($project: ID!, $item: ID!, $field: ID!) {
  clearProjectV2ItemFieldValue(input: {projectId: $project, itemId: $item, fieldId: $field}) {
    projectV2Item { id }
  }
}' -f project="$1" -f item="$2" -f field="$3" \
    --jq '.data.clearProjectV2ItemFieldValue.projectV2Item.id // empty')" || return 1
  [ "${out}" = "$2" ]
}
