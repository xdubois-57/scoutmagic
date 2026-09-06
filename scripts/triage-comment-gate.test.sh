#!/bin/bash
set -euo pipefail

# Usage: ./scripts/triage-comment-gate.test.sh
#
# Exercises scripts/triage-comment-gate.sh on both halves of its job:
# the comments it must refuse, and — as important — the comments it must
# let through, because a gate that withholds every verdict mentioning a
# version number is a gate somebody deletes. Same shape as the other
# `.test.sh` files here: run by hand, nothing in CI runs it.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GATE="${SCRIPT_DIR}/triage-comment-gate.sh"

PASS_COUNT=0
FAIL_COUNT=0

expect_allowed() {
  local name="$1" text="$2"
  if printf '%s' "${text}" | bash "${GATE}" >/dev/null 2>&1; then
    echo "PASS  allowed: ${name}"
    PASS_COUNT=$((PASS_COUNT + 1))
  else
    echo "FAIL  allowed: ${name} — was refused"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
}

expect_refused() {
  local name="$1" text="$2" category="$3"
  local output
  if output="$(printf '%s' "${text}" | bash "${GATE}" 2>&1)"; then
    echo "FAIL  refused: ${name} — was allowed"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    return
  fi
  if [[ "${output}" != *"${category}"* ]]; then
    echo "FAIL  refused: ${name} — refused, but not as '${category}': ${output}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    return
  fi
  # The annotation must never carry the match itself.
  local secret="$4"
  if [[ -n "${secret}" ]] && [[ "${output}" == *"${secret}"* ]]; then
    echo "FAIL  refused: ${name} — the annotation quotes the match"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    return
  fi
  echo "PASS  refused: ${name}"
  PASS_COUNT=$((PASS_COUNT + 1))
}

expect_allowed 'an ordinary verdict' \
  "Merci pour ce signalement. J'ai relu la page Trombinoscope : le tri par section ne tient pas compte de l'année. Verdict : bug:confirmed."
expect_allowed 'a three-part version number' \
  'Le site est en 1.0.41 et PHP en 8.4.0 ; le correctif est arrivé en 1.0.39.'
expect_allowed 'a time and a date' \
  'Le journal montre une erreur le 2026-09-06 à 12:30:45.'
expect_allowed 'a PHP class and method' \
  'Pour le mainteneur : Modules\Groups\Service\ModerationService::isAvailable() lit le réglage.'
expect_allowed 'a short commit hash and a file path' \
  'Vérifié contre d3d3b93, dans core/Http/Controller/SupportController.php:574.'
expect_allowed 'a support reference and a URL' \
  'La référence SUP-ABC234 est bien lue ; voir https://github.com/xdubois-57/scoutmagic/issues/181.'
expect_allowed 'a masked query value from the extract' \
  'Le journal montre GET /reset?token=… à ip-3.'
expect_allowed 'a long markdown line without spaces in a code fence' \
  'La ligne fautive est `member_functions.scout_year_id_is_missing_from_the_join_condition_here`.'

expect_refused 'an IPv4 address' \
  'Douze échecs depuis 203.0.113.7 en dix minutes.' 'an IPv4 address' '203.0.113.7'
expect_refused 'an IPv4 address closing a sentence' \
  'Depuis 203.0.113.7.' 'an IPv4 address' '203.0.113.7'
expect_refused 'a full IPv6 address' \
  'Client 2001:0db8:85a3:0000:0000:8a2e:0370:7334.' 'an IPv6 address' '2001:0db8'
expect_refused 'a compressed IPv6 address' \
  'Client 2001:db8::1 puis ::1.' 'an IPv6 address' '2001:db8'
expect_refused 'an e-mail address' \
  'Le chef a écrit depuis chef@unite.be.' 'an e-mail address' 'chef@unite.be'
expect_refused 'a GitHub token' \
  "Jeton : ghs_$(printf 'A%.0s' $(seq 1 30))." 'a GitHub token' 'ghs_AAAA'
expect_refused 'a fine-grained GitHub token' \
  "github_pat_$(printf 'B%.0s' $(seq 1 40))" 'a GitHub token' 'github_pat_BBBB'
expect_refused 'an Anthropic key' \
  "sk-ant-oat01-$(printf 'C%.0s' $(seq 1 40))" 'an Anthropic key' 'sk-ant-oat01'
expect_refused 'a bearer header' \
  "Authorization: Bearer $(printf 'd%.0s' $(seq 1 40))" 'a bearer token' 'dddddddddd'
expect_refused 'a long unbroken credential-looking run' \
  "Secret $(printf 'x%.0s' $(seq 1 64)) found." 'a long unbroken run' 'xxxxxxxxxx'

echo
echo "${PASS_COUNT} passed, ${FAIL_COUNT} failed."
[[ "${FAIL_COUNT}" -eq 0 ]]
