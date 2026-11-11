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
#     Inside a block quote or a list item too (`> [x]: …`, `- [x]: …`),
#     which hide it just the same. The definition always goes, even one a
#     link refers to: telling a real reference from any other bracket is a
#     parser's job, and a link that falls back to plain text next to the
#     marker is a visible loss, never a hidden one. Inside a fenced code
#     block it stays, because there GitHub shows it as text. A footnote,
#     `[^1]: text`, is rendered and stays.
#
# `--bot` applies to a text a bot wrote. It keeps the repository's own
# markers — the exact forms its workflows write on issues, nothing looser:
# `<!-- external-source:<id> -->`, `<!-- external-source-fingerprint:<16
# hex>[:sans-analyse] -->`, `<!-- issue-fixed-comment: pull request #<n> -->`
# — and removes everything else like any text. A bot's issue can be edited
# by a person afterwards, and GitHub still names the bot as its author.
#
# Line endings are kept: GitHub stores what its web editor sends with CRLF,
# the rules work on LF, and a text that only changed its line endings
# would be rewritten for nothing.
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
BOT=0
case "${mode}" in
    --title) TITLE=1 ;;
    --bot) BOT=1 ;;
    "") ;;
    *) echo "usage: $0 [--title|--bot]" >&2; exit 2 ;;
esac

MARKER="${MARKER}" TITLE="${TITLE}" BOT="${BOT}" exec perl -CSDA -0777 -pe '
    my $m = $ENV{MARKER};
    utf8::decode($m) unless utf8::is_utf8($m);
    my $crlf = s/\r\n/\n/g;
    my $own = qr/<!-- (?:external-source:[a-z0-9-]+|external-source-fingerprint:[0-9a-f]{16}(?::sans-analyse)?|issue-fixed-comment: pull request #[0-9]+) -->/;
    s{(<!--.*?(?:-->|\z))}{ my $c = $1; $ENV{BOT} && $c =~ m{\A$own\z} ? $c : $m }gse;
    s/[\x{E0000}-\x{E007F}\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/$m/g;
    if (!$ENV{TITLE}) {
        # A newline, but never a blank line, may sit inside a label or title.
        my $nl    = qr/\n(?![ \t]*\n)/;
        # Block quote and list item markers, nested any number of times.
        my $cont  = qr/(?:[ ]{0,3}(?:>[ ]?|[-*+][ ]+|\d{1,9}[.)][ ]+))*/;
        my $label = qr/\[(?!\^)(?:[^\[\]\\\n]|\\.|$nl)+\]/;
        my $dest  = qr/<[^<>\n]*>|[^\s<]\S*/;
        my $title = qr/"(?:[^"\\\n]|\\.|$nl)*"|\x27(?:[^\x27\\\n]|\\.|$nl)*\x27|\((?:[^()\\\n]|\\.|$nl)*\)/;
        my $def   = qr/^($cont)[ ]{0,3}$label:[ \t]*\n?[ \t]*(?:$dest)(?:(?:[ \t]+|[ \t]*\n[ \t]*)(?:$title))?[ \t]*$/m;
        # Fenced code blocks are copied as they are; the rest is filtered.
        # An unclosed fence runs to the end of the text, as CommonMark has it.
        my ($out, $prose, $fence) = (q{}, q{}, undef);
        for my $line (split /(?<=\n)/, $_) {
            if (defined $fence) {
                $out .= $line;
                $fence = undef if $line =~ /^$cont[ ]{0,3}$fence[ \t]*\n?\z/;
            } elsif ($line =~ /^$cont[ ]{0,3}(?:(`{3,})(?![^\n]*`)|(~{3,}))/) {
                # A backtick fence carries no backtick after it: ``` a ```
                # on one line is inline code in a paragraph, never a fence.
                my $open = $1 // $2;
                $prose =~ s/$def/$1$m/g;
                $out .= $prose . $line;
                $prose = q{};
                $fence = quotemeta(substr($open, 0, 1)) . "{" . length($open) . ",}";
            } else {
                $prose .= $line;
            }
        }
        $prose =~ s/$def/$1$m/g;
        $_ = $out . $prose;
    }
    s/\n/\r\n/g if $crlf;
'
