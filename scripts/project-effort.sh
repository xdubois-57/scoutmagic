#!/usr/bin/env bash
set -euo pipefail

# Set an issue's Effort on ScoutMagic Project #1, but never replace a value
# already set by a human or by an earlier automation run.
# Authentication comes ONLY from the calling step's PROJECTS_TOKEN as GH_TOKEN.
# Usage: GH_TOKEN=... GITHUB_REPOSITORY=xdubois-57/scoutmagic bash scripts/project-effort.sh ISSUE S|M|L

if [[ "$#" -ne 2 ]]; then
  echo "Usage: project-effort.sh <issue number> <S|M|L>" >&2
  exit 2
fi
issue="$1"
effort="$2"

case "$issue" in
  ''|*[!0-9]*|0) echo "Invalid issue number." >&2; exit 2 ;;
  *) ;;
esac
case "$effort" in
  S|M|L) ;;
  *) echo "Invalid effort value. Expected S, M or L." >&2; exit 2 ;;
esac
if [[ "${GITHUB_REPOSITORY:-}" != "xdubois-57/scoutmagic" ]]; then
  echo "Refusing to write to a different repository." >&2
  exit 2
fi
if [[ -z "${GH_TOKEN:-}" ]]; then
  echo "::error title=Missing Projects token::Set the repository secret PROJECTS_TOKEN with the project scope." >&2
  exit 1
fi

query=$(cat <<'GRAPHQL'
query($owner: String!, $repo: String!, $number: Int!, $issue: Int!) {
  user(login: $owner) {
    projectV2(number: $number) {
      id
      fields(first: 100) {
        pageInfo { hasNextPage }
        nodes {
          ... on ProjectV2SingleSelectField {
            id
            name
            options { id name }
          }
        }
      }
    }
  }
  repository(owner: $owner, name: $repo) {
    issue(number: $issue) {
      id
      projectItems(first: 100) {
        pageInfo { hasNextPage }
        nodes {
          id
          project { id }
          fieldValues(first: 100) {
            pageInfo { hasNextPage }
            nodes {
              ... on ProjectV2ItemFieldSingleSelectValue {
                optionId
                field { ... on ProjectV2SingleSelectField { id } }
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL
)
snapshot="$(gh api graphql -f query="$query" -f owner=xdubois-57 \
  -f repo=scoutmagic -F number=1 -F issue="$issue")"

project_id="$(jq -r '.data.user.projectV2.id // empty' <<< "$snapshot")"
issue_id="$(jq -r '.data.repository.issue.id // empty' <<< "$snapshot")"
if [[ -z "$project_id" ]] || [[ -z "$issue_id" ]]; then
  echo "::error title=Project unavailable::Cannot read ScoutMagic Project #1 or issue #$issue. Check PROJECTS_TOKEN." >&2
  exit 1
fi
if [[ "$(jq -r '.data.user.projectV2.fields.pageInfo.hasNextPage or .data.repository.issue.projectItems.pageInfo.hasNextPage' <<< "$snapshot")" != false ]]; then
  echo "Cannot safely inspect the full project or issue project list." >&2
  exit 1
fi

field_id="$(jq -r '.data.user.projectV2.fields.nodes[]? | select(.name == "Effort") | .id' <<< "$snapshot")"
if [[ -z "$field_id" ]]; then
  echo "::error title=Effort field missing::Create a single-select Effort field (S, M, L) on Project #1." >&2
  exit 1
fi
option_id="$(jq -r --arg field "$field_id" --arg effort "$effort" \
  '.data.user.projectV2.fields.nodes[]? | select(.id == $field) | .options[]? | select(.name == $effort) | .id' \
  <<< "$snapshot")"
if [[ -z "$option_id" ]]; then
  echo "::error title=Effort option missing::Field Effort must have the exact choices S, M and L." >&2
  exit 1
fi

item_json="$(jq -c --arg project "$project_id" \
  '[.data.repository.issue.projectItems.nodes[]? | select(.project.id == $project)] | .[0] // empty' \
  <<< "$snapshot")"
if [[ -n "$item_json" ]]; then
  if [[ "$(jq -r '.fieldValues.pageInfo.hasNextPage' <<< "$item_json")" != false ]]; then
    echo "Cannot safely inspect all project item field values." >&2
    exit 1
  fi
  # Any existing value is authoritative, including a manual correction.
  existing="$(jq -r --arg field "$field_id" \
    '.fieldValues.nodes[]? | select(.field.id == $field) | .optionId // empty' \
    <<< "$item_json")"
  if [[ -n "$existing" ]]; then
    echo "Issue #$issue already has Effort set; leaving it unchanged."
    exit 0
  fi
  item_id="$(jq -r '.id' <<< "$item_json")"
else
  add=$(cat <<'GRAPHQL'
mutation($project: ID!, $content: ID!) {
  addProjectV2ItemById(input: {projectId: $project, contentId: $content}) {
    item { id }
  }
}
GRAPHQL
)
  item_id="$(gh api graphql -f query="$add" -f project="$project_id" \
    -f content="$issue_id" --jq '.data.addProjectV2ItemById.item.id // empty')"
  if [[ -z "$item_id" ]]; then
    echo "Could not add issue #$issue to ScoutMagic Project #1." >&2
    exit 1
  fi
fi


# Read the item again immediately before writing, including when it was just
# added: auto-add workflows or Project default values may have populated it
# since the initial snapshot. A maintainer's value always takes precedence.
item_query=$(cat <<'GRAPHQL'
query($item: ID!) {
  node(id: $item) {
    ... on ProjectV2Item {
      id
      fieldValues(first: 100) {
        pageInfo { hasNextPage }
        nodes {
          ... on ProjectV2ItemFieldSingleSelectValue {
            optionId
            field { ... on ProjectV2SingleSelectField { id } }
          }
        }
      }
    }
  }
}
GRAPHQL
)
current="$(gh api graphql -f query="$item_query" -f item="$item_id")"
if [[ "$(jq -r '.data.node.id // empty' <<< "$current")" != "$item_id" ]]; then
  echo "Could not re-read project item $item_id; refusing to change Effort." >&2
  exit 1
fi
if [[ "$(jq -r '.data.node.fieldValues.pageInfo.hasNextPage' <<< "$current")" != false ]]; then
  echo "Cannot safely inspect every current field value." >&2
  exit 1
fi
current_effort="$(jq -r --arg field "$field_id" \
  '.data.node.fieldValues.nodes[]? | select(.field.id == $field) | .optionId // empty' \
  <<< "$current")"
if [[ -n "$current_effort" ]]; then
  echo "Issue #$issue has acquired an Effort value; leaving it unchanged."
  exit 0
fi

mutation=$(cat <<'GRAPHQL'
mutation($project: ID!, $item: ID!, $field: ID!, $option: String!) {
  updateProjectV2ItemFieldValue(input: {
    projectId: $project, itemId: $item, fieldId: $field,
    value: {singleSelectOptionId: $option}
  }) {
    projectV2Item { id }
  }
}
GRAPHQL
)
updated="$(gh api graphql -f query="$mutation" -f project="$project_id" \
  -f item="$item_id" -f field="$field_id" -f option="$option_id" \
  --jq '.data.updateProjectV2ItemFieldValue.projectV2Item.id // empty')"
if [[ "$updated" != "$item_id" ]]; then
  echo "Effort was not confirmed on issue #$issue." >&2
  exit 1
fi
echo "Set Effort=$effort on ScoutMagic issue #$issue (Project #1)."
