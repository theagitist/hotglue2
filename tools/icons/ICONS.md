# Editor icon set

The editor menu icons in this fork are [Lucide](https://lucide.dev) glyphs,
mapped to each Hotglue editor function and rendered into the module tree's
`.png` paths so the editor uses them with no JavaScript changes.

## Licence

Lucide is published under the **ISC licence** (a permissive, GPL-compatible
licence; Lucide is a fork of Feather icons, MIT). The SVG sources in `sources/`
are unmodified Lucide glyphs. ISC permits use, copy, and redistribution with the
copyright notice retained:

```
ISC License

Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as part of
Feather (MIT). All other copyright (c) for Lucide are held by Lucide Contributors
2022.

Permission to use, copy, modify, and/or distribute this software for any purpose
with or without fee is hereby granted, provided that the above copyright notice
and this permission notice appear in all copies.
```

The render script and the mapping are part of this fork and covered by the
project's GPL-3.0 licence (see `COPYING`).

## Theming

Icons are baked per deployment (see the GUI-revamp work item). `render.sh <hex>`
rasterises the whole set in one brand colour:

- **Standalone** (this `main` branch): navy `#002848` (the Hotglue logo colour).
- **Telaris-embedded** (`telaris` branch): Aurora white `#e8eef0` on the Void
  background, with Wormhole mint `#00ffcc` carried by `css/theme.css` for the
  active state and focus, per the Telaris brand book (mint is a signal, never a
  fill).

State toggles (`*-on.png` / `*-off.png`) get a green-check / red-x badge
composited bottom-right, mirroring the original on/off convention, so state stays
legible without the old green box. The badges are theme-independent.

## Regenerate

```
tools/icons/render.sh '#002848'        # standalone navy (default out-root = repo root)
tools/icons/render.sh '#e8eef0'        # Telaris aurora (run on the telaris branch)
```

Requires `inkscape` and `imagemagick`. `mapping.txt` is the function -> Lucide
map; edit it (and re-run) to change which glyph a function uses.
