# Handoff: port Hotglue mobile scale-to-fit to the Telaris-embedded Hotglue

**Audience:** an agent working in the Telaris project who needs to add the
mobile-responsiveness adaptation to the Hotglue that Telaris embeds (the fork
served under a `/hg/` sub-path with a custom auth bridge).

**This document is self-contained.** The full source of the only file you need to
add is embedded below. You do not need access to the standalone install to do
this port; this is just where it was first built and proven
(https://hotglue.polivoxia.ca, 2026-06-24).

---

## 1. What this does and why

Hotglue lays out every object with **absolute pixel coordinates** (`object-left`,
`object-top`, `object-width` -> inline `position:absolute; left/top/width`). A
page authored at 2000-2600px wide is unusable on a phone because:

- Vanilla Hotglue emits **no** `<meta name="viewport">`, so mobile browsers
  assume the legacy ~980px layout viewport and shrink the whole page to a sliver
  (tiny text, pinch-zoom, sideways scroll).

You cannot "reflow" a freehand absolute-position canvas without scrambling it
(DOM order is object *creation* order, not reading order). So the faithful fix is
to **scale the whole canvas down to fit the screen width**, preserving the exact
layout.

The solution is one drop-in module that, **on the published view only**:

1. Emits `<meta name="viewport" content="width=device-width, initial-scale=1">`.
2. Wraps the body in `#hg-clip` (overflow hidden) + `#hg-scale` and applies
   `transform: scale(s)` (origin top-left) when the viewport is narrower than the
   authored design width.
3. Clamps `s` to a floor `MOBILE_MIN_SCALE` (default `0.6`). Above the floor the
   page fits the width exactly (no horizontal scroll); at the floor it stops
   shrinking and the page scrolls horizontally, so text never becomes
   microscopic. This is the deliberate fidelity-vs-readability tradeoff.

No reflow, layout fidelity is 100%, and the editor is never touched.

---

## 2. The file to add (verbatim)

Create `module_mobile.inc.php` in the embedded Hotglue **program directory**
(the directory that already contains `module_glue.inc.php`, `module_text.inc.php`,
`html.inc.php`, `modules.inc.php`, etc.). No other files are modified.

```php
<?php

/*
 *	module_mobile.inc.php
 *	Mobile "scale-to-fit" adaptation for VIEW mode (k0a1a/hotglue2 1.0.4).
 *
 *	THE PROBLEM
 *	Hotglue lays every object out with absolute pixel coordinates. A page
 *	authored at, say, 2000px wide is therefore unreadable on a phone: vanilla
 *	hotglue emits no <meta name="viewport">, so mobile browsers assume the
 *	legacy ~980px layout viewport and shrink the whole page to a sliver, with
 *	tiny text and sideways scrolling.
 *
 *	THE FIX (this module)
 *	On the published view only, wrap the page body and shrink the whole canvas
 *	to fit the screen width with CSS `transform: scale()`, clamped to a tunable
 *	floor (MOBILE_MIN_SCALE). Above the floor the page fits exactly (no
 *	horizontal scroll); at the floor it stops shrinking and the page scrolls
 *	horizontally at a still-readable size. The spatial layout is preserved
 *	exactly (no reflow) - this is a faithful zoom-out, not a re-layout. The
 *	drag-and-drop editor is never touched.
 *
 *	WHY A MODULE (no core edits)
 *	load_modules() (modules.inc.php) auto-discovers any module_*.inc.php in the
 *	program directory, and invoke_hook() auto-calls a function named
 *	<module>_<hook> if it exists. So defining mobile_render_page_late() is enough
 *	to run at the end of view rendering (module_glue.inc.php render_page(), which
 *	fires the `render_page_late` hook with `edit` in $args). Nothing else in the
 *	tree is modified; removing the feature = deleting this one file.
 *
 *	TUNING
 *	Override the floor in user-config.inc.php, e.g.
 *		@define('MOBILE_MIN_SCALE', '0.5');
 *	0.6 keeps text readable while fitting most pages; lower = fits wider pages
 *	before horizontal scroll kicks in, at the cost of smaller text.
 *
 *	Same GPL license as the rest of hotglue.
 */

@require_once('config.inc.php');
require_once('html.inc.php');

// minimum scale factor (1.0 = never shrink, 0.0 = always fit exactly).
@define('MOBILE_MIN_SCALE', '0.6');


/**
 *	hook: render_page_late - fired after all objects are rendered, for both
 *	view and edit. We act on the public view only.
 *
 *	@param array $args has 'page' and 'edit'
 */
function mobile_render_page_late($args)
{
	// never interfere with the editor
	if (!empty($args['edit'])) {
		return false;
	}

	// 1) responsive viewport (vanilla hotglue emits none). prio 1 = early in <head>.
	html_add_head_inline('<meta name="viewport" content="width=device-width, initial-scale=1">', 1);

	// 2) the scaler. sanitise the floor define into a safe JS float literal.
	$floor = floatval(MOBILE_MIN_SCALE);
	if ($floor < 0) {
		$floor = 0;
	}
	if (1 < $floor) {
		$floor = 1;
	}

	html_add_js_inline(mobile_scaler_js($floor), 7, 'mobile scale-to-fit (module_mobile)');

	return true;
}


/**
 *	client-side scaler. Wraps the body in a clip+scale pair and, when the
 *	viewport is narrower than the authored design width, applies
 *	transform: scale() clamped to $floor. Recomputes on resize/orientation and
 *	again on window load (after images settle the real height).
 *
 *	Runs from <head> (html_add_js_inline output lives there), so it defers all
 *	DOM work to DOMContentLoaded - the body does not exist yet at parse time.
 *
 *	@param float $floor sanitised minimum scale, safe to inline
 *	@return string javascript (without <script> wrapper)
 */
function mobile_scaler_js($floor)
{
	return <<<JS
(function () {
	var FLOOR = {$floor};
	var clip, scale, designW, designH;

	function measure() {
		// design size = furthest right/bottom edge of the absolutely-positioned
		// objects. offsetParent is #hg-scale (position:relative, at 0,0).
		var w = 0, h = 0, k = scale.children, i, el, r, b;
		for (i = 0; i < k.length; i++) {
			el = k[i];
			r = el.offsetLeft + el.offsetWidth;
			b = el.offsetTop + el.offsetHeight;
			if (r > w) w = r;
			if (b > h) h = b;
		}
		designW = w;
		designH = h;
		// abs-positioned children give the wrapper no intrinsic size, so set it
		// explicitly or the clip would collapse to zero height.
		scale.style.width = designW + 'px';
		scale.style.height = designH + 'px';
	}

	function apply() {
		if (!designW) return;
		var vpW = document.documentElement.clientWidth;
		var s = vpW / designW;
		if (s >= 1) {                  // screen already wide enough: untouched
			scale.style.transform = 'none';
			clip.style.width = '';
			clip.style.height = '';
			return;
		}
		if (s < FLOOR) s = FLOOR;      // floor: stop shrinking, allow h-scroll
		scale.style.transform = 'scale(' + s + ')';
		clip.style.width = (designW * s) + 'px';
		clip.style.height = (designH * s) + 'px';
	}

	function init() {
		clip = document.createElement('div');
		clip.id = 'hg-clip';
		clip.style.overflow = 'hidden';
		clip.style.margin = '0 auto';
		scale = document.createElement('div');
		scale.id = 'hg-scale';
		scale.style.position = 'relative';
		scale.style.transformOrigin = '0 0';
		while (document.body.firstChild) {
			scale.appendChild(document.body.firstChild);
		}
		clip.appendChild(scale);
		document.body.appendChild(clip);
		measure();
		apply();
		window.addEventListener('resize', apply);
		window.addEventListener('orientationchange', apply);
		// re-measure once images have loaded (heights can shift)
		window.addEventListener('load', function () { measure(); apply(); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
JS;
}
```

File permissions: it holds no secret, so plain world-readable is fine (e.g.
`0644`, or match the other `module_*.inc.php`). It does **not** need the
`0640 group www-data` treatment that secret-bearing config files need.

---

## 3. How it wires in (so you can verify it on the fork)

Hotglue's module system does all the wiring, which is why there are zero core
edits:

- `load_modules()` (`modules.inc.php`) scans the program directory for
  `module_*.inc.php` and `include`s each.
- `invoke_hook($hook, $args)` then auto-calls any function named
  `<module>_<hook>` that exists.

The hook we use is fired in `module_glue.inc.php` `render_page()`:

```php
invoke_hook('render_page_late', array('page'=>..., 'edit'=>$args['edit']));
```

So `mobile_render_page_late($args)` runs at the end of every page render. We bail
when `$args['edit']` is truthy, so the editor is untouched. The injected
`<meta>`/`<script>` go through `html_add_head_inline()` / `html_add_js_inline()`
and land in the cached HTML, so output caching (`CACHE_TIME`) is safe.

Because inline JS is emitted inside `<head>`, the script guards on
`document.readyState` and defers all DOM work to `DOMContentLoaded`. Do not
"optimize" that away.

---

## 4. Telaris-specific checks (IMPORTANT)

The mechanism is portable, but verify these on the embedded fork:

1. **CSP (most likely blocker).** The standalone vhost sets **no**
   Content-Security-Policy, so the inline `<script>` and `<meta>` work as-is.
   **Telaris very likely sets a CSP header.** If so, an inline script is blocked
   unless `script-src` allows `'unsafe-inline'` or a per-response nonce. Two
   options:
   - If Telaris already uses `'unsafe-inline'` for scripts: nothing to do.
   - If Telaris uses a nonce or hash CSP: either (a) move the JS body into an
     external `.js` file served by the app and add it with `html_add_js()`
     instead of `html_add_js_inline()`, or (b) add the active nonce to the
     emitted `<script>` tag. The viewport `<meta>` is not affected by CSP.
   Check the response headers of an embedded Hotglue page before and after.

2. **View vs edit is independent of the auth bridge.** Edit mode is decided by
   `controller_edit` vs `controller_show` (the `edit` flag in `$args`), not by
   whether the request is authenticated. So "view mode only" still holds even if
   the Telaris bridge always presents as authenticated. Just confirm
   `render_page_late` actually fires in the embedded render path (the fork may
   have customized rendering). If the fork bypasses `render_page()`, hook whatever
   late-in-view point it uses instead, or call the same `html_add_*` functions
   from there.

3. **`/hg/` sub-path is fine.** The scaler uses no URLs and the viewport meta is
   path-agnostic, so the sub-path needs no changes.

4. **Avoid a double viewport meta.** If the embedding Telaris page (the outer
   shell, not Hotglue) already emits its own `<meta name="viewport">`, or if the
   fork added one, drop the meta line from `mobile_render_page_late()` to avoid
   two conflicting tags. Keep the scaler.

5. **If Hotglue is rendered inside an iframe or a Telaris chrome**, the scaler
   measures `document.documentElement.clientWidth` of the Hotglue document. That
   is correct inside an iframe (it sees the iframe's own width). If Hotglue is
   embedded inline within a larger Telaris page (not an iframe), the wrap targets
   `document.body`, which would wrap the whole Telaris page, not just the Hotglue
   region. In that inline-embed case, change `init()` to wrap a specific
   container element (e.g. the Hotglue page root `#<pagename>` / `body.page`)
   instead of `document.body`, and measure that element. Check how Telaris embeds
   Hotglue first; the standalone case is a normal standalone document where
   wrapping `document.body` is correct.

---

## 5. Dependency checklist (for upstream Hotglue drift)

The module relies only on long-stable internals (unchanged across the 1.0.x
line). Recheck these if the fork is based on a newer/older Hotglue:

1. Module auto-discovery + `<module>_<hook>` naming (`modules.inc.php`).
2. The `render_page_late` hook, fired in `render_page()` with an `edit` flag
   (`module_glue.inc.php`).
3. `html_add_head_inline($def, $prio)` and `html_add_js_inline($code, $prio, $reason)`
   (`html.inc.php`). Note `html_add_js_inline` only emits when `$reason` is
   non-empty (we pass one).
4. Objects are absolutely-positioned direct children of `<body>` (the scaler
   measures the design box from `#hg-scale.children` offsets).

---

## 6. Configuration

Default floor is `0.6`. Override per-install in `user-config.inc.php`:

```php
@define('MOBILE_MIN_SCALE', '0.5');   // lower = fits wider pages before h-scroll, smaller text
@define('MOBILE_MIN_SCALE', '1.0');   // effectively disables scaling
@define('MOBILE_MIN_SCALE', '0.0');   // always fit exactly to width (smallest text on wide pages)
```

---

## 7. Verification recipe

After deploying, check an embedded Hotglue page:

```sh
# 1) view page: viewport meta + scaler present
curl -s 'https://<telaris-host>/hg/<page>' | grep -o '<meta name="viewport"[^>]*>'
curl -s 'https://<telaris-host>/hg/<page>' | grep -c 'mobile scale-to-fit'

# 2) edit page: scaler must be ABSENT (editor untouched)
curl -s 'https://<telaris-host>/hg/<page>/edit' | grep -c 'mobile scale-to-fit'   # expect 0

# 3) CSP header present? (if so, see section 4.1)
curl -sI 'https://<telaris-host>/hg/<page>' | grep -i content-security-policy
```

Visual check with headless Chromium (the scaler is client-side, so curl cannot
test the actual scaling):

```sh
chromium-browser --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage \
  --hide-scrollbars --force-device-scale-factor=1 --window-size=390,844 \
  --user-data-dir=/tmp/cprof --virtual-time-budget=7000 \
  --screenshot=/path/in/HOME/shot.png 'https://<telaris-host>/hg/<page>'
```

Two Snap-Chromium gotchas observed on the Polivoxia VPS (skip if you use a
non-snap Chromium): it has a **private `/tmp`** namespace, so the
`--screenshot=` output path must be under the real `$HOME`, not `/tmp`; and
redirecting its stderr/stdout to `/dev/null` suppressed the file write (keep a
pipe, e.g. `2>&1 | grep written`).

Expected: on a phone-width viewport, a wide page renders at the floor scale with
readable text and horizontal scroll (for very wide pages); on a desktop viewport
wider than the design, the page is untouched.

---

## 8. Rollback

Delete `module_mobile.inc.php`. Nothing else changes.

---

## 9. Optional: testing content

If you want a wide+tall test page and prefer to script object files directly on
disk rather than build one in the editor, note two non-obvious format rules
(they cost an hour on the first build):

- **Object attribute lines are `key:value` with NO space after the colon.**
  Hotglue's `load_object()` splits on `:` and does **not** trim;
  `save_object()` writes no space. Writing `type: text` (with a space) stores
  the value as `" text"`, the type-matching modules reject the object, and it
  renders blank.
- **Image objects need `image-file-mime:image/jpeg`** (or png/gif). `serve_file()`
  defaults to `application/octet-stream` when the mime is unset and does not
  derive it from the file extension; combined with an `X-Content-Type-Options:
  nosniff` response header the browser may refuse to render the image.

Otherwise just build a wide page in the editor and view it on a phone.
