/**
 *	modules/draw/draw-edit.js
 *	"Draw image" launcher for the Hotglue editor (theagitist/hotglue2 fork).
 *
 *	Adds a button to the editor's NEW menu (click the background) that opens the
 *	vendored miniPaint app (modules/draw/minipaint/, MIT, same-origin iframe) in a
 *	modal. When the author clicks "Place on page", we composite miniPaint's layers
 *	to a canvas, turn it into a PNG file, and hand it to hotglue's existing upload
 *	path so it lands as a normal image object at the spot the menu was opened.
 *
 *	miniPaint's embed API (same-origin) is documented in its examples/; we use:
 *	  Layers.get_dimensions(), Layers.convert_layers_to_canvas(ctx).
 *	Upload reuses $.glue.upload.files + $.glue.upload.handle_response (js/edit.js).
 *
 *	Registered only in edit mode by module_draw.inc.php. No upstream files changed.
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

(function () {
	var modal = null;     // the overlay element (built once, reused)
	var frame = null;     // the miniPaint iframe
	var loaded = false;   // has the iframe src been set yet (lazy-load)
	var place_x = 0, place_y = 0;  // where to drop the resulting object

	function build_modal() {
		modal = $(
			'<div class="glue-ui glue-draw-overlay" style="display: none;">' +
			'  <div class="glue-draw-panel">' +
			'    <div class="glue-draw-bar">' +
			'      <span class="glue-draw-title">draw an image</span>' +
			'      <span class="glue-draw-actions">' +
			'        <button type="button" class="glue-draw-cancel">cancel</button>' +
			'        <button type="button" class="glue-draw-place">place on page</button>' +
			'      </span>' +
			'    </div>' +
			'    <iframe class="glue-draw-frame" title="image editor"></iframe>' +
			'  </div>' +
			'</div>'
		);
		frame = modal.find('.glue-draw-frame').get(0);
		// jQuery 1.5.2 (editor's bundled version) has no .on(); use .bind()
		modal.find('.glue-draw-cancel').bind('click', close_modal);
		modal.find('.glue-draw-place').bind('click', place_drawing);
		// keep clicks inside the modal from reaching the editor's handlers
		modal.bind('click', function (e) { e.stopPropagation(); });
		$('body').append(modal);
	}

	function open_modal() {
		if (!modal) {
			build_modal();
		}
		if (!loaded) {
			// lazy-load the ~MB bundle only the first time it is opened
			frame.src = $.glue.base_url + 'modules/draw/minipaint/index.html';
			loaded = true;
		}
		modal.css('display', 'block');
	}

	function close_modal() {
		if (modal) {
			modal.css('display', 'none');
		}
	}

	function place_drawing() {
		var win = frame ? frame.contentWindow : null;
		if (!win || !win.Layers || typeof win.Layers.convert_layers_to_canvas !== 'function') {
			$.glue.error('The image editor is still loading. Give it a moment and try again.');
			return;
		}
		var L = win.Layers;
		var dim = L.get_dimensions();
		var canvas = document.createElement('canvas');
		canvas.width = dim.width;
		canvas.height = dim.height;
		L.convert_layers_to_canvas(canvas.getContext('2d'));

		var x = place_x, y = place_y;
		var finish_up = function (blob) {
			if (!blob) {
				$.glue.error('Could not read the drawing from the editor.');
				return;
			}
			// PNG keeps line art crisp and preserves transparency; the server also
			// accepts image/webp if we ever want smaller files.
			var file;
			try {
				file = new File([blob], 'drawing-' + (new Date()).getTime() + '.png', { type: 'image/png' });
			} catch (e) {
				// very old browsers without the File constructor
				blob.name = 'drawing-' + (new Date()).getTime() + '.png';
				file = blob;
			}
			$.glue.upload.files([file], { method: 'glue.upload_files', page: $.glue.page }, {
				finish: function (data) { $.glue.upload.handle_response(data, x, y); },
				error: function (e) { $.glue.error('There was a problem placing the drawing.'); }
			});
			close_modal();
		};

		if (canvas.toBlob) {
			canvas.toBlob(finish_up, 'image/png');
		} else {
			// fallback: dataURL -> Blob
			var data = canvas.toDataURL('image/png');
			var bin = atob(data.split(',')[1]);
			var arr = new Uint8Array(bin.length);
			for (var i = 0; i < bin.length; i++) { arr[i] = bin.charCodeAt(i); }
			finish_up(new Blob([arr], { type: 'image/png' }));
		}
	}

	$(document).ready(function () {
		var elem = $('<img src="' + $.glue.base_url + 'modules/draw/draw.png" alt="btn" title="draw a new image" width="32" height="32">');
		$(elem).bind('click', function () {
			// remember where the new menu was spawned, so we drop the object there
			var p = $.glue.menu.spawn_coords();
			if (p) {
				place_x = p.x;
				place_y = p.y;
			} else {
				place_x = $(document).scrollLeft() + 200;
				place_y = $(document).scrollTop() + 200;
			}
			$.glue.menu.hide();
			open_modal();
		});
		$.glue.menu.register('new', elem);
	});
})();
