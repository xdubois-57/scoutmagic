#!/bin/bash
set -euo pipefail

# Usage:
#
#   printf '%s' "${comment}" | ./scripts/triage-comment-gate.sh
#
# Exits 0 when the comment may be posted on a public issue, 1 with an
# `::error` annotation when it must not. Reads the comment on stdin —
# never as an argument, so no quoting in a reporter's words reaches a
# shell — and prints nothing of it: the annotation names the CATEGORY of
# what was found, never the match, because the whole point is that the
# match must not appear in a public place.
#
# WHY THIS EXISTS. The triage agent may now read the anonymised extract
# of a support ticket's archive (ARCHITECTURE.md §8.49sexies), and the
# verdict it returns is posted, by the workflow, on a public issue. The
# skill file tells it never to quote an address; a sentence in a prompt
# is a request, and this is the boundary. The extract is anonymised
# before it leaves the support site, so what this refuses in practice is
# the residue — an address in a line the scrubber did not recognise, a
# reporter's e-mail quoted back at them — and, more importantly, the
# one thing an agent that can read the runner's filesystem must never be
# able to publish: a credential.
#
# Refused:
#   - an IPv4 address, or an IPv6 address in its full or compressed form;
#   - an e-mail address;
#   - anything shaped like a GitHub token, an Anthropic key, a bearer
#     token, or a long unbroken credential-looking run.
#
# Deliberately NOT refused: a three-part version number, a time, a PHP
# `Class::method`, a short commit hash — the things a triage comment
# legitimately carries. Each is a test case in
# scripts/triage-comment-gate.test.sh, next to each thing that is.

comment="$(cat)"

refuse() {
  echo "::error title=Verdict comment withheld::The triage comment contained ${1}. It was not posted; the issue keeps triage:pending. Read the run's transcript to see what the agent wrote, and fix the skill file or the scrubber before re-running." >&2
  exit 1
}

# Patterns are PCRE (`grep -P`), applied to the whole comment; `-q`
# because nothing of the match may be printed.
#
# An IPv4 address: four dotted groups not glued to a longer dotted
# number, so `1.0.41` is not one and neither is `1.0.41.2`'s tail.
if printf '%s' "${comment}" | grep -qP '(?<!\d\.)(?<!\w)(?:\d{1,3}\.){3}\d{1,3}(?!\.\d)(?!\w)'; then
  refuse 'an IPv4 address'
fi

# IPv6, eight groups.
if printf '%s' "${comment}" | grep -qiP '(?<![\w:])(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}(?![\w:])'; then
  refuse 'an IPv6 address'
fi

# IPv6, compressed: some groups, `::`, some groups, with at least one
# digit in the run — which is what keeps `Service::method` out.
if printf '%s' "${comment}" | grep -qiP '(?<![\w:])(?=[0-9a-f:]*\d)(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?(?![\w:])'; then
  refuse 'an IPv6 address'
fi

# An e-mail address.
if printf '%s' "${comment}" | grep -qP '[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}'; then
  refuse 'an e-mail address'
fi

# Credentials by shape: GitHub's prefixed tokens, Anthropic keys, a
# bearer header, and a long unbroken run of token characters.
if printf '%s' "${comment}" | grep -qP '\b(?:gh[pousr]|github_pat)_[A-Za-z0-9_]{20,}'; then
  refuse 'something shaped like a GitHub token'
fi
if printf '%s' "${comment}" | grep -qP 'sk-ant-[A-Za-z0-9_\-]{20,}'; then
  refuse 'something shaped like an Anthropic key'
fi
if printf '%s' "${comment}" | grep -qiP '\bbearer\s+[A-Za-z0-9._\-]{20,}'; then
  refuse 'something shaped like a bearer token'
fi
if printf '%s' "${comment}" | grep -qP '(?<![A-Za-z0-9_\-/.])[A-Za-z0-9_\-]{60,}(?![A-Za-z0-9_\-/.])'; then
  refuse 'a long unbroken run that looks like a credential'
fi

exit 0
