/**
 * StoreCanvas Lite: customer design canvas (product page).
 *
 * Vanilla JS, no dependencies, no outside requests. The customer adds text and
 * images inside one print area drawn on the product's main image. Positions are
 * stored relative to the print area (x/y = centre, 0..1; s = text height as % of the
 * area height, or image width as % of the area width; rot = degrees), so the design
 * does not depend on screen size. On add to cart the design JSON and a PNG preview
 * (data URL) go into hidden fields; the server validates both.
 */
(function () {
	'use strict';

	var W = 800; // internal canvas width (CSS scales it).
	var EXPORT_W = 600; // preview PNG width.

	function clamp(v, min, max) {
		return Math.max(min, Math.min(max, v));
	}

	function init(root) {
		var cfg;
		try {
			cfg = JSON.parse(root.getAttribute('data-sclt-canvas') || '{}');
		} catch (e) {
			return;
		}
		var canvas = root.querySelector('.sclt-canvas__stage');
		if (!canvas || !canvas.getContext) {
			return;
		}
		var ctx = canvas.getContext('2d');
		var t = cfg.i18n || {};
		var q = function (sel) {
			return root.querySelector(sel);
		};
		var ui = {
			text: q('.sclt-canvas__text'),
			file: q('.sclt-canvas__file'),
			layers: q('.sclt-canvas__layers'),
			font: q('.sclt-canvas__font'),
			color: q('.sclt-canvas__color'),
			bold: q('.sclt-canvas__bold'),
			size: q('.sclt-canvas__size'),
			rot: q('.sclt-canvas__rot'),
			msg: q('.sclt-canvas__msg'),
			json: q('.sclt-canvas__json'),
			preview: q('.sclt-canvas__preview')
		};
		var fonts = {};
		(cfg.fonts || []).forEach(function (f) {
			fonts[f.key] = f.css;
		});
		var maxLayers = parseInt(cfg.maxLayers, 10) || 10;
		var H = W;
		var bg = null;
		var tainted = false;
		var layers = [];
		var active = null;
		var seq = 0;
		var drag = null;
		var frame = 0;
		var previewTimer = 0;

		canvas.width = W;
		canvas.height = H;

		/* ---------- geometry ---------- */

		function area() {
			var a = cfg.area || { x: 25, y: 20, w: 50, h: 50 };
			return { x: (a.x / 100) * W, y: (a.y / 100) * H, w: (a.w / 100) * W, h: (a.h / 100) * H };
		}

		function fontString(l, px) {
			return (l.bold ? 'bold ' : '') + Math.max(1, px).toFixed(1) + 'px ' + (fonts[l.font] || 'sans-serif');
		}

		function geom(l, c) {
			var a = area();
			var g = { cx: a.x + l.x * a.w, cy: a.y + l.y * a.h, w: 10, h: 10, px: 0 };
			if (l.type === 'text') {
				c = c || ctx;
				g.px = (l.s / 100) * a.h;
				c.save();
				c.font = fontString(l, g.px);
				g.w = Math.max(4, c.measureText(l.text).width);
				c.restore();
				g.h = g.px * 1.2;
			} else if (l.img && l.img.naturalWidth) {
				g.w = (l.s / 100) * a.w;
				g.h = (g.w * l.img.naturalHeight) / l.img.naturalWidth;
			}
			return g;
		}

		function toLocal(p, g, rot) {
			var r = (-rot * Math.PI) / 180;
			var dx = p.x - g.cx;
			var dy = p.y - g.cy;
			return { x: dx * Math.cos(r) - dy * Math.sin(r), y: dx * Math.sin(r) + dy * Math.cos(r) };
		}

		function toScreen(lx, ly, g, rot) {
			var r = (rot * Math.PI) / 180;
			return { x: g.cx + lx * Math.cos(r) - ly * Math.sin(r), y: g.cy + lx * Math.sin(r) + ly * Math.cos(r) };
		}

		function k() {
			var cw = canvas.clientWidth || W;
			return W / cw; // canvas px per CSS px.
		}

		function handles(l) {
			var g = geom(l);
			return {
				g: g,
				resize: toScreen(g.w / 2, g.h / 2, g, l.rot),
				rotate: toScreen(0, -g.h / 2 - 30 * k(), g, l.rot)
			};
		}

		function hit(l, p) {
			var g = geom(l);
			var lp = toLocal(p, g, l.rot);
			var pad = 6 * k();
			return Math.abs(lp.x) <= g.w / 2 + pad && Math.abs(lp.y) <= g.h / 2 + pad;
		}

		function dist(a, b) {
			return Math.sqrt((a.x - b.x) * (a.x - b.x) + (a.y - b.y) * (a.y - b.y));
		}

		/* ---------- drawing ---------- */

		function drawLayer(c, l) {
			var g = geom(l, c);
			c.save();
			c.translate(g.cx, g.cy);
			c.rotate((l.rot * Math.PI) / 180);
			if (l.type === 'text') {
				c.font = fontString(l, g.px);
				c.fillStyle = l.color || '#000000';
				c.textAlign = 'center';
				c.textBaseline = 'middle';
				c.fillText(l.text, 0, 0);
			} else if (l.img && l.img.naturalWidth) {
				c.drawImage(l.img, -g.w / 2, -g.h / 2, g.w, g.h);
			}
			c.restore();
		}

		function paint(c, exporting, withBg) {
			var a = area();
			c.clearRect(0, 0, W, H);
			if (withBg && bg) {
				c.drawImage(bg, 0, 0, W, H);
			} else {
				c.fillStyle = exporting ? '#ffffff' : '#f3f3f3';
				c.fillRect(0, 0, W, H);
			}
			c.save();
			c.beginPath();
			c.rect(a.x, a.y, a.w, a.h);
			c.clip();
			layers.forEach(function (l) {
				drawLayer(c, l);
			});
			c.restore();
			if (exporting) {
				if (!withBg || !bg) {
					c.strokeStyle = '#cccccc';
					c.lineWidth = 2;
					c.strokeRect(a.x, a.y, a.w, a.h);
				}
				return;
			}
			var s = k();
			c.save();
			c.setLineDash([8 * s, 6 * s]);
			c.lineWidth = 1.5 * s;
			c.strokeStyle = 'rgba(0,0,0,.55)';
			c.strokeRect(a.x, a.y, a.w, a.h);
			c.strokeStyle = 'rgba(255,255,255,.8)';
			c.lineDashOffset = 7 * s;
			c.strokeRect(a.x, a.y, a.w, a.h);
			c.restore();
			if (active) {
				var h = handles(active);
				var g = h.g;
				c.save();
				c.translate(g.cx, g.cy);
				c.rotate((active.rot * Math.PI) / 180);
				c.strokeStyle = '#2271b1';
				c.lineWidth = 2 * s;
				c.strokeRect(-g.w / 2, -g.h / 2, g.w, g.h);
				c.beginPath();
				c.moveTo(0, -g.h / 2);
				c.lineTo(0, -g.h / 2 - 30 * s);
				c.stroke();
				c.restore();
				c.fillStyle = '#2271b1';
				c.strokeStyle = '#ffffff';
				c.lineWidth = 2 * s;
				[h.resize, h.rotate].forEach(function (pt) {
					c.beginPath();
					c.arc(pt.x, pt.y, 9 * s, 0, Math.PI * 2);
					c.fill();
					c.stroke();
				});
			}
		}

		function draw() {
			if (frame) {
				return;
			}
			frame = (window.requestAnimationFrame || setTimeout)(function () {
				frame = 0;
				paint(ctx, false, true);
			});
		}

		function exportPreview() {
			var out = document.createElement('canvas');
			out.width = EXPORT_W;
			out.height = Math.round((EXPORT_W * H) / W);
			var c = out.getContext('2d');
			var render = function (withBg) {
				c.setTransform(1, 0, 0, 1, 0, 0);
				c.scale(EXPORT_W / W, EXPORT_W / W);
				paint(c, true, withBg);
				return out.toDataURL('image/png');
			};
			try {
				return render(!tainted);
			} catch (e) {
				// A product image on another domain (CDN) taints the canvas: export the design alone.
				tainted = true;
				try {
					return render(false);
				} catch (e2) {
					return '';
				}
			}
		}

		/* ---------- state -> form ---------- */

		function serialize() {
			return layers.map(function (l) {
				var o = { type: l.type, x: +l.x.toFixed(4), y: +l.y.toFixed(4), s: +l.s.toFixed(2), rot: Math.round(l.rot) };
				if (l.type === 'text') {
					o.text = l.text;
					o.font = l.font;
					o.color = l.color;
					o.bold = !!l.bold;
				} else {
					o.file = l.file;
				}
				return o;
			});
		}

		function writeFields(withPreview) {
			if (ui.json) {
				ui.json.value = layers.length ? JSON.stringify({ v: 1, layers: serialize() }) : '';
			}
			if (ui.preview && withPreview) {
				ui.preview.value = layers.length ? exportPreview() : '';
			}
		}

		function changed() {
			draw();
			writeFields(false);
			clearTimeout(previewTimer);
			previewTimer = setTimeout(function () {
				writeFields(true);
			}, 400);
		}

		function say(text, isError) {
			if (!ui.msg) {
				return;
			}
			ui.msg.textContent = text || '';
			ui.msg.classList.toggle('is-error', !!isError);
		}

		function layerName(l, i) {
			var label = l.type === 'text' ? (t.textLayer || 'Text') + ': ' + l.text : t.imageLayer || 'Image';
			if (label.length > 40) {
				label = label.slice(0, 39) + '…';
			}
			return i + 1 + '. ' + label;
		}

		function syncControls() {
			if (ui.layers) {
				ui.layers.innerHTML = '';
				if (!layers.length) {
					var none = document.createElement('option');
					none.value = '';
					none.textContent = t.noLayers || 'No layers yet';
					ui.layers.appendChild(none);
				}
				layers.forEach(function (l, i) {
					var o = document.createElement('option');
					o.value = l.id;
					o.textContent = layerName(l, i);
					o.selected = l === active;
					ui.layers.appendChild(o);
				});
				ui.layers.disabled = !layers.length;
			}
			var isText = !!(active && active.type === 'text');
			root.classList.toggle('is-text', isText);
			if (active) {
				if (ui.size) {
					ui.size.value = Math.round(active.s);
				}
				if (ui.rot) {
					ui.rot.value = Math.round(active.rot);
				}
			}
			if (isText) {
				if (ui.font) {
					ui.font.value = active.font;
				}
				if (ui.color) {
					ui.color.value = active.color;
				}
				if (ui.bold) {
					ui.bold.checked = !!active.bold;
				}
				if (ui.text && document.activeElement !== ui.text) {
					ui.text.value = active.text;
				}
			}
			root.querySelectorAll('[data-sclt-act="forward"],[data-sclt-act="backward"],[data-sclt-act="delete"]').forEach(function (b) {
				b.disabled = !active;
			});
			[ui.size, ui.rot].forEach(function (inp) {
				if (inp) {
					inp.disabled = !active;
				}
			});
		}

		function select(l, announce) {
			active = l || null;
			syncControls();
			draw();
			if (announce && active) {
				say((t.selected || 'Selected:') + ' ' + layerName(active, layers.indexOf(active)));
			}
		}

		function canAdd() {
			if (layers.length >= maxLayers) {
				say(t.maxLayers || 'Too many layers.', true);
				return false;
			}
			return true;
		}

		function addText() {
			if (!canAdd()) {
				return;
			}
			var text = ui.text && ui.text.value.trim() && !(active && active.type === 'text' && ui.text.value === active.text) ? ui.text.value.trim() : t.newText || 'Your text';
			var l = {
				id: 'l' + ++seq,
				type: 'text',
				text: text.slice(0, 200),
				font: (ui.font && ui.font.value) || 'sans',
				color: (ui.color && ui.color.value) || '#000000',
				bold: !!(ui.bold && ui.bold.checked),
				x: 0.5,
				y: 0.5,
				s: 18,
				rot: 0
			};
			// Shrink long text so it starts inside the print area.
			var a = area();
			var g = geom(l);
			if (g.w > a.w * 0.9) {
				l.s = Math.max(3, (l.s * a.w * 0.9) / g.w);
			}
			layers.push(l);
			select(l);
			loadFont(l.font);
			changed();
			if (ui.text) {
				ui.text.value = l.text;
			}
		}

		function loadFont(key) {
			if (!document.fonts || !document.fonts.load || !fonts[key]) {
				return;
			}
			document.fonts.load('20px ' + fonts[key]).then(draw, function () {});
		}

		function upload(file) {
			if (!file) {
				return;
			}
			if (!canAdd()) {
				return;
			}
			if (!/^image\/(png|jpeg)$/.test(file.type)) {
				say(t.badType || 'Please choose a PNG or JPEG image.', true);
				return;
			}
			if (file.size > (cfg.maxBytes || 0)) {
				say(t.tooBig || 'That image is too large.', true);
				return;
			}
			say(t.uploading || 'Uploading…');
			var fd = new FormData();
			fd.append('action', cfg.action);
			fd.append('nonce', cfg.nonce);
			fd.append('product_id', String(cfg.productId));
			fd.append('file', file, file.name);
			fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (!res || !res.success || !res.data || !res.data.file) {
						throw new Error(res && res.data && res.data.message ? res.data.message : '');
					}
					var img = new Image();
					img.onload = function () {
						if (layers.length >= maxLayers) {
							say(t.maxLayers || 'Too many layers.', true);
							return;
						}
						var a = area();
						var s = 60;
						var h = ((a.w * s) / 100) * (img.naturalHeight / img.naturalWidth);
						if (h > a.h * 0.9) {
							s = (s * a.h * 0.9) / h;
						}
						var l = { id: 'l' + ++seq, type: 'image', file: res.data.file, img: img, x: 0.5, y: 0.5, s: Math.max(3, s), rot: 0 };
						layers.push(l);
						select(l);
						changed();
						say(t.uploaded || 'Image added.');
					};
					img.onerror = function () {
						say(t.uploadFail || 'Upload failed.', true);
					};
					img.src = URL.createObjectURL(file);
				})
				.catch(function (err) {
					say((err && err.message) || t.uploadFail || 'Upload failed.', true);
				});
		}

		function move(dir) {
			if (!active) {
				return;
			}
			var i = layers.indexOf(active);
			var j = i + dir;
			if (j < 0 || j >= layers.length) {
				return;
			}
			layers[i] = layers[j];
			layers[j] = active;
			syncControls();
			changed();
			say((t.selected || 'Selected:') + ' ' + layerName(active, j));
		}

		function removeActive() {
			if (!active) {
				return;
			}
			var i = layers.indexOf(active);
			layers.splice(i, 1);
			select(layers[Math.min(i, layers.length - 1)] || null);
			changed();
			say(t.deleted || 'Layer deleted.');
		}

		/* ---------- pointer (mouse, pen, touch) ---------- */

		function point(e) {
			var r = canvas.getBoundingClientRect();
			return { x: ((e.clientX - r.left) * W) / (r.width || W), y: ((e.clientY - r.top) * H) / (r.height || H) };
		}

		canvas.addEventListener('pointerdown', function (e) {
			var p = point(e);
			var mode = null;
			var target = null;
			if (active) {
				var h = handles(active);
				var rad = 14 * k();
				if (dist(p, h.resize) <= rad) {
					mode = 'resize';
				} else if (dist(p, h.rotate) <= rad) {
					mode = 'rotate';
				}
				target = mode ? active : null;
			}
			if (!mode) {
				for (var i = layers.length - 1; i >= 0; i--) {
					if (hit(layers[i], p)) {
						target = layers[i];
						mode = 'move';
						break;
					}
				}
			}
			select(target);
			if (!target) {
				return;
			}
			e.preventDefault();
			canvas.focus({ preventScroll: true });
			var g = geom(target);
			drag = { mode: mode, id: e.pointerId, p: p, x: target.x, y: target.y, s: target.s, d: Math.max(1, dist(p, { x: g.cx, y: g.cy })) };
			try {
				canvas.setPointerCapture(e.pointerId);
			} catch (err) {
				/* ignore */
			}
		});

		canvas.addEventListener('pointermove', function (e) {
			if (!drag || !active || e.pointerId !== drag.id) {
				if (!drag) {
					var p0 = point(e);
					var over = layers.some(function (l) {
						return hit(l, p0);
					});
					canvas.style.cursor = over ? 'move' : 'default';
				}
				return;
			}
			e.preventDefault();
			var p = point(e);
			var a = area();
			var g = geom(active);
			if (drag.mode === 'move') {
				active.x = clamp(drag.x + (p.x - drag.p.x) / a.w, 0, 1);
				active.y = clamp(drag.y + (p.y - drag.p.y) / a.h, 0, 1);
			} else if (drag.mode === 'resize') {
				active.s = clamp((drag.s * dist(p, { x: g.cx, y: g.cy })) / drag.d, 3, 200);
			} else if (drag.mode === 'rotate') {
				var ang = (Math.atan2(p.y - g.cy, p.x - g.cx) * 180) / Math.PI + 90;
				var snap = Math.round(ang / 45) * 45;
				if (Math.abs(ang - snap) < 4) {
					ang = snap;
				}
				ang = ((((ang + 180) % 360) + 360) % 360) - 180;
				active.rot = ang;
			}
			syncControls();
			changed();
		});

		function endDrag(e) {
			if (drag && e.pointerId === drag.id) {
				drag = null;
				changed();
			}
		}
		canvas.addEventListener('pointerup', endDrag);
		canvas.addEventListener('pointercancel', endDrag);

		/* ---------- keyboard ---------- */

		canvas.addEventListener('keydown', function (e) {
			if (!active) {
				if (layers.length && /^(Arrow|Page)/.test(e.key)) {
					select(layers[layers.length - 1], true);
					e.preventDefault();
				}
				return;
			}
			var step = e.shiftKey ? 0.05 : 0.01;
			var handled = true;
			switch (e.key) {
				case 'ArrowLeft':
					active.x = clamp(active.x - step, 0, 1);
					break;
				case 'ArrowRight':
					active.x = clamp(active.x + step, 0, 1);
					break;
				case 'ArrowUp':
					active.y = clamp(active.y - step, 0, 1);
					break;
				case 'ArrowDown':
					active.y = clamp(active.y + step, 0, 1);
					break;
				case '+':
				case '=':
					active.s = clamp(active.s * 1.05, 3, 200);
					break;
				case '-':
				case '_':
					active.s = clamp(active.s / 1.05, 3, 200);
					break;
				case ']':
					active.rot = ((active.rot + (e.shiftKey ? 15 : 5) + 540) % 360) - 180;
					break;
				case '[':
					active.rot = ((active.rot - (e.shiftKey ? 15 : 5) + 540) % 360) - 180;
					break;
				case 'PageUp':
					move(1);
					break;
				case 'PageDown':
					move(-1);
					break;
				case 'Delete':
				case 'Backspace':
					removeActive();
					break;
				case 'Escape':
					select(null);
					break;
				default:
					handled = false;
			}
			if (handled) {
				e.preventDefault();
				syncControls();
				changed();
			}
		});

		/* ---------- controls ---------- */

		root.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-sclt-act]') : null;
			if (!btn || !root.contains(btn)) {
				return;
			}
			e.preventDefault();
			switch (btn.getAttribute('data-sclt-act')) {
				case 'add-text':
					addText();
					break;
				case 'forward':
					move(1);
					break;
				case 'backward':
					move(-1);
					break;
				case 'delete':
					removeActive();
					break;
			}
		});

		if (ui.text) {
			ui.text.addEventListener('input', function () {
				if (active && active.type === 'text' && ui.text.value.trim()) {
					active.text = ui.text.value.slice(0, 200);
					syncControls();
					changed();
				}
			});
			ui.text.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault(); // never submit the cart form from here.
					if (!(active && active.type === 'text')) {
						addText();
					}
				}
			});
		}
		if (ui.file) {
			ui.file.addEventListener('change', function () {
				upload(ui.file.files && ui.file.files[0]);
				ui.file.value = '';
			});
		}
		if (ui.layers) {
			ui.layers.addEventListener('change', function () {
				var id = ui.layers.value;
				select(
					layers.filter(function (l) {
						return l.id === id;
					})[0] || null,
					true
				);
			});
		}
		if (ui.font) {
			ui.font.addEventListener('change', function () {
				if (active && active.type === 'text') {
					active.font = ui.font.value;
					loadFont(active.font);
					changed();
				}
			});
		}
		if (ui.color) {
			ui.color.addEventListener('input', function () {
				if (active && active.type === 'text') {
					active.color = ui.color.value;
					changed();
				}
			});
		}
		if (ui.bold) {
			ui.bold.addEventListener('change', function () {
				if (active && active.type === 'text') {
					active.bold = ui.bold.checked;
					changed();
				}
			});
		}
		if (ui.size) {
			ui.size.addEventListener('input', function () {
				if (active) {
					active.s = clamp(parseFloat(ui.size.value) || active.s, 3, 200);
					changed();
				}
			});
		}
		if (ui.rot) {
			ui.rot.addEventListener('input', function () {
				if (active) {
					active.rot = clamp(parseFloat(ui.rot.value) || 0, -180, 180);
					changed();
				}
			});
		}

		/* ---------- add to cart ---------- */

		var form = root.closest ? root.closest('form') : null;
		function beforeSubmit(e) {
			writeFields(true);
			if (cfg.required && !layers.length) {
				e.preventDefault();
				if (e.stopImmediatePropagation) {
					e.stopImmediatePropagation();
				}
				say(t.required || 'Please add your design.', true);
				canvas.focus();
			}
		}
		if (form) {
			form.addEventListener('submit', beforeSubmit, true);
			var btn = form.querySelector('.single_add_to_cart_button');
			if (btn) {
				// Themes that add to cart over AJAX read the fields on click.
				btn.addEventListener('click', beforeSubmit, true);
			}
		}

		/* ---------- background (product image) ---------- */

		function start() {
			syncControls();
			draw();
			Object.keys(fonts).forEach(loadFont);
		}
		if (cfg.bg) {
			var img = new Image();
			try {
				if (new URL(cfg.bg, window.location.href).origin !== window.location.origin) {
					img.crossOrigin = 'anonymous';
				}
			} catch (e) {
				/* ignore */
			}
			img.onload = function () {
				bg = img;
				H = Math.round(clamp((W * img.naturalHeight) / (img.naturalWidth || 1), W * 0.4, W * 2));
				canvas.height = H;
				start();
			};
			img.onerror = function () {
				bg = null;
				start();
			};
			img.src = cfg.bg;
		} else {
			start();
		}
		window.addEventListener('resize', draw);

		// Small test/automation hook (read-only state + export), harmless in production.
		root.scltCanvas = {
			layers: function () {
				return serialize();
			},
			select: function (i) {
				select(layers[i] || null);
			},
			exportPreview: exportPreview
		};
	}

	function boot() {
		var roots = document.querySelectorAll('.sclt-canvas[data-sclt-canvas]');
		for (var i = 0; i < roots.length; i++) {
			if (!roots[i].scltCanvas) {
				init(roots[i]);
			}
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
