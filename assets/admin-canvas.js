/**
 * StoreCanvas Lite: print-area editor on the product edit screen.
 * One box per side (front, back). Drag the box to move it, drag the corner to resize it,
 * or type the numbers. Keyboard: arrow keys move the focused box, Shift + arrow keys
 * resize it. The red dashed line inside shows the safe margin.
 * Also: "Start from a template" and the back photo picker (WordPress media library).
 */
(function () {
	'use strict';

	function clamp(v, min, max) {
		return Math.max(min, Math.min(max, v));
	}

	function initSide(wrap, admin) {
		var box = wrap.querySelector('.sclt-canvas-editor__box');
		var rect = wrap.querySelector('.sclt-canvas-editor__rect');
		var handle = wrap.querySelector('.sclt-canvas-editor__handle');
		var safeBox = wrap.querySelector('.sclt-canvas-editor__safe');
		var safeInput = admin.querySelector('.sclt-canvas-safe');
		var inputs = {};
		wrap.querySelectorAll('.sclt-canvas-area').forEach(function (inp) {
			inputs[inp.getAttribute('data-k')] = inp;
		});
		if (!inputs.x || !inputs.y || !inputs.w || !inputs.h) {
			return null;
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
			if (safeBox && box) {
				// Same rule as the storefront: the margin is a % of the shorter side of the area.
				var s = clamp(parseFloat(safeInput && safeInput.value) || 0, 0, 20) / 100;
				var bw = box.clientWidth || 1;
				var bh = box.clientHeight || 1;
				var px = Math.min((a.w / 100) * bw, (a.h / 100) * bh) * s;
				safeBox.style.left = px + 'px';
				safeBox.style.top = px + 'px';
				safeBox.style.right = px + 'px';
				safeBox.style.bottom = px + 'px';
				safeBox.style.display = px > 0 ? 'block' : 'none';
			}
		}

		Object.keys(inputs).forEach(function (k) {
			inputs[k].addEventListener('input', function () {
				render();
			});
			inputs[k].addEventListener('change', function () {
				write(read());
			});
		});
		var img = box ? box.querySelector('img') : null;
		if (img) {
			img.addEventListener('load', function () {
				render();
			});
		}
		if (box && window.ResizeObserver) {
			// The product tab is hidden on load; draw the safe line once it has a size.
			new window.ResizeObserver(function () {
				render();
			}).observe(box);
		}
		render();
		if (box && rect) {
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
			var end = function () {
				drag = null;
			};
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
		return { write: write, render: render, box: box, img: img };
	}

	function init(admin) {
		var sides = {};
		admin.querySelectorAll('.sclt-canvas-side').forEach(function (wrap) {
			var ed = initSide(wrap, admin);
			if (ed) {
				ed.wrap = wrap;
				sides[wrap.getAttribute('data-side')] = ed;
			}
		});
		var backOn = admin.querySelector('.sclt-canvas-back-on');
		var backId = admin.querySelector('.sclt-canvas-back-id');
		var pick = admin.querySelector('.sclt-canvas-back-pick');
		var clear = admin.querySelector('.sclt-canvas-back-clear');
		var safe = admin.querySelector('.sclt-canvas-safe');
		var ratio = admin.querySelector('.sclt-canvas-ratio');

		function renderAll() {
			Object.keys(sides).forEach(function (k) {
				sides[k].render();
			});
		}
		function syncBack() {
			var on = !!(backOn && backOn.checked);
			if (sides.back) {
				sides.back.wrap.style.display = on ? 'inline-block' : 'none';
				sides.back.render();
			}
			if (pick) {
				pick.disabled = !on;
			}
		}
		if (safe) {
			safe.addEventListener('input', renderAll);
		}
		if (backOn) {
			backOn.addEventListener('change', syncBack);
		}
		syncBack();

		function setBackPhoto(id, url) {
			if (backId) {
				backId.value = id ? String(id) : '0';
			}
			if (clear) {
				clear.style.display = id ? '' : 'none';
			}
			var b = sides.back;
			if (!b || !b.box || !b.img) {
				return;
			}
			var none = b.wrap.querySelector('.sclt-canvas-editor__none');
			if (id && url) {
				b.img.src = url;
				b.box.style.display = 'inline-block';
				if (none) {
					none.style.display = 'none';
				}
			} else {
				b.box.style.display = 'none';
				if (none) {
					none.style.display = '';
				}
			}
			b.render();
		}

		var frame = null;
		if (pick) {
			pick.addEventListener('click', function (e) {
				e.preventDefault();
				if (!window.wp || !window.wp.media) {
					return;
				}
				if (!frame) {
					frame = window.wp.media({ title: pick.textContent, library: { type: 'image' }, multiple: false });
					frame.on('select', function () {
						var att = frame.state().get('selection').first();
						if (!att) {
							return;
						}
						var j = att.toJSON();
						var size = j.sizes && (j.sizes.medium_large || j.sizes.large || j.sizes.full);
						setBackPhoto(j.id, (size && size.url) || j.url);
					});
				}
				frame.open();
			});
		}
		if (clear) {
			clear.addEventListener('click', function (e) {
				e.preventDefault();
				setBackPhoto(0, '');
			});
		}

		var tplSelect = admin.querySelector('.sclt-canvas-template');
		var tpls = {};
		try {
			tpls = JSON.parse(admin.getAttribute('data-sclt-templates') || '{}');
		} catch (err) {
			tpls = {};
		}
		if (tplSelect) {
			tplSelect.addEventListener('change', function () {
				var t = tpls[tplSelect.value];
				if (!t) {
					return;
				}
				if (sides.front && t.area) {
					sides.front.write(t.area);
				}
				if (sides.back && t.barea) {
					sides.back.write(t.barea);
				}
				if (backOn) {
					backOn.checked = !!t.back;
				}
				if (safe && t.safe != null) {
					safe.value = t.safe;
				}
				if (ratio && t.ratio != null) {
					ratio.value = t.ratio;
				}
				syncBack();
				renderAll();
			});
		}
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
