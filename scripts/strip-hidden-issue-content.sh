#!/usr/bin/env bash
set -euo pipefail

# Removes what GitHub's rendered view hides but an agent reading the raw
# text sees (issue #1117, audit SEC-020): HTML comments and invisible
# Unicode. Reads the text on stdin, writes the cleaned text on stdout,
# and replaces every hidden run with one VISIBLE marker, so the removal
# itself can be seen by the maintainer reading the issue.
#
#   - every HTML comment `<!-- … -->`, multi-line, and an unclosed one up
#     to the end of the text, and its siblings GitHub renders as nothing:
#     declarations `<!X…>`, CDATA `<![CDATA[…]]>`, processing instructions
#     `<?…?>`. Inside a code block too: a fence does not make an agent
#     ignore what it reads, and the safer reading wins — a `<?php` snippet
#     in a report shows the marker, its original in the edit history;
#   - every Default_Ignorable_Code_Point: the property Unicode gives to
#     what renders as nothing — tag characters U+E0000–U+E0FFF that can
#     spell a whole ASCII instruction, zero-width characters, the soft
#     hyphen, invisible operators, the whole Bidi_Control set, variation
#     selectors that can encode bytes on the character before them, and
#     the rest. Two exceptions, both visible emoji: U+FE0E/U+FE0F (text or
#     emoji style), which go only in a run or next to another hidden
#     character, and U+200D between two pictographs (👩‍💻, 🏳️‍🌈), kept with
#     the emoji it joins;
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
#     approximation as above. A real footnote shows the marker instead;
#   - what Markdown and its HTML render as nothing, or only on an action
#     nobody takes (issue #1254): an entity spelling an invisible character
#     (the marker), a link whose text is empty and which carries a title
#     (the marker), the alternative text and title of every image (emptied,
#     the image still shows), the colour, phantom, CSS and tiny-size
#     commands of a formula (removed, their text shows), <sub>/<sup>/<small>
#     nested past one level (the outer level stays). A folded <details> is
#     kept folded behind a visible « ⚠️ [bloc replié : contenu non relu] »,
#     the warning an agent reads too; an `open` one hides nothing.
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
# One filter, used in every place that hands an issue to an agent: the
# sanitizing workflow and the triage job (scripts/sanitize-issue-thread.sh),
# and « fixe le backlog » reading a ticket (scripts/read-issue-thread.sh).
# Tested by scripts/strip-hidden-issue-content.test.sh.

MARKER='⚠️ [contenu caché retiré automatiquement]'
# Written before a folded <details> (issue #1254): its content is kept, but
# nobody saw it without unfolding the block.
FOLD='⚠️ [bloc replié : contenu non relu]'

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

