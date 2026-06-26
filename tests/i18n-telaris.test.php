<?php

/*
 *	tests/i18n-telaris.test.php  (TELARIS BRANCH ONLY)
 *	Self-check for module_telaris_locale (the Telaris i18n bridge).
 *	Run from anywhere:  php tests/i18n-telaris.test.php
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
chdir(dirname(__DIR__));   // app root

require_once('config.inc.php');
require_once('html.inc.php');
require_once('module_i18n.inc.php');         // provides i18n_available_locales()
require_once('module_telaris_locale.inc.php');

function ok($cond, $msg)
{
	if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
	echo "ok - $msg\n";
}

// exact catalog match
$_SERVER['TELARIS_LOCALE'] = 'es';
ok(telaris_locale_i18n_locale_override(array()) === 'es', 'TELARIS_LOCALE=es -> es');

// region tag falls back to the primary subtag
$_SERVER['TELARIS_LOCALE'] = 'pt-BR';
ok(telaris_locale_i18n_locale_override(array()) === 'pt', 'TELARIS_LOCALE=pt-BR -> pt');

// underscore form normalised
$_SERVER['TELARIS_LOCALE'] = 'fr_FR';
ok(telaris_locale_i18n_locale_override(array()) === 'fr', 'TELARIS_LOCALE=fr_FR -> fr');

// unknown language -> false (defer to module_i18n)
$_SERVER['TELARIS_LOCALE'] = 'xx';
ok(telaris_locale_i18n_locale_override(array()) === false, 'unknown locale -> false');

// nothing provided -> false
unset($_SERVER['TELARIS_LOCALE']);
putenv('TELARIS_LOCALE');
ok(telaris_locale_i18n_locale_override(array()) === false, 'no TELARIS_LOCALE -> false (defer to module_i18n)');

// selector forced off in edit mode (overwrites module_i18n's js_var)
$GLOBALS['html'] = array();
telaris_locale_render_page_early(array('edit' => true));
ok(isset($html['header']['js_var']['$.glue.conf.show_lang_selector'])
   && $html['header']['js_var']['$.glue.conf.show_lang_selector'] === false,
   'render_page_early forces the language selector off in edit mode');

// no-op in view mode
$GLOBALS['html'] = array();
$r = telaris_locale_render_page_early(array('edit' => false));
ok($r === false && empty($html['header']['js_var']),
   'render_page_early is a no-op in view mode');

echo "\nall telaris-locale checks passed\n";
