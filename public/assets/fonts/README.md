# Fonts served to the browser

## `dejavu-sans-bold-latin.woff2`

**What it is.** A Latin subset of `modules/social/resources/fonts/DejaVuSans-Bold.ttf`,
the font the social card's title and address are drawn in. The card used to
be composed by GD on the server, which read the `.ttf` directly; from issue
#706, IT-02 the browser draws it in a `<canvas>`, so the same font has to
reach the browser — and the content security policy is `font-src 'self'`
(`Core\Http\Response`), so it is served from here and never from a font CDN.

**Why a subset.** The full `.ttf` is 709 KB. A card's text is a French
title somebody typed and the site's own address, so Latin with French
accents is the whole requirement: the subset below is 19.7 KB, 36 times
smaller, on a page a chief opens on mobile data to look at one image.

**Licence.** Unchanged — DejaVu's own, as shipped beside the source file in
`modules/social/resources/fonts/LICENSE-DejaVu.txt`. Subsetting is a
permitted modification; the licence travels with the original, which stays
in the repository.

**How to rebuild it** (a generated asset nobody can regenerate is a
liability, so this is the command, not a description of one):

```sh
pip install fonttools brotli
python3 -m fontTools.subset modules/social/resources/fonts/DejaVuSans-Bold.ttf \
  --unicodes="U+0020-007E,U+00A0-00FF,U+0152-0153,U+0178,U+2013-2014,U+2018-201A,U+201C-201E,U+2026,U+2039-203A,U+20AC" \
  --layout-features="kern,liga" \
  --flavor=woff2 \
  --output-file=public/assets/fonts/dejavu-sans-bold-latin.woff2
```

The ranges are, in order: ASCII, Latin-1 Supplement (every French accent),
`Œ`/`œ`, `Ÿ`, the en and em dashes, the curly quotes and apostrophe — `’`
is what a French keyboard and ScoutMagic's own prose use — the double
guillemets, the ellipsis `…` which the title's three-line cap appends, the
single guillemets `‹ ›`, and the euro sign. `FFTM` is dropped with a
warning: it is FontForge's timestamp table, and nothing reads it.

A character outside the subset renders as a fallback glyph rather than
nothing, so a title in an alphabet this does not cover degrades visibly
instead of vanishing. If ScoutMagic ever ships an interface in such an
alphabet, widen the ranges above rather than reaching for a CDN.
