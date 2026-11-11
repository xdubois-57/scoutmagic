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
#   - bidirectional controls, Unicode's whole Bidi_Control set: U+061C,
#     U+200E, U+200F, U+202A–U+202E, U+2066–U+2069;
#   - every Markdown link reference definition, `[label]: destination
#     "title"`, which GitHub renders as nothing at all — the classic
#     `[x]: # (an instruction)`. Matched as CommonMark defines it: label
#     and title may span lines (never a blank one), the destination is one
#     token, and a title, if any, is the last thing on its line — so
#     `[Vue warn]: Failed to mount component` is visible text and stays.
#     An unused definition goes whole. One a link in the text refers to
#     keeps its label and destination, so the link still works, and loses
#     its title (a hover tooltip, never shown otherwise), with the marker
#     on the next line. A footnote, `[^1]: text`, is rendered and stays.
#
# `--title` applies to an issue title, which GitHub shows as plain text:
# nothing in it is Markdown, so the link-definition rule is not applied
# there — `[Bug]: le barème` is a title, not a hidden definition.
#
# Idempotent by construction: the marker contains none of the above, so a
# second pass changes nothing — which is what keeps the `edited` event the
# rewrite itself triggers from looping (.github/workflows/issue-sanitize.yml).
#
# One filter, used in both places that hand an issue to an agent: the
# sanitizing workflow and the triage job (scripts/sanitize-issue-thread.sh).
# Tested by scripts/strip-hidden-issue-content.test.sh.

MARKER='⚠️ [contenu caché retiré automatiquement]'

mode="${1:-}"
TITLE=0
if [[ "${mode}" == "--title" ]]; then
    TITLE=1
fi

MARKER="${MARKER}" TITLE="${TITLE}" exec perl -CSDA -0777 -pe '
    my $m = $ENV{MARKER};
    utf8::decode($m) unless utf8::is_utf8($m);
    s/<!--.*?(?:-->|\z)/$m/gs;
    s/[\x{E0000}-\x{E007F}\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/$m/g;
    if (!$ENV{TITLE}) {
        # A newline, but never a blank line, may sit inside a label or title.
        my $nl    = qr/\n(?![ \t]*\n)/;
        my $label = qr/\[(?!\^)((?:[^\[\]\\\n]|\\.|$nl)+)\]/;
        my $dest  = qr/<[^<>\n]*>|[^\s<]\S*/;
        my $title = qr/"(?:[^"\\\n]|\\.|$nl)*"|\x27(?:[^\x27\\\n]|\\.|$nl)*\x27|\((?:[^()\\\n]|\\.|$nl)*\)/;
        my $def   = qr/^[ ]{0,3}$label:[ \t]*\n?[ \t]*($dest)(?:(?:[ \t]+|[ \t]*\n[ \t]*)($title))?[ \t]*$/m;
        my $norm  = sub { my $l = lc shift; $l =~ s/\s+/ /g; $l =~ s/^ | $//g; $l };
        # What the text refers to, read with every definition taken out.
        (my $rest = $_) =~ s/$def//g;
        my %used;
        $used{ $norm->($1) } = 1 while $rest =~ /\[((?:[^\[\]\\]|\\.)+)\]/gs;
        s{$def}{
            my ($whole, $l, $d, $t) = ($&, $1, $2, $3);
            !$used{ $norm->($l) } ? $m
            : defined $t ? "[$l]: $d\n$m"
            : $whole
        }ge;
    }
'
