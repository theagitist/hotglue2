# Mobile scale-to-fit for Hotglue (`module_mobile.inc.php`)

Status: live on hotglue.polivoxia.ca since 2026-06-24.
Author note: this document is the porting guide. Read it before copying the
feature into the Telaris-embedded Hotglue, or before re-applying it after a
Hotglue upstream update.

## The problem

Hotglue positions every object with absolute pixel coordinates (`object-left`,
`object-top`, `object-width` become inline `position:absolute; left/top/width`).
A page authored at, say, 2000-2600px wide is therefore unusable on a phone:

- Vanilla Hotglue emits **no** `<meta name="viewport">`, so mobile browsers fall
  back to the legacy ~980px layout viewport and shrink the whole page to a
  sliver: tiny text, pinch-zoom, sideways scrolling.

You cannot make a freehand absolute-position canvas "reflow" without scrambling
it (DOM order is object *creation* order, not reading order). So the faithful
fix is to **scale the whole canvas down to fit**, preserving the exact layout.

## The solution

`module_mobile.inc.php` (a single drop-in module, no core files modified). On the
**published view only**, it:

1. Emits `<meta name="viewport" content="width=device-width, initial-scale=1">`.
2. Wraps the page body in a `#hg-clip` (overflow hidden) + `#hg-scale` pair and,
   when the viewport is narrower than the authored design width, applies
   `transform: scale(s)` with `transform-origin: 0 0`.
3. Clamps `s` to a floor `MOBILE_MIN_SCALE` (default `0.6`). Above the floor the
   page fits the width exactly (no horizontal scroll). At the floor it stops
   shrinking and the page scrolls horizontally, so text never becomes
   microscopic. This is the deliberate fidelity-vs-readability tradeoff: a very
   wide page (e.g. 2600px) on a 390px phone sits at the 0.6 floor and the user
   pans sideways, rather than reading 5px text.

No reflow. Layout fidelity is 100%. The editor is never touched.

### Tuning

Override the floor in `user-config.inc.php`:

```php
@define('MOBILE_MIN_SCALE', '0.5');   // lower = fits wider pages before h-scroll, smaller text
```

- `1.0` = never shrink (effectively disables scaling).
- `0.0` = always fit exactly to width (can produce very small text on wide pages).

## Why this is a module and not a core edit

Hotglue's module system does all the wiring for us, so there are **zero** edits
to any shipped file:

- `load_modules()` (in `modules.inc.php`) scans the program directory for
  `module_*.inc.php` and `include`s each one.
- `invoke_hook($hook, $args)` then auto-calls any function named
  `<module>_<hook>` that exists.

So defining `mobile_render_page_late($args)` is enough to run at the end of view
rendering. The relevant hook is fired in `module_glue.inc.php` `render_page()`:

```php
invoke_hook('render_page_late', array('page'=>..., 'edit'=>$args['edit']));
```

We bail when `$args['edit']` is truthy, so the editor is untouched. The injected
`<meta>`/`<script>` are added via `html_add_head_inline()` / `html_add_js_inline()`
and end up inside the cached HTML, so caching (`CACHE_TIME`) is safe.

The injected script runs from `<head>` (that is where inline JS is emitted), so
it guards on `document.readyState` and defers all DOM work to `DOMContentLoaded`;
the `<body>` does not exist yet at parse time.

**To remove the feature entirely:** delete `module_mobile.inc.php`. Nothing else
changes.

## Dependencies (what to recheck on an upstream update)

The module relies only on long-stable Hotglue internals. If a future upstream
release changes any of these, adapt accordingly:

1. **Module auto-discovery + `<module>_<hook>` naming** (`modules.inc.php`). Stable
   since 1.0.
2. **The `render_page_late` hook**, fired in `render_page()` with an `edit` flag
   in `$args` (`module_glue.inc.php`). If renamed/removed, hook a different
   late-in-view point (e.g. `render_page_early`, or a wrapper around
   `html_finalize`).
3. **HTML builder API**: `html_add_head_inline($def, $prio)` and
   `html_add_js_inline($code, $prio, $reason)` (`html.inc.php`). Note
   `html_add_js_inline` only emits when `$reason` is non-empty (we pass one).
4. **Objects are absolutely-positioned direct children of `<body>`.** The client
   scaler measures the design box from `#hg-scale.children` offsets. Still true in
   1.0.4.

None of these have changed across the 1.0.x line.

## Porting to the Telaris-embedded Hotglue

The embedded fork runs under a `/hg/` sub-path with a custom auth bridge. The
module is portable, but check the following:

1. **Copy the file** into the embedded Hotglue program directory (wherever the
   other `module_*.inc.php` live). It auto-loads; no registration needed.
2. **View vs edit is independent of the auth bridge.** Edit mode is determined by
   `controller_edit` vs `controller_show` (the `edit` flag), not by whether the
   request is authenticated. So "view mode only" still holds even though the
   Telaris bridge may always present as authed. Verify `render_page_late` still
   fires in the embedded render path.
3. **Base path.** The scaler uses no URLs, and the viewport meta is path-agnostic,
   so the `/hg/` sub-path is fine.
4. **Content Security Policy.** The standalone vhost sets **no** CSP, so the inline
   `<script>`/`<meta>` work as-is. If the Telaris site sets a CSP header (likely),
   the inline script needs either `'unsafe-inline'` in `script-src` or a nonce.
   If a nonce is required, switch from `html_add_js_inline` to an external
   `.js` file served by the app, or add the nonce to the emitted tag.
5. **If the fork already injects a viewport meta or its own responsive layer,**
   drop step 1 of the module (the meta) to avoid duplicates.

## Demo page used to test it

A deliberately wide (~2600px) and tall (~4726px) social-justice themed page was
created as the live start page (`start.head`) to exercise the scaling. It is
**content, not code**: object files under `content/start/head/` plus images in
`content/start/shared/`. Remove it by deleting `content/start/` and
`content/startpage` (or replace via the editor).

Two non-obvious gotchas hit while authoring object files directly on disk (they
matter if you script content rather than use the editor):

- **Attribute format is `key:value` with NO space after the colon.** Hotglue's
  `load_object()` splits on `:` and does not trim; `save_object()` writes no
  space. Writing `type: text` (with a space) stores the value as `" text"` and
  the module rejects the object, so it renders blank.
- **Image objects need `image-file-mime:image/jpeg`** (or png/gif). `serve_file()`
  defaults to `application/octet-stream` when the mime is unset and does not
  derive it from the extension; combined with the vhost's
  `X-Content-Type-Options: nosniff`, the browser may refuse to render it.

## Files

- `module_mobile.inc.php` - the feature (drop-in, self-contained, heavily
  commented).
- `MOBILE-SCALING.md` - this document.
