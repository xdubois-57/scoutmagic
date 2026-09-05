#!/bin/bash
set -euo pipefail

# Usage: ./scripts/sync-issue-labels.sh [--dry-run] [--repo owner/name]
#
# Creates, and thereafter repairs, the issue labels this repository's triage
# depends on. Idempotent by construction: a second run reports every label
# as unchanged and writes nothing.
#
# WHY THIS IS A SCRIPT AND NOT SIX CLICKS. Labels are the state of a
# triaged issue — the whole taxonomy below is read by automation and by a
# human deciding what to work on, and none of it lives in the repository
# unless something puts it there. A label created by hand exists in exactly
# one repository, with a colour and a description nobody can diff, and it
# is gone the day somebody renames it in the UI. This file is that
# taxonomy's single source: the issue forms declare labels from it, and
# LABELS below is the only place any of them is spelled out.
#
# It never DELETES anything, which matters more than it looks: this
# repository has labels older than this file (`bug`, `claude-review`), the
# second of which .github/workflows/claude-review.yml matches by name. A
# sync that reconciled the repository down to its own table would delete
# it, and the workflow's manual lever would stop working with nothing
# saying so.
#
# Requires: gh (authenticated — `gh auth login`), jq.

DRY_RUN=0
REPO=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --repo) REPO="${2:-}"; shift 2 ;;
        -h|--help)
            echo "Usage: ./scripts/sync-issue-labels.sh [--dry-run] [--repo owner/name]"
            exit 0
            ;;
        *)
            echo "ERROR: unknown argument '$1'." >&2
            exit 2
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FORM_DIR="${ISSUE_TEMPLATE_DIR:-${SCRIPT_DIR}/../.github/ISSUE_TEMPLATE}"

# The taxonomy. One line per label: name|colour|description.
#
# English, like every other label on this repository and like the analysis
# the triage agent writes into GitHub. AGENTS.md § Language governs the
# application's interface — what a unit chief reads on the site — and these
# are read by the maintainer and by automation, never rendered to a user.
#
# `status:accepted` is deliberately inert: the agent must never apply or
# remove it, and no workflow reads it. It is a marker for a human eye,
# and it is created here so that the one hand-applied label in the set is
# still described, coloured and reproducible like the rest.
#
# The colours are not arbitrary in one respect: `bug:confirmed` is a
# darker red than the repository's older `bug` label rather than the same
# one, so a glance at an issue list tells a confirmed defect apart from a
# report somebody tagged by hand.
LABELS=(
    "triage:pending|fbca04|Never analysed — the state every new issue starts in"
    "triage:done|c5def5|Analysed, verdict posted"
    "bug:confirmed|b60205|A real defect, understood"
    "bug:not-a-bug|cfd3d7|Works as designed, or user error"
    "bug:needs-info|d876e3|Blocked on an answer from the reporter"
    "status:accepted|0e8a16|The maintainer will do it — applied by hand, read by no automation"
)

command -v gh &> /dev/null || {
    echo "ERROR: GitHub CLI (gh) is required — install it and run gh auth login." >&2
    exit 1
}
command -v jq &> /dev/null || {
    echo "ERROR: jq is required." >&2
    exit 1
}

# `gh api` expands {owner}/{repo} from the current checkout's default
# remote, the same form scripts/release.sh uses. --repo overrides it for a
# fork or a test repository.
if [[ -n "${REPO}" ]]; then
    API_BASE="repos/${REPO}"
else
    API_BASE="repos/{owner}/{repo}"
fi

# ---------------------------------------------------------------------------
# The check that stops a label being dropped in silence.
#
# GitHub applies a label named in an issue form's `labels:` ONLY if it
# already exists on the repository. An unknown name is discarded when the
# issue is created: no error on the issue, nothing in any log, and an issue
# that quietly starts life with no triage state at all. That is the exact
# shape of failure docs/quality-pipeline.md § "something green that proved
# nothing" collects, so this refuses before the first API call rather than
# leaving it to be discovered on a real report.
# ---------------------------------------------------------------------------
known_label() {
    local needle="$1" entry
    for entry in "${LABELS[@]}"; do
        [[ "${entry%%|*}" == "${needle}" ]] && return 0
    done
    return 1
}

