<?php
/*
 *	tests/webp-roundtrip.test.php
 *	Check the WebP GD path used by module_image.inc.php image_resize():
 *	load via imagecreatefromwebp, resize, save via imagewebp, alpha preserved.
 *	Framework-free: run with `php tests/webp-roundtrip.test.php` (exit 0 = pass).
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

function check($cond, $msg)
{
	if (!$cond) {
		fwrite(STDERR, 'FAIL: '.$msg."\n");
		exit(1);
	}
}

// WebP support is a GD build requirement for this feature; fail loudly so a box
// without it is not mistaken for "tests pass".
check(function_exists('imagewebp') && function_exists('imagecreatefromwebp'),
	'GD has no WebP support (imagewebp/imagecreatefromwebp missing)');

$base = sys_get_temp_dir().'/hg_webp_test_'.getmypid();
$src = $base.'_src.webp';
$out = $base.'_out.webp';

// source: 40x30 webp with a semi-transparent fill (alpha must survive)
$im = imagecreatetruecolor(40, 30);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefilledrectangle($im, 0, 0, 39, 29, imagecolorallocatealpha($im, 255, 0, 0, 40));
check(imagewebp($im, $src, 80), 'could not write source webp');
imagedestroy($im);

// same sequence as image_resize(): create from webp, resample, save as webp
$orig = imagecreatefromwebp($src);
check($orig !== false, 'imagecreatefromwebp failed');
$sz = getimagesize($src);
check($sz['mime'] === 'image/webp', 'source not detected as image/webp');
$resized = imagecreatetruecolor(20, 15);
imagealphablending($resized, false);
imagesavealpha($resized, true);
check(imagecopyresampled($resized, $orig, 0, 0, 0, 0, 20, 15, $sz[0], $sz[1]), 'resample failed');
check(imagewebp($resized, $out, 80), 'could not write resized webp');
imagedestroy($orig);
imagedestroy($resized);

$chk = getimagesize($out);
check($chk['mime'] === 'image/webp', 'output not image/webp');
check($chk[0] === 20 && $chk[1] === 15, 'output dimensions wrong');

@unlink($src);
@unlink($out);
echo "webp-roundtrip: all assertions passed\n";
