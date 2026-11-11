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
#   - zero-width characters U+200B–U+200D, U+2060, U+FEFF, and the
#     invisible operators U+2061–U+2064;
#   - variation selectors, which can encode bytes on the character before
#     them: U+FE00–U+FE0D and U+E0100–U+E01EF always, U+FE0E/U+FE0F (text
#     or emoji style) only in a run or next to another hidden character;
#   - bidirectional controls, Unicode's whole Bidi_Control set: U+061C,
#     U+200E, U+200F, U+202A–U+202E, U+2066–U+2069;
#   - every Markdown link reference definition, `[label]: destination
#     "title"`, which GitHub renders as nothing at all — the classic
#     `[x]: # (an instruction)`. Matched as CommonMark defines it: label
#     and title may span lines (never a blank one), the destination is one
#     token, and a title, if any, is the last thing on its line — so
#     `[Vue warn]: Failed to mount component` is visible text and stays.
#     At any indentation, behind any block quote or list markers, and
#     inside code too, like an HTML comment: every exemption for where
#     Markdown "really" ends a quote, a list or a code block was a way
#     past this rule. The definition always goes, even one a link refers
#     to. What this costs is visible, never hidden: a link falls back to
#     plain text, and a code line shaped exactly like `[label]: token`
#     shows the marker, its original in the edit history;
#   - every footnote definition, `[^1]: text`, with the lines that continue
#     it: GitHub drops one nothing refers to, and deciding what "refers"
#     means (a reference in inline code renders nothing) is the same
#     approximation as above. A real footnote shows the marker instead.
#
# `--bot` applies to a text a bot wrote. It keeps the repository's own
# markers — the exact forms its workflows write on issues, nothing looser:
# `<!-- external-source:<id> -->`, `<!-- external-source-fingerprint:<16
# hex>[:sans-analyse] -->`, `<!-- issue-fixed-comment: pull request #<n> -->`
# — and removes everything else like any text. A bot's issue can be edited
# by a person afterwards, and GitHub still names the bot as its author.
#
# Line endings are kept, each as it was: GitHub stores what its web editor
# sends with CRLF, a text can mix both, and the rules accept either rather
# than normalising — a text that only changed its line endings would be
# rewritten for nothing.
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

# The ids of Core\ExternalSource\ExternalSources, the only ones an
# `<!-- external-source:<id> -->` marker may carry: a looser pattern would
# let a person editing a bot's issue keep any kebab-case sentence hidden.
# tests/Security/BacklogProjectScriptsTest.php fails when this list and the
# register differ, so a new source lands with its id here.
# BEGIN external-source ids
SOURCE_IDS='
    federal-fees
    federal-scout-path
    federal-data-protection
    federal-federation
    federal-insignia-placement
    federal-adult-quality-code
    federal-data-protection-file
    federal-desk
    osrm-demo-server-policy
    federal-home
    anthropic-console
    anthropic-api-keys
    anthropic-privacy
    anthropic-commercial-terms
    anthropic-privacy-short
    mistral-console
    mistral-api-keys
    mistral-privacy
    mistral-dpa
    mistral-terms-privacy
    scaleway-console
    scaleway-api-keys
    scaleway-buckets
    scaleway-applications
    scaleway-policies
    scaleway-privacy
    scaleway-contracts
    scaleway-model-catalogue
    hetzner-console
    cloudflare-dashboard
    cloudflare-privacy
    ovh-manager
    ovh-create-app
    google-cloud-console
    google-app-passwords
'
# END external-source ids
OWN_IDS="$(printf '%s' "${SOURCE_IDS}" | tr -s ' \n' '\n' | grep -v '^$' | paste -sd '|' -)"

MARKER="${MARKER}" TITLE="${TITLE}" BOT="${BOT}" OWN_IDS="${OWN_IDS}" exec perl -CSDA -0777 -pe '
    my $m = $ENV{MARKER};
    utf8::decode($m) unless utf8::is_utf8($m);
    my $own = qr/<!-- (?:external-source:(?:$ENV{OWN_IDS})|external-source-fingerprint:[0-9a-f]{16}(?::sans-analyse)?|issue-fixed-comment: pull request #[0-9]+) -->/;
    s{(<!--.*?(?:-->|\z))}{ my $c = $1; $ENV{BOT} && $c =~ m{\A$own\z} ? $c : $m }gse;
    # Invisible on their own. U+FE0E/U+FE0F choose text or emoji style, and
    # one alone after a character is ordinary (the ⚠️ of the marker ends in
    # one): only a run of them, or one touching another hidden character,
    # can carry anything, so only that goes.
    my $hidden = qr/[\x{E0000}-\x{E007F}\x{E0100}-\x{E01EF}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FE00}-\x{FE0D}\x{FEFF}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/;
    my $style  = qr/[\x{FE0E}\x{FE0F}]/;
    s/(?:$hidden|$style)*$hidden(?:$hidden|$style)*|$style{2,}/$m/g;
    if (!$ENV{TITLE}) {
        # A newline, but never a blank line, may sit inside a label or title.
        my $nl    = qr/\r?\n(?![ \t]*\r?\n)/;
        # Any indentation and any block quote or list markers, nested or
        # lazy: approximating where CommonMark ends a container is exactly
        # how a hidden definition slipped past each earlier version.
        my $cont  = qr/(?:[ \t]|>|[-*+](?=[ \t])|\d{1,9}[.)](?=[ \t]))*/;
        my $label = qr/\[(?!\^)(?:[^\[\]\\\r\n]|\\.|$nl)+\]/;
        my $dest  = qr/<[^<>\r\n]*>|[^\s<]\S*/;
        my $title = qr/"(?:[^"\\\r\n]|\\.|$nl)*"|\x27(?:[^\x27\\\r\n]|\\.|$nl)*\x27|\((?:[^()\\\r\n]|\\.|$nl)*\)/;
        # A line ends in LF or CRLF, and keeps whichever it had.
        # A footnote definition, `[^x]: text`: GitHub drops one nothing
        # refers to, and a reference only counts where Markdown renders it,
        # so it always goes — its first line, the lines that run on from it
        # (lazy continuation), and indented paragraphs after a blank line.
        my $line  = qr/[^\r\n]*/;
        my $more  = qr/(?:\r?\n(?![ \t]*\r?$)$line)*/m;
        s/^($cont)\[\^[^\]\r\n]+\]:$line$more(?:(?:\r?\n[ \t]*)*\r?\n(?:[ ]{4}|\t)$line$more)*/$1$m/gm;
        s/^($cont)$label:[ \t]*(?:\r?\n)?[ \t]*(?:$dest)(?:(?:[ \t]+|[ \t]*\r?\n[ \t]*)(?:$title))?[ \t]*(?=\r?$)/$1$m/gm;
    }
'
