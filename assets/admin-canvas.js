/**
 * StoreCanvas Lite: print-area editor on the product edit screen.
 * Drag the box to move it, drag the corner to resize it, or type the numbers.
 * Keyboard: arrow keys move the focused box, Shift + arrow keys resize it.
 */
(function () {
	'use strict';

	function clamp(v, min, max) {
		return Math.max(min, Math.min(max, v));
	}

	function init(wrap) {
		var box = wrap.querySelector('.sclt-canvas-editor__box');
		var rect = wrap.querySelector('.sclt-canvas-editor__rect');
		var handle = wrap.querySelector('.sclt-canvas-editor__handle');
		var inputs = {};
		wrap.querySelectorAll('.sclt-canvas-area').forEach(function (inp) {
			inputs[inp.getAttribute('data-k')] = inp;
		});
		if (!inputs.x || !inputs.y || !inputs.w || !inputs.h) {
			return;
		}

		function read() {
			var a = {
				x: parseFloat(inputs.x.value) || 0,
				y: parseFloat(inputs.y.value) || 0,
				w: parseFloat(inputs.w.value) || 5,
				h: parseFloat(inputs.h.value) || 5
			};
			a.x = clamp(a.x, 0, 95);
			a.y = clamp(a.y, 0, 95);
			a.w = clamp(a.w, 5, 100 - a.x);
			a.h = clamp(a.h, 5, 100 - a.y);
			return a;
		}

		function write(a) {
			['x', 'y', 'w', 'h'].forEach(function (k) {
				inputs[k].value = Math.round(a[k] * 2) / 2;
			});
			render(a);
		}

		function render(a) {
			if (!rect) {
				return;
			}
			a = a || read();
			rect.style.left = a.x + '%';
			rect.style.top = a.y + '%';
			rect.style.width = a.w + '%';
			rect.style.height = a.h + '%';
		}

		Object.keys(inputs).forEach(function (k) {
			inputs[k].addEventListener('input', function () {
				render();
			});
			inputs[k].addEventListener('change', function () {
				write(read());
			});
		});
		render();
		if (!box || !rect) {
			return;
		}

		var drag = null;
		rect.addEventListener('pointerdown', function (e) {
			e.preventDefault();
			rect.focus();
			drag = { mode: e.target === handle ? 'resize' : 'move', x: e.clientX, y: e.clientY, a: read(), id: e.pointerId };
			try {
				rect.setPointerCapture(e.pointerId);
			} catch (err) {
				/* ignore */
			}
		});
		rect.addEventListener('pointermove', function (e) {
			if (!drag || e.pointerId !== drag.id) {
				return;
			}
			var r = box.getBoundingClientRect();
			var dx = ((e.clientX - drag.x) / (r.width || 1)) * 100;
			var dy = ((e.clientY - drag.y) / (r.height || 1)) * 100;
			var a = { x: drag.a.x, y: drag.a.y, w: drag.a.w, h: drag.a.h };
			if (drag.mode === 'move') {
				a.x = clamp(a.x + dx, 0, 100 - a.w);
				a.y = clamp(a.y + dy, 0, 100 - a.h);
			} else {
				a.w = clamp(a.w + dx, 5, 100 - a.x);
				a.h = clamp(a.h + dy, 5, 100 - a.y);
			}
			write(a);
		});
		function end() {
			drag = null;
		}
		rect.addEventListener('pointerup', end);
		rect.addEventListener('pointercancel', end);
		rect.addEventListener('keydown', function (e) {
			var map = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
			var d = map[e.key];
			if (!d) {
				return;
			}
			e.preventDefault();
			var a = read();
			if (e.shiftKey) {
				a.w = clamp(a.w + d[0], 5, 100 - a.x);
				a.h = clamp(a.h + d[1], 5, 100 - a.y);
			} else {
				a.x = clamp(a.x + d[0], 0, 100 - a.w);
				a.y = clamp(a.y + d[1], 0, 100 - a.h);
			}
			write(a);
		});
	}

	function boot() {
		document.querySelectorAll('.sclt-canvas-admin').forEach(init);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
