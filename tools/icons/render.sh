#!/usr/bin/env bash
#
# render.sh - render the Hotglue editor icon set in a brand colour.
#
# Part of the theagitist/hotglue2 fork GUI revamp. Icons are Lucide
# (https://lucide.dev, ISC licence) mapped to each editor function; see
# mapping.txt and ICONS.md. This script rasterises the SVG sources into the
# module tree's .png paths so the editor picks them up unchanged (no JS edits).
#
# Usage:  render.sh <hex-colour> [out-root]
#   <hex-colour>  base glyph colour, e.g. '#002848' (standalone navy),
#                 '#e8eef0' (Telaris aurora white).
#   [out-root]    where the module tree lives (default: repo root, two levels up).
#
# Action icons render at 64px (2x: the editor's <img width=32 height=32> attrs
# downscale them crisply on retina). Toggle on/off icons render at 64px too,
# each with a green-check (on) / red-x (off) state badge composited bottom-right,
# mirroring the original on/off convention; theme.css sets background-size:32px
# so the 64px source fits the 32px toggle <div>.
#
# Requires: inkscape, imagemagick (convert/composite). GPL, see COPYING.

set -euo pipefail

COLOR="${1:?usage: render.sh <hex-colour> [out-root]}"
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="${2:-$(cd "$HERE/../.." && pwd)}"
SRC="$HERE/sources"
MAP="$HERE/mapping.txt"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

render_glyph() { # <lucide> <color> <px> <out>
	sed "s/currentColor/$2/g" "$SRC/$1.svg" > "$TMP/g.svg"
	inkscape "$TMP/g.svg" -w "$3" -h "$3" -o "$4" >/dev/null 2>&1
}

# build the two state badges once (theme-independent: state is state)
mkbadge() { # <lucide-glyph> <circle-hex> <out>
	sed -e 's/currentColor/#ffffff/g' -e 's/stroke-width="2"/stroke-width="3"/' "$SRC/$1.svg" > "$TMP/b.svg"
	inkscape "$TMP/b.svg" -w 30 -h 30 -o "$TMP/bg.png" >/dev/null 2>&1
	convert -size 34x34 xc:none -fill "$2" -draw "circle 17,17 17,1" "$TMP/circ.png"
	composite -gravity center "$TMP/bg.png" "$TMP/circ.png" "$3"
}
mkbadge check '#1a7f37' "$TMP/badge_on.png"
mkbadge x     '#c0392b' "$TMP/badge_off.png"

count=0
while read -r path lucide; do
	[[ "$path" == \#* || -z "$path" ]] && continue
	dest="$OUT/$path"
	mkdir -p "$(dirname "$dest")"
	render_glyph "$lucide" "$COLOR" 64 "$TMP/base.png"
	case "$path" in
		*-on.png)  composite -gravity SouthEast -geometry +1+1 "$TMP/badge_on.png"  "$TMP/base.png" "$dest" ;;
		*-off.png) composite -gravity SouthEast -geometry +1+1 "$TMP/badge_off.png" "$TMP/base.png" "$dest" ;;
		*)         cp "$TMP/base.png" "$dest" ;;
	esac
	count=$((count+1))
done < "$MAP"
echo "rendered $count icons in $COLOR -> $OUT"
