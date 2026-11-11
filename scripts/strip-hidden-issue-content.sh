#!/usr/bin/env bash
set -euo pipefail

# Removes what GitHub's rendered view hides but an agent reading the raw
# text sees (issue #1117, audit SEC-020): HTML comments and invisible
# Unicode. Reads the text on stdin, writes the cleaned text on stdout,
# and replaces every hidden run with one VISIBLE marker, so the removal
# itself can be seen by the maintainer reading the issue.
#
#   - every HTML comment `<!-- … -->`, multi-line, and an unclosed one up
#     to the end of the text. Inside a code block too: a fence does not
#     make an agent ignore what it reads, and the safer reading wins;
#   - Unicode "tag" characters U+E0000–U+E007F, which can spell a whole
#     ASCII instruction without drawing anything;
#   - zero-width characters U+200B–U+200D, U+2060, U+FEFF;
#   - bidirectional controls U+202A–U+202E, U+2066–U+2069.
#
# Idempotent by construction: the marker contains none of the above, so a
# second pass changes nothing — which is what keeps the `edited` event the
# rewrite itself triggers from looping (.github/workflows/issue-sanitize.yml).
#
# One filter, used in both places that hand an issue to an agent: the
# sanitizing workflow and the triage job (scripts/sanitize-issue-thread.sh).
# Tested by scripts/strip-hidden-issue-content.test.sh.

MARKER='⚠️ [contenu caché retiré automatiquement]'

MARKER="${MARKER}" exec perl -CSDA -0777 -pe '
    my $m = $ENV{MARKER};
    utf8::decode($m) unless utf8::is_utf8($m);
    s/<!--.*?(?:-->|\z)/$m/gs;
    s/[\x{E0000}-\x{E007F}\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/$m/g;
'
