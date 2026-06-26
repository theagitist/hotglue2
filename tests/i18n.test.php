<?php

/*
 *	tests/i18n.test.php
 *	Self-check for the editor i18n layer (module_i18n.inc.php).
 *	Run from anywhere:  php tests/i18n.test.php
 *
 *	Covers the load-bearing logic: locale discovery, catalog merge / en-fallback,
 *	locale resolution, t() lookup + key-fallback + %s interpolation. No framework.
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
chdir(dirname(__DIR__));   // app root, so glob('lang/*.json') and __DIR__ resolve

// minimal stub: the real invoke_hook_while lives in modules.inc.php; for the
// standalone path there is no i18n_locale_override, so an empty result is faithful.
if (!function_exists('invoke_hook_while')) {
	function invoke_hook_while($hook, $while, $args = array()) { return array(); }
}

// force the active locale to es via cookie BEFORE module_i18n caches it
$_GET = array();
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';
$_COOKIE['hg_lang'] = 'es';

require_once('config.inc.php');
require_once('html.inc.php');
require_once('module_i18n.inc.php');

function ok($cond, $msg)
{
	if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
	echo "ok - $msg\n";
}

$en = json_decode(file_get_contents('lang/en.json'), true);
$es = json_decode(file_get_contents('lang/es.json'), true);
ok(is_array($en) && is_array($es), 'en.json and es.json are valid JSON objects');

// 1. locales discovered from lang/*.json (data-driven, no hardcoded list)
$avail = i18n_available_locales();
ok(in_array('en', $avail) && in_array('es', $avail) && in_array('fr', $avail) && in_array('pt', $avail),
   'i18n_available_locales() discovers en/es/fr/pt');

// 2. catalog merge: en base with locale layered over => missing keys fall back to en
$cat = i18n_catalog('es');
ok($cat === array_merge($en, $es), 'i18n_catalog(es) = en merged with es (en-fallback for gaps)');
foreach (array_keys($en) as $k) {
	ok(array_key_exists($k, $cat), "merged es catalog keeps en key: $k");
}

// 3. active locale resolves to es (from the cookie)
ok(i18n_locale() === 'es', 'i18n_locale() resolves to es from cookie');

// 4. t() returns the locale string for a translated key
ok(t('errors.prefix') === $es['errors.prefix'], 't(errors.prefix) returns the es translation');

// 5. t() falls back to the key itself for an unknown key (never blank/raw)
ok(t('__no_such_key__') === '__no_such_key__', 't(unknown) returns the key verbatim');

// 6. %s interpolation through t()
ok(t('page.not_exist', '"start"') === sprintf($es['page.not_exist'], '"start"'),
   't(page.not_exist, ...) interpolates %s using the es template');

echo "\nall i18n checks passed\n";
