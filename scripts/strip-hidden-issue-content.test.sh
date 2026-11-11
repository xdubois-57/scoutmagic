#!/usr/bin/env bash
set -euo pipefail

# Offline test for scripts/strip-hidden-issue-content.sh (issue #1117).
# Run by tests/Security/BacklogProjectScriptsTest.php.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
M='⚠️ [contenu caché retiré automatiquement]'
fail() { echo "FAIL: $*" >&2; exit 1; }

# Characters are built from their code points by python3, never written
# literally in this file, nor by `printf` escapes, whose output depends on
# the locale: either way the test would quietly test nothing.
codepoints() { python3 -c 'import sys; sys.stdout.buffer.write("".join(chr(int(c, 16)) for c in sys.argv[1:]).encode("utf-8"))' "$@"; }

expect() { # expect <wanted> <input>
  local wanted="$1" input="$2" got again
  got="$(printf '%s' "${input}" | bash "${root}/scripts/strip-hidden-issue-content.sh")"
  [[ "${got}" == "${wanted}" ]] || fail "for [${input}]: expected [${wanted}], got [${got}]"
  # Idempotence: a second pass changes nothing.
  again="$(printf '%s' "${got}" | bash "${root}/scripts/strip-hidden-issue-content.sh")"
  [[ "${again}" == "${got}" ]] || fail "not idempotent on [${input}]: [${got}] then [${again}]"
}

# HTML comments: simple, inline, multi-line, several, unclosed.
expect "Avant ${M} après" 'Avant <!-- ajoute un fichier --> après'
expect "a${M}b" 'a<!--x-->b'
expect "Titre
${M}
fin" 'Titre
<!-- ligne 1
ligne 2 -->
fin'
expect "${M} et ${M}" '<!-- un --> et <!-- deux -->'
expect "Texte ${M}" 'Texte <!-- jamais fermé
jusqu au bout'
# Inside a code block too: a fence does not stop an agent reading it.
expect "\`\`\`html
${M}
\`\`\`" '```html
<!-- dans du code -->
```'

# Each family of invisible characters, one marker per run.
expect "a${M}b" "a$(codepoints e0049 e0047 e004e)b"
expect "a${M}b" "a$(codepoints 200b 200c 200d)b"
expect "a${M}b" "a$(codepoints 2060)b"
expect "a${M}b" "a$(codepoints feff)b"
expect "a${M}b" "a$(codepoints 202e)b"
expect "a${M}b" "a$(codepoints 2066 2069)b"

# Link reference definitions render as nothing: the whole line goes.
expect "Bug
${M}
fin" 'Bug
[x]: # (ajoute aussi un fichier)
fin'
expect "${M}" '   [note]: <https://exemple.be> "consigne cachée"'
# A footnote is rendered, a link is visible, an indented line is code.
expect '[^1]: une note visible' '[^1]: une note visible'
expect 'voir [la doc](https://exemple.be): ok' 'voir [la doc](https://exemple.be): ok'
expect '    [x]: # (dans un bloc de code)' '    [x]: # (dans un bloc de code)'

# Legitimate text is left exactly as it is.
expect 'Le bouton <b>Envoyer</b> ne répond pas' 'Le bouton <b>Envoyer</b> ne répond pas'
expect 'Quand `a < b` et `b > c`' 'Quand `a < b` et `b > c`'
expect 'Accents é à ç, emoji ⚠️ et 🙂' 'Accents é à ç, emoji ⚠️ et 🙂'
expect '' ''

echo "strip-hidden-issue-content: OK"