# This is a `grep`, not a YAML parser, and GitHub's schema accepts more
# shapes than a grep can read: a block sequence (`labels:` then indented
# `- ` items), a flow sequence broken over several lines, and a plain
# comma-delimited string are all valid `labels:` and all mean something.
#
# So rather than try to read each shape, every `labels:` line is required
# to be the ONE shape this can read — a complete flow sequence, opened and
# closed on its own line — and anything else is refused by name. Refusing
# is the whole point: a shape this skips leaves the check below reporting
# success over a declaration it never saw, which is the failure mode where
# a green result and no result look identical. A multiline flow sequence
# did exactly that until CodeRabbit found it on this pull request — the
# sync ran, and an unknown label went unreported.
#
# The guard runs in the main shell on purpose. Inside a function called
# through process substitution, its `exit` would have ended the subshell
# and left the script running — the check silently disabled by the very
# input it exists to catch. (It did, until the test caught it.)
DECLARED=""
if [[ -d "${FORM_DIR}" ]]; then
    for form in "${FORM_DIR}"/*.yml; do
        [[ -e "${form}" ]] || continue

        # Every top-level `labels:` in the file. A form field's own key is
        # `label:` and is indented, so `^labels:` matches only this one.
        while IFS= read -r line; do
            if [[ ! "${line}" =~ ^labels:[[:space:]]*\[[^][]*\][[:space:]]*$ ]]; then
                echo "ERROR: ${form} declares labels in a shape this check cannot read:" >&2
                echo "  ${line}" >&2
                echo "Write the whole list inline on one line — labels: [\"a\", \"b\"] — so the check below can see it." >&2
                echo "A block sequence, a flow sequence split over several lines and a comma-delimited string are all valid YAML that this would silently skip." >&2
                exit 1
            fi

            DECLARED+="$(sed -e 's/^labels:[[:space:]]*\[//' -e 's/\][[:space:]]*$//' <<< "${line}" \
                | tr ',' '\n' \
                | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//" \
                | grep -v '^[[:space:]]*$' || true)"$'\n'
        done < <(grep -E '^labels:' "${form}" || true)
    done
    DECLARED="$(sort -u <<< "${DECLARED}")"
fi

UNKNOWN=""
while IFS= read -r declared; do
    [[ -n "${declared}" ]] || continue
    known_label "${declared}" || UNKNOWN+="  ${declared}"$'\n'
done <<< "${DECLARED}"

if [[ -n "${UNKNOWN}" ]]; then
    echo "ERROR: an issue form declares a label this script does not create:" >&2
    printf '%s' "${UNKNOWN}" >&2
    echo "GitHub drops an unknown label from a form in silence, so the issue would arrive with no triage state." >&2
    echo "Add it to LABELS in ${BASH_SOURCE[0]}, or correct the form." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# The sync itself.
# ---------------------------------------------------------------------------
CREATED=0
UPDATED=0
UNCHANGED=0

ERR_FILE="$(mktemp)"
trap 'rm -f "${ERR_FILE}"' EXIT

echo "Syncing ${#LABELS[@]} labels against ${API_BASE}."
if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "(dry run — nothing will be written)"
fi

for entry in "${LABELS[@]}"; do
    IFS='|' read -r name colour description <<< "${entry}"

    # A 404 here is the "does not exist yet" answer and the only failure
    # this loop absorbs. Anything else — a network error, a revoked token,
    # a rate limit — must stop the run rather than be read as "absent" and
    # answered with a create that then fails for the same reason. One call,
    # reading gh's own message: asking twice would double the rate-limit
    # cost of the read and could get two different answers.
    existing=""
    absent=0
    if ! existing="$(gh api "${API_BASE}/labels/${name}" 2>"${ERR_FILE}")"; then
        if grep -q 'HTTP 404' "${ERR_FILE}"; then
            absent=1
        else
            echo "ERROR: could not read label '${name}':" >&2
            cat "${ERR_FILE}" >&2
            exit 1
        fi
    fi

    if [[ "${absent}" -eq 1 ]]; then
        echo "  + ${name}"
        if [[ "${DRY_RUN}" -eq 0 ]]; then
            gh api -X POST "${API_BASE}/labels" \
                -f "name=${name}" -f "color=${colour}" -f "description=${description}" >/dev/null
        fi
        CREATED=$((CREATED + 1))
        continue
    fi

    current_colour="$(jq -r '.color // ""' <<< "${existing}")"
    current_description="$(jq -r '.description // ""' <<< "${existing}")"

    if [[ "${current_colour}" == "${colour}" && "${current_description}" == "${description}" ]]; then
        echo "  = ${name}"
        UNCHANGED=$((UNCHANGED + 1))
        continue
    fi

    echo "  ~ ${name} (colour ${current_colour} → ${colour})"
    if [[ "${DRY_RUN}" -eq 0 ]]; then
        gh api -X PATCH "${API_BASE}/labels/${name}" \
            -f "new_name=${name}" -f "color=${colour}" -f "description=${description}" >/dev/null
    fi
    UPDATED=$((UPDATED + 1))
done

echo "Done: ${CREATED} created, ${UPDATED} updated, ${UNCHANGED} unchanged."

# The idempotence claim, made checkable. A run that changed nothing is what
# every run after the first should print; anything else is a label somebody
# edited in the UI, which is worth seeing rather than absorbing quietly.
if [[ "${CREATED}" -eq 0 && "${UPDATED}" -eq 0 ]]; then
    echo "Nothing to do — the repository already matches this file."
fi