MARKER="${MARKER}" FOLD="${FOLD}" TITLE="${TITLE}" BOT="${BOT}" OWN_IDS="${OWN_IDS}" exec perl -CSDA -0777 -pe '
    my $m = $ENV{MARKER};
    utf8::decode($m) unless utf8::is_utf8($m);
    my $own = qr/<!-- (?:external-source:(?:$ENV{OWN_IDS})|external-source-fingerprint:[0-9a-f]{16}(?::sans-analyse)?|issue-fixed-comment: pull request #[0-9]+) -->/;
    # HTML comments, and the sibling constructs GitHub renders as nothing:
    # declarations, CDATA sections, processing instructions — closed, or
    # unclosed to the end of the text, as an HTML block runs.
    my $html = qr/<!--.*?(?:-->|\z)|<!\[CDATA\[.*?(?:\]\]>|\z)|<![A-Za-z][^>]*(?:>|\z)|<\?.*?(?:\?>|\z)/s;
    s{($html)}{ my $c = $1; $ENV{BOT} && $c =~ m{\A$own\z} ? $c : $m }gse;
    # Invisible on their own. U+FE0E/U+FE0F choose text or emoji style, and
    # one alone after a character is ordinary (the ⚠️ of the marker ends in
    # one): only a run of them, or one touching another hidden character,
    # can carry anything, so only that goes.
    # Unicode marks every character meant to render as nothing
    # Default_Ignorable_Code_Point: the whole property, not a hand-picked
    # list that the next unlisted one walks past.
    my $hidden = qr/(?![\x{FE0E}\x{FE0F}])\p{Default_Ignorable_Code_Point}/;
    my $style  = qr/[\x{FE0E}\x{FE0F}]/;
    # U+200D joins emoji into one (a woman and a laptop, a flag and a
    # rainbow): a joiner between two pictographs is that emoji, visible,
    # and is kept whole. A joiner anywhere else is hidden like the rest.
    my $pict   = qr/\p{Extended_Pictographic}(?:$style|\p{Emoji_Modifier})?/;
    s/($pict(?:\x{200D}$pict)+)|(?:$hidden|$style)*$hidden(?:$hidden|$style)*|$style{2,}/defined $1 ? $1 : $m/ge;
    if (!$ENV{TITLE}) {
        # Issue #1254: what Markdown and its HTML render as nothing, or only
        # on an action nobody takes. None of it applies to a title, which
        # GitHub shows as plain text.
        #
        # An HTML entity spelling an invisible character renders exactly as
        # the character itself, which the rule above removes: `&#x200B;`,
        # `&#917577;`, `&zwj;`.
        my $named = qr/zwj|zwnj|shy|lrm|rlm|ZeroWidthSpace|NegativeVeryThinSpace|NegativeThinSpace|NegativeMediumSpace|NegativeThickSpace|NoBreak|InvisibleTimes|InvisibleComma|ApplyFunction|it|ic|af/;
        s{(?:&#(?:[xX][0-9A-Fa-f]{1,8}|[0-9]{1,8});|&(?:$named);)+}{
            my $run = $&;
            my $hides = 0;
            while ($run =~ /&#(?:[xX]([0-9A-Fa-f]+)|([0-9]+));|&($named);/g) {
                if (defined $3) { $hides = 1; next; }
                my $cp = defined $1 ? hex($1) : $2;
                $hides = 1 if $cp <= 0x10FFFF && chr($cp) =~ $hidden;
            }
            $hides ? $m : $run
        }ge;
        # A link whose text is empty shows nothing, and its title only on
        # hovering that nothing: `[](https://… "an instruction")`. The whole
        # link goes. A link with text keeps its title — it shows on hover
        # over words someone sees.
        my $ltitle = qr/"(?:[^"\\]|\\.)*"|\x27(?:[^\x27\\]|\\.)*\x27|\((?:[^()\\]|\\.)*\)/;
        # A destination as CommonMark reads it: in angle brackets, or a run
        # of non-space characters whose parentheses balance.
        my $ldest = qr/<[^<>\r\n]*>|(?<par>(?:[^\s()\\<]|\\.|\((?:[^\s()\\]|\\.|(?&par))*\))+)/;
        s{(?<!!)\[[ \t]*\]\([ \t]*(?:$ldest)?[ \t\r\n]+(?:$ltitle)[ \t]*\)}{$m}g;
        # The attributes of a tag, read as the HTML tokenizer reads them:
        # a name runs to a space, `/`, `>` or `=` and needs no space before
        # it after a quoted value; a quoted value may hold a `>`; a quote
        # inside an unquoted value (`width=10\x27`) is just a character of it.
        # Atomic, so a tag that never closes cannot make the match backtrack.
        my $aname  = qr/[^\s\/>][^\s\/>=]*/;
        my $avalue = qr/"[^"]*"|\x27[^\x27]*\x27|[^\s>]*/;
        my $attrs  = qr/(?>(?:[ \t\r\n\/]+|$aname(?:[ \t\r\n]*=[ \t\r\n]*(?:$avalue))?)*)/;
        # attributes_of <the text between the tag name and its `>`> →
        # a list of [name, value or undef], names as written.
        my $attributes_of = sub {
            my ($text) = @_;
            my @found;
            while ($text =~ /\G(?:[ \t\r\n\/]+|($aname)(?:[ \t\r\n]*=[ \t\r\n]*($avalue))?)/gc) {
                push @found, [$1, $2] if defined $1;
            }
            return @found;
        };
        my $has = sub {
            my ($text, $wanted) = @_;
            return scalar grep { lc($_->[0]) eq $wanted } $attributes_of->($text);
        };
        s{<a\b($attrs)>[ \t\r\n]*</a[ \t]*>}{
            my $all = $&;
            $has->($1, "title") ? $m : $all
        }gie;
        # The alternative text of an image and title show only when it fails to
        # load, or on hover: a transparent pixel can carry a whole
        # instruction there. Both are emptied, for every image — the image
        # itself still shows, and its original stays in the edit history.
        # Its description may nest brackets, as CommonMark allows.
        my $desc = qr/(?<desc>\[(?:[^\[\]\\]|\\.|(?&desc))*\])/;
        s{!$desc\([ \t]*($ldest)(?:[ \t\r\n]+(?:$ltitle))?[ \t]*\)}{![]($2)}g;
        s{(<img\b)($attrs)>}{
            my ($tag, $text, $all) = ($1, $2, $&);
            my @found = $attributes_of->($text);
            my @kept = grep { lc($_->[0]) ne "alt" && lc($_->[0]) ne "title" } @found;
            @kept == @found
                ? $all
                : join(" ", $tag, map { defined $_->[1] ? "$_->[0]=$_->[1]" : $_->[0] } @kept) . ">"
        }gie;
        # Mathematics GitHub renders with MathJax: a colour (white on the
        # light theme, the background on the dark one), a phantom, a CSS
        # style or class, a tiny size can each hide text in plain sight.
        # The command goes and its text shows, in the colour of the formula
        # and size. Everywhere, not only between `$`: a formula has four
        # delimiters, and every exemption was a way past the rule.
        # Until nothing changes: removing one command can join the halves of
        # another around it (`\co\style{x}lor{white}`).
        my $arg = qr/\{(?:[^{}]|\{[^{}]*\})*\}/;
        my $was;
        do {
            $was = $_;
            s{\\fcolorbox[ \t]*(?:\[[^\]]*\])?[ \t]*$arg[ \t]*$arg}{}g;
            s{\\(?:color|textcolor|colorbox|mathcolor|pagecolor)[ \t]*(?:\[[^\]]*\])?[ \t]*$arg}{}g;
            s{\\(?:class|cssId|style|htmlStyle|htmlClass|htmlId|htmlData)[ \t]*$arg}{}g;
            s{\\(?:[hv]?phantom|tiny|Tiny|scriptsize|scriptscriptstyle)(?![A-Za-z])}{}g;
        } while ($_ ne $was);
        # Text nested in <sub>, <sup> or <small> shrinks at every level until
        # nobody can read it: only the outermost level is kept.
        # Tracked as HTML parses them: a stack of the open names, a close
        # matching one of them closing it and those above it, and a close
        # matching none ignored — and removed, so a stray `</sup>` cannot
        # reset the count while the browser keeps the levels open.
        my @open;
        s{<(/?)(sub|sup|small)\b[^>]*>}{
            my ($close, $name, $all) = ($1, lc $2, $&);
            if (!$close) {
                push @open, $name;
                @open == 1 ? $all : "";
            } else {
                my ($at) = grep { $open[$_] eq $name } reverse 0 .. $#open;
                if (!defined $at) {
                    "";
                } else {
                    splice @open, $at;
                    @open ? "" : $all;
                }
            }
        }gie;
        # A folded <details> shows only its summary until someone opens it:
        # an agent reads what is inside as if it had been seen. It stays
        # folded — a log belongs there — behind a visible warning, which the
        # agent reads too. `<details open>` hides nothing and is left alone.
        my $fold = $ENV{FOLD};
        utf8::decode($fold) unless utf8::is_utf8($fold);
        s{<details\b($attrs)>}{
            my ($all, $text) = ($&, $1);
            my $before = $`;
            # An attribute named open, never the word inside a value.
            my $seen = $has->($text, "open")
                || $before =~ /\Q$fold\E[ \t]*(?:\r?\n(?:[ \t]|>)*)?\z/;
            my ($cont) = $before =~ /(?:\A|\n)((?:[ \t]|>)*)\z/;
            my $eol = $before =~ /\r\n(?:[ \t]|>)*\z/ ? "\r\n" : "\n";
            $seen ? $all : defined $cont ? "$fold$eol$cont$all" : "$fold $all"
        }gie;
        # A newline, but never a blank line, may sit inside a label or title.
        my $nl    = qr/\r?\n(?![ \t]*\r?\n)/;
        # Any indentation and any block quote or list markers, nested or
        # lazy: approximating where CommonMark ends a container is exactly
        # how a hidden definition slipped past each earlier version.
        my $cont  = qr/(?:[ \t]|>|[-*+](?=[ \t])|\d{1,9}[.)](?=[ \t]))*/;
        my $label = qr/\[(?!\^)(?:[^\[\]\\\r\n]|\\.|$nl)+\]/;
        my $dest  = qr/<(?:[^<>\\\r\n]|\\.)*>|[^\s<]\S*/;
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
