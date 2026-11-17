/**
 * StoreCanvas Lite: customer design canvas (product page).
 *
 * Vanilla JS, no dependencies, no outside requests. The customer adds text, images and
 * clip-art inside the print area of each side (front, and back when the store turns it
 * on), drawn on that side's product photo. Positions are stored relative to the print
 * area (x/y = centre, 0..1; s = text height as % of the area height, or image/clip-art
 * width as % of the area width; rot = degrees), so the design does not depend on screen
 * size. On add to cart the design JSON, a PNG preview per side and a print-ready PNG per
 * side (the print area only, transparent) go into hidden fields; the server validates all.
 */
(function () {
	'use strict';

	var W = 800; // internal canvas width (CSS scales it).
	var EXPORT_W = 600; // preview PNG width.
	var SIDES = ['front', 'back'];
	var HISTORY_MAX = 60;

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
			outline: q('.sclt-canvas__outline'),
			ow: q('.sclt-canvas__ow'),
			size: q('.sclt-canvas__size'),
			rot: q('.sclt-canvas__rot'),
			msg: q('.sclt-canvas__msg'),
			safe: q('.sclt-canvas__safe-note'),
			json: q('.sclt-canvas__json'),
			saveName: q('.sclt-canvas__save-name'),
			savedList: q('.sclt-canvas__saved-list'),
			saved: q('.sclt-canvas__saved')
		};
		var fields = {
			preview: { front: q('.sclt-canvas__preview'), back: q('.sclt-canvas__preview-back') },
			print: { front: q('.sclt-canvas__print'), back: q('.sclt-canvas__print-back') }
		};
		var fonts = {};
		(cfg.fonts || []).forEach(function (f) {
			fonts[f.key] = f.css;
		});
		var clips = {};
		(cfg.clipart || []).forEach(function (c) {
			clips[c.key] = c;
		});
		var paths = {};
		var maxLayers = parseInt(cfg.maxLayers, 10) || 10;
		var ratio = clamp(parseFloat(cfg.ratio) || 1, 0.3, 2);
		var printPx = clamp(parseInt(cfg.printPx, 10) || 1500, 300, 4000);
		var sides = {
			front: { area: cfg.area, src: cfg.bg || '', home: cfg.bg || '', bg: null, H: Math.round(W * ratio), tainted: false }
		};
		if (cfg.back) {
			sides.back = { area: cfg.back.area || cfg.area, src: cfg.back.bg || '', home: cfg.back.bg || '', bg: null, H: Math.round(W * ratio), tainted: false };
		}
		var side = 'front';
		var H = sides.front.H;
		var layers = [];
		var imgCache = {};
		var active = null;
		var seq = 0;
		var drag = null;
		var frame = 0;
		var previewTimer = 0;
		var history = [];
		var hpos = -1;
		var commitTimer = 0;

		canvas.width = W;
		canvas.height = H;

		/* ---------- geometry ---------- */

		function area() {
			var a = sides[side].area || { x: 25, y: 20, w: 50, h: 50 };
			return { x: (a.x / 100) * W, y: (a.y / 100) * H, w: (a.w / 100) * W, h: (a.h / 100) * H };
		}

		function safeRect() {
			var a = area();
			var m = (clamp(parseFloat(cfg.safe) || 0, 0, 20) / 100) * Math.min(a.w, a.h);
			return { x: a.x + m, y: a.y + m, w: a.w - 2 * m, h: a.h - 2 * m, m: m };
		}

		// Run fn with another side as the current one (geometry depends on its area/height).
		function withSide(s, fn) {
			var ps = side;
			var pH = H;
			side = s;
			H = sides[s].H;
			try {
				return fn();
			} finally {
				side = ps;
				H = pH;
			}
		}

		function sideLayers(s) {
			return layers.filter(function (l) {
				return l.side === s;
			});
		}

		function fontString(l, px) {
			return (l.bold ? 'bold ' : '') + Math.max(1, px).toFixed(1) + 'px ' + (fonts[l.font] || 'sans-serif');
		}

		function outlineWidth(l, px) {
			return l.type === 'text' && l.ow > 0 ? (px * l.ow) / 100 : 0;
		}

		function geom(l, c) {
			var a = area();
			var g = { cx: a.x + l.x * a.w, cy: a.y + l.y * a.h, w: 10, h: 10, px: 0 };
			if (l.type === 'text') {
				c = c || ctx;
				g.px = (l.s / 100) * a.h;
				c.save();
				c.font = fontString(l, g.px);
				g.w = Math.max(4, c.measureText(l.text).width) + 2 * outlineWidth(l, g.px);
				c.restore();
				g.h = g.px * 1.2 + 2 * outlineWidth(l, g.px);
			} else if (l.type === 'clipart') {
				g.w = (l.s / 100) * a.w;
				g.h = g.w;
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

		function bbox(l) {
			var g = geom(l);
			var pts = [
				toScreen(-g.w / 2, -g.h / 2, g, l.rot),
				toScreen(g.w / 2, -g.h / 2, g, l.rot),
				toScreen(g.w / 2, g.h / 2, g, l.rot),
				toScreen(-g.w / 2, g.h / 2, g, l.rot)
			];
			var b = { x0: Infinity, y0: Infinity, x1: -Infinity, y1: -Infinity };
			pts.forEach(function (p) {
				b.x0 = Math.min(b.x0, p.x);
				b.y0 = Math.min(b.y0, p.y);
				b.x1 = Math.max(b.x1, p.x);
				b.y1 = Math.max(b.y1, p.y);
			});
			return b;
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

		function clipPath(key) {
			if (!paths[key] && clips[key] && typeof Path2D === 'function') {
				paths[key] = new Path2D(clips[key].d);
			}
			return paths[key] || null;
		}

		function drawLayer(c, l) {
			var g = geom(l, c);
			c.save();
			c.translate(g.cx, g.cy);
			c.rotate((l.rot * Math.PI) / 180);
			if (l.type === 'text') {
				c.font = fontString(l, g.px);
				c.textAlign = 'center';
				c.textBaseline = 'middle';
				var ow = outlineWidth(l, g.px);
				if (ow > 0) {
					// Stroke first at twice the width, then fill: the outline sits outside the letters.
					c.lineJoin = 'round';
					c.miterLimit = 2;
					c.lineWidth = ow * 2;
					c.strokeStyle = l.outline || '#ffffff';
					c.strokeText(l.text, 0, 0);
				}
				c.fillStyle = l.color || '#000000';
				c.fillText(l.text, 0, 0);
			} else if (l.type === 'clipart') {
				var p = clipPath(l.art);
				if (p) {
					c.scale(g.w / 100, g.h / 100);
					c.translate(-50, -50);
					c.fillStyle = l.color || '#000000';
					c.fill(p);
				}
			} else if (l.img && l.img.naturalWidth) {
				c.drawImage(l.img, -g.w / 2, -g.h / 2, g.w, g.h);
			}
			c.restore();
		}

		function drawLayers(c) {
			var a = area();
			c.save();
			c.beginPath();
			c.rect(a.x, a.y, a.w, a.h);
			c.clip();
			sideLayers(side).forEach(function (l) {
				drawLayer(c, l);
			});
			c.restore();
		}

		function paint(c, exporting, withBg) {
			var a = area();
			var sd = sides[side];
			c.clearRect(0, 0, W, H);
			if (withBg && sd.bg) {
				c.drawImage(sd.bg, 0, 0, W, H);
			} else {
				c.fillStyle = exporting ? '#ffffff' : '#f3f3f3';
				c.fillRect(0, 0, W, H);
			}
			drawLayers(c);
			if (exporting) {
				if (!withBg || !sd.bg) {
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
			var sr = safeRect();
			if (sr.m > 0 && sr.w > 0 && sr.h > 0) {
				// Red and white dashes, so the line shows on any shirt color.
				c.setLineDash([4 * s, 4 * s]);
				c.lineWidth = 1.25 * s;
				c.lineDashOffset = 0;
				c.strokeStyle = 'rgba(214,54,56,.95)';
				c.strokeRect(sr.x, sr.y, sr.w, sr.h);
				c.lineDashOffset = 4 * s;
				c.strokeStyle = 'rgba(255,255,255,.9)';
				c.strokeRect(sr.x, sr.y, sr.w, sr.h);
			}
			c.restore();
			if (active && active.side === side) {
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

		function exportPreview(s) {
			s = s || side;
			if (!sides[s]) {
				return '';
			}
			return withSide(s, function () {
				var sd = sides[s];
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
					return render(!sd.tainted);
				} catch (e) {
					// A product image on another domain (CDN) taints the canvas: export the design alone.
					sd.tainted = true;
					try {
						return render(false);
					} catch (e2) {
						return '';
					}
				}
			});
		}

		// Print-ready PNG: the print area only, transparent, long edge printPx pixels.
		function exportPrint(s) {
			s = s || side;
			if (!sides[s]) {
				return '';
			}
			return withSide(s, function () {
				var a = area();
				var scale = printPx / Math.max(a.w, a.h);
				var out = document.createElement('canvas');
				out.width = Math.max(1, Math.round(a.w * scale));
				out.height = Math.max(1, Math.round(a.h * scale));
				var c = out.getContext('2d');
				c.setTransform(scale, 0, 0, scale, -a.x * scale, -a.y * scale);
				drawLayers(c);
				try {
					return out.toDataURL('image/png');
				} catch (e) {
					return '';
				}
			});
		}

		/* ---------- state -> form ---------- */

		function serializeLayer(l) {
			var o = { type: l.type, side: l.side, x: +l.x.toFixed(4), y: +l.y.toFixed(4), s: +l.s.toFixed(2), rot: Math.round(l.rot) };
			if (l.type === 'text') {
				o.text = l.text;
				o.font = l.font;
				o.color = l.color;
				o.bold = !!l.bold;
				if (l.ow > 0) {
					o.outline = l.outline;
					o.ow = +(+l.ow).toFixed(2);
				}
			} else if (l.type === 'clipart') {
				o.art = l.art;
				o.color = l.color;
			} else {
				o.file = l.file;
			}
			return o;
		}

		function serialize() {
			return layers.map(serializeLayer);
		}

		function writeFields(withPreview, withPrint) {
			if (ui.json) {
				ui.json.value = layers.length ? JSON.stringify({ v: 2, layers: serialize() }) : '';
			}
			SIDES.forEach(function (s) {
				var has = !!sides[s] && sideLayers(s).length > 0;
				var pf = fields.preview[s];
				var rf = fields.print[s];
				if (pf && (withPreview || !has)) {
					pf.value = has ? exportPreview(s) : '';
				}
				if (rf) {
					rf.value = has && withPrint ? exportPrint(s) : '';
				}
			});
		}

		function snapshot() {
			return JSON.stringify(
				layers.map(function (l) {
					var o = serializeLayer(l);
					o.id = l.id;
					return o;
				})
			);
		}

		function syncUndo() {
			var u = q('[data-sclt-act="undo"]');
			var r = q('[data-sclt-act="redo"]');
			if (u) {
				u.disabled = hpos <= 0;
			}
			if (r) {
				r.disabled = hpos >= history.length - 1;
			}
		}

		function commit() {
			clearTimeout(commitTimer);
			commitTimer = 0;
			var snap = snapshot();
			if (history[hpos] === snap) {
				return;
			}
			history = history.slice(0, hpos + 1);
			history.push(snap);
			if (history.length > HISTORY_MAX) {
				history.shift();
			}
			hpos = history.length - 1;
			syncUndo();
		}

		function refresh() {
			draw();
			writeFields(false, false);
			checkSafe();
			clearTimeout(previewTimer);
			previewTimer = setTimeout(function () {
				writeFields(true, false);
			}, 400);
		}

		function changed() {
			refresh();
			clearTimeout(commitTimer);
			commitTimer = setTimeout(commit, 350);
		}

		function restore(snap) {
			var keep = active ? active.id : null;
			var list;
			try {
				list = JSON.parse(snap);
			} catch (e) {
				return;
			}
			layers = list.map(function (o) {
				var l = {};
				Object.keys(o).forEach(function (key) {
					l[key] = o[key];
				});
				if (l.type === 'image') {
					l.img = imgCache[l.file] || null;
				}
				return l;
			});
			active =
				layers.filter(function (l) {
					return l.id === keep && l.side === side;
				})[0] || null;
			syncControls();
			refresh();
		}

		function undo() {
			if (commitTimer) {
				commit();
			}
			if (hpos <= 0) {
				return;
			}
			hpos--;
			restore(history[hpos]);
			syncUndo();
			say(t.undone || 'Undone.');
		}

		function redo() {
			if (hpos >= history.length - 1) {
				return;
			}
			hpos++;
			restore(history[hpos]);
			syncUndo();
			say(t.redone || 'Redone.');
		}

		function checkSafe() {
			if (!ui.safe) {
				return;
			}
			var out = layers.some(function (l) {
				return (
					!!sides[l.side] &&
					withSide(l.side, function () {
						var r = safeRect();
						if (r.m <= 0) {
							return false;
						}
						var b = bbox(l);
						var tol = 0.5;
						return b.x0 < r.x - tol || b.y0 < r.y - tol || b.x1 > r.x + r.w + tol || b.y1 > r.y + r.h + tol;
					})
				);
			});
			ui.safe.textContent = out ? t.outsideSafe || '' : '';
		}

		function say(text, isError) {
			if (!ui.msg) {
				return;
			}
			ui.msg.textContent = text || '';
			ui.msg.classList.toggle('is-error', !!isError);
		}

		function layerName(l, i) {
			var label;
			if (l.type === 'text') {
				label = (t.textLayer || 'Text') + ': ' + l.text;
			} else if (l.type === 'clipart') {
				label = (t.clipLayer || 'Clip-art') + ': ' + ((clips[l.art] && clips[l.art].label) || l.art);
			} else {
				label = t.imageLayer || 'Image';
			}
			if (label.length > 40) {
				label = label.slice(0, 39) + '…';
			}
			return i + 1 + '. ' + label;
		}

		function syncControls() {
			var mine = sideLayers(side);
			if (ui.layers) {
				ui.layers.innerHTML = '';
				if (!mine.length) {
					var none = document.createElement('option');
					none.value = '';
					none.textContent = t.noLayers || 'No layers yet';
					ui.layers.appendChild(none);
				}
				mine.forEach(function (l, i) {
					var o = document.createElement('option');
					o.value = l.id;
					o.textContent = layerName(l, i);
					o.selected = l === active;
					ui.layers.appendChild(o);
				});
				ui.layers.disabled = !mine.length;
			}
			var isText = !!(active && active.type === 'text');
			var isArt = !!(active && active.type === 'clipart');
			root.classList.toggle('is-text', isText);
			root.classList.toggle('is-art', isArt);
			if (active) {
				if (ui.size) {
					ui.size.value = Math.round(active.s);
				}
				if (ui.rot) {
					ui.rot.value = Math.round(active.rot);
				}
			}
			if ((isText || isArt) && ui.color) {
				ui.color.value = active.color;
			}
			if (isText) {
				if (ui.font) {
					ui.font.value = active.font;
				}
				if (ui.bold) {
					ui.bold.checked = !!active.bold;
				}
				if (ui.outline) {
					ui.outline.value = active.outline || '#ffffff';
				}
				if (ui.ow) {
					ui.ow.value = Math.round(active.ow || 0);
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
			root.querySelectorAll('[data-sclt-side]').forEach(function (b) {
				var on = b.getAttribute('data-sclt-side') === side;
				b.classList.toggle('is-active', on);
				b.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
		}

		function select(l, announce) {
			active = l || null;
			syncControls();
			draw();
			if (announce && active) {
				say((t.selected || 'Selected:') + ' ' + layerName(active, sideLayers(side).indexOf(active)));
			}
		}

		function canAdd() {
			if (layers.length >= maxLayers) {
				say(t.maxLayers || 'Too many layers.', true);
				return false;
			}
			return true;
		}

		// Adding, deleting and reordering are single undo steps of their own.
		function discrete(fn) {
			if (commitTimer) {
				commit();
			}
			fn();
			refresh();
			commit();
		}

		function add(l) {
			discrete(function () {
				l.id = 'l' + ++seq;
				l.side = side;
				layers.push(l);
				select(l);
			});
		}

		function addText() {
			if (!canAdd()) {
				return;
			}
			var text = ui.text && ui.text.value.trim() && !(active && active.type === 'text' && ui.text.value === active.text) ? ui.text.value.trim() : t.newText || 'Your text';
			var ow = ui.ow ? parseFloat(ui.ow.value) || 0 : 0;
			var l = {
				type: 'text',
				side: side,
				text: text.slice(0, 200),
				font: (ui.font && ui.font.value) || 'sans',
				color: (ui.color && ui.color.value) || '#000000',
				bold: !!(ui.bold && ui.bold.checked),
				outline: (ui.outline && ui.outline.value) || '#ffffff',
				ow: clamp(ow, 0, 30),
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
			add(l);
			loadFont(l.font);
			if (ui.text) {
				ui.text.value = l.text;
			}
		}

		function addClip(key) {
			if (!clips[key] || !canAdd()) {
				return;
			}
			var a = area();
			var s = 40;
			if ((a.w * s) / 100 > a.h * 0.9) {
				s = (a.h * 0.9 * 100) / a.w;
			}
			add({ type: 'clipart', art: key, color: (ui.color && ui.color.value) || '#000000', x: 0.5, y: 0.5, s: Math.max(3, s), rot: 0 });
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
			var target = side;
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
						imgCache[res.data.file] = img;
						if (side !== target) {
							switchSide(target);
						}
						var a = area();
						var s = 60;
						var h = ((a.w * s) / 100) * (img.naturalHeight / img.naturalWidth);
						if (h > a.h * 0.9) {
							s = (s * a.h * 0.9) / h;
						}
						add({ type: 'image', file: res.data.file, img: img, x: 0.5, y: 0.5, s: Math.max(3, s), rot: 0 });
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
			// Swap with the next layer on the same side.
			var i = layers.indexOf(active);
			var j = i + dir;
			while (j >= 0 && j < layers.length && layers[j].side !== side) {
				j += dir;
			}
			if (j < 0 || j >= layers.length) {
				return;
			}
			discrete(function () {
				layers[i] = layers[j];
				layers[j] = active;
				syncControls();
			});
			say((t.selected || 'Selected:') + ' ' + layerName(active, sideLayers(side).indexOf(active)));
		}

		function removeActive() {
			if (!active) {
				return;
			}
			discrete(function () {
				var mine = sideLayers(side);
				var i = mine.indexOf(active);
				layers.splice(layers.indexOf(active), 1);
				mine.splice(i, 1);
				select(mine[Math.min(i, mine.length - 1)] || null);
			});
			say(t.deleted || 'Layer deleted.');
		}

		/* ---------- sides and photos ---------- */

		function fitSide(s) {
			var sd = sides[s];
			sd.H = sd.bg ? Math.round(clamp((W * sd.bg.naturalHeight) / (sd.bg.naturalWidth || 1), W * 0.4, W * 2)) : Math.round(W * ratio);
			if (s === side) {
				H = sd.H;
				if (canvas.height !== H) {
					canvas.height = H;
				}
			}
		}

		function switchSide(s) {
			if (!sides[s] || s === side) {
				return;
			}
			side = s;
			fitSide(s);
			active = null;
			syncControls();
			draw();
		}

		function setPhoto(s, src, done) {
			var sd = sides[s];
			if (!sd) {
				return;
			}
			sd.src = src || '';
			if (!sd.src) {
				sd.bg = null;
				sd.tainted = false;
				fitSide(s);
				if (done) {
					done();
				}
				return;
			}
			var img = new Image();
			try {
				if (new URL(sd.src, window.location.href).origin !== window.location.origin) {
					img.crossOrigin = 'anonymous';
				}
			} catch (e) {
				/* ignore */
			}
			img.onload = function () {
				if (sd.src !== src) {
					return; // a newer photo was asked for meanwhile.
				}
				sd.bg = img;
				sd.tainted = false;
				fitSide(s);
				if (done) {
					done();
				}
			};
			img.onerror = function () {
				if (sd.src !== src) {
					return;
				}
				sd.bg = null;
				fitSide(s);
				if (done) {
					done();
				}
			};
			img.src = sd.src;
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
				var mine = sideLayers(side);
				for (var i = mine.length - 1; i >= 0; i--) {
					if (hit(mine[i], p)) {
						target = mine[i];
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
					var over = sideLayers(side).some(function (l) {
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
				commit();
			}
		}
		canvas.addEventListener('pointerup', endDrag);
		canvas.addEventListener('pointercancel', endDrag);

		/* ---------- keyboard ---------- */

		canvas.addEventListener('keydown', function (e) {
			var key = (e.key || '').toLowerCase();
			if ((e.ctrlKey || e.metaKey) && (key === 'z' || key === 'y')) {
				e.preventDefault();
				if (key === 'y' || e.shiftKey) {
					redo();
				} else {
					undo();
				}
				return;
			}
			if (!active) {
				var mine = sideLayers(side);
				if (mine.length && /^(Arrow|Page)/.test(e.key)) {
					select(mine[mine.length - 1], true);
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

		/* ---------- saved designs ("My designs") ---------- */

		var storeKey = 'sclt_designs_' + cfg.productId;

		function api(op, data) {
			var fd = new FormData();
			fd.append('action', cfg.designsAction);
			fd.append('op', op);
			fd.append('nonce', cfg.nonce);
			fd.append('product_id', String(cfg.productId));
			Object.keys(data || {}).forEach(function (key) {
				fd.append(key, data[key]);
			});
			return fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (!res || !res.success) {
						throw new Error(res && res.data && res.data.message ? res.data.message : '');
					}
					return res.data || {};
				});
		}

		function localList() {
			try {
				var list = JSON.parse(window.localStorage.getItem(storeKey) || '[]');
				return Array.isArray(list) ? list : [];
			} catch (e) {
				return [];
			}
		}

		function localWrite(list) {
			try {
				window.localStorage.setItem(storeKey, JSON.stringify(list.slice(0, 20)));
				return true;
			} catch (e) {
				return false;
			}
		}

		var savedCache = [];

		function renderSaved(list) {
			savedCache = list || [];
			if (!ui.savedList) {
				return;
			}
			ui.savedList.innerHTML = '';
			if (!savedCache.length) {
				var li0 = document.createElement('li');
				li0.textContent = t.noSaved || 'No saved designs yet.';
				ui.savedList.appendChild(li0);
				return;
			}
			savedCache.forEach(function (d) {
				var li = document.createElement('li');
				var name = document.createElement('span');
				name.className = 'sclt-canvas__saved-name';
				var when = d.time ? new Date(d.time * 1000).toLocaleDateString() : '';
				name.textContent = (d.name || t.untitled || 'My design') + (when ? ' (' + when + ')' : '');
				li.appendChild(name);
				[
					['load', t.load || 'Load'],
					['remove-saved', t.remove || 'Delete']
				].forEach(function (b) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'button sclt-canvas__btn';
					btn.setAttribute('data-sclt-act', b[0]);
					btn.setAttribute('data-id', String(d.id));
					btn.textContent = b[1];
					li.appendChild(btn);
				});
				ui.savedList.appendChild(li);
			});
		}

		function refreshSaved() {
			if (!cfg.designsAction) {
				return;
			}
			if (cfg.loggedIn) {
				api('list')
					.then(function (data) {
						renderSaved(data.designs || []);
					})
					.catch(function () {
						renderSaved([]);
					});
			} else {
				renderSaved(localList());
			}
		}

		function saveDesign() {
			if (!layers.length) {
				say(t.nothingSave || 'Add something first.', true);
				return;
			}
			var name = ((ui.saveName && ui.saveName.value) || '').trim().slice(0, 60) || t.untitled || 'My design';
			var json = JSON.stringify({ v: 2, layers: serialize() });
			api('save', { name: name, design: json })
				.then(function (data) {
					if (!cfg.loggedIn) {
						var list = localList();
						list.unshift({ id: 'g' + Date.now(), name: name, time: Math.round(Date.now() / 1000), design: json });
						if (!localWrite(list)) {
							throw new Error('');
						}
					}
					if (ui.saveName) {
						ui.saveName.value = '';
					}
					say(t.saved || 'Design saved.');
					refreshSaved();
					return data;
				})
				.catch(function (err) {
					say((err && err.message) || t.saveFail || 'The design could not be saved.', true);
				});
		}

		function applyDesign(design) {
			var list = design && Array.isArray(design.layers) ? design.layers : [];
			var files = [];
			list.forEach(function (l) {
				if (l && l.type === 'image' && l.file && files.indexOf(l.file) === -1) {
					files.push(l.file);
				}
			});
			var urls = files.length ? api('files', { files: JSON.stringify(files) }) : Promise.resolve({ urls: {} });
			return urls.then(function (data) {
				var map = data.urls || {};
				var missing = false;
				var jobs = list.map(function (o) {
					if (!o || (o.side && !sides[o.side])) {
						return Promise.resolve(null);
					}
					var l = {};
					Object.keys(o).forEach(function (key) {
						l[key] = o[key];
					});
					l.side = l.side === 'back' ? 'back' : 'front';
					l.x = parseFloat(l.x) || 0.5;
					l.y = parseFloat(l.y) || 0.5;
					l.s = parseFloat(l.s) || 20;
					l.rot = parseFloat(l.rot) || 0;
					l.ow = parseFloat(l.ow) || 0;
					if (l.type === 'clipart' && !clips[l.art]) {
						return Promise.resolve(null);
					}
					if (l.type !== 'image') {
						return Promise.resolve(l);
					}
					if (!map[l.file]) {
						missing = true;
						return Promise.resolve(null);
					}
					return new Promise(function (resolve) {
						var img = new Image();
						img.onload = function () {
							imgCache[l.file] = img;
							l.img = img;
							resolve(l);
						};
						img.onerror = function () {
							missing = true;
							resolve(null);
						};
						img.src = map[l.file];
					});
				});
				return Promise.all(jobs).then(function (built) {
					layers = built
						.filter(function (l) {
							return !!l;
						})
						.slice(0, maxLayers)
						.map(function (l) {
							l.id = 'l' + ++seq;
							return l;
						});
					active = null;
					syncControls();
					changed();
					commit();
					layers.forEach(function (l) {
						if (l.type === 'text') {
							loadFont(l.font);
						}
					});
					say(missing ? t.loadMissing || 'Some images were left out.' : t.loaded || 'Design loaded.', missing);
				});
			});
		}

		function loadSaved(id) {
			var d = savedCache.filter(function (x) {
				return String(x.id) === String(id);
			})[0];
			if (!d) {
				return;
			}
			var design = d.design;
			if (typeof design === 'string') {
				try {
					design = JSON.parse(design);
				} catch (e) {
					design = null;
				}
			}
			applyDesign(design).catch(function (err) {
				say((err && err.message) || t.saveFail || 'Failed.', true);
			});
		}

		function removeSaved(id) {
			if (cfg.loggedIn) {
				api('delete', { id: String(id) })
					.then(refreshSaved)
					.catch(function (err) {
						say((err && err.message) || t.saveFail || 'Failed.', true);
					});
			} else {
				localWrite(
					localList().filter(function (x) {
						return String(x.id) !== String(id);
					})
				);
				refreshSaved();
			}
		}

		if (ui.saved) {
			ui.saved.addEventListener('toggle', function () {
				if (ui.saved.open) {
					refreshSaved();
				}
			});
		}

		/* ---------- controls ---------- */

		root.addEventListener('click', function (e) {
			var sideBtn = e.target.closest ? e.target.closest('[data-sclt-side]') : null;
			if (sideBtn && root.contains(sideBtn)) {
				e.preventDefault();
				switchSide(sideBtn.getAttribute('data-sclt-side'));
				return;
			}
			var btn = e.target.closest ? e.target.closest('[data-sclt-act]') : null;
			if (!btn || !root.contains(btn)) {
				return;
			}
			e.preventDefault();
			switch (btn.getAttribute('data-sclt-act')) {
				case 'add-text':
					addText();
					break;
				case 'add-clip':
					addClip(btn.getAttribute('data-sclt-art'));
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
				case 'undo':
					undo();
					break;
				case 'redo':
					redo();
					break;
				case 'save':
					saveDesign();
					break;
				case 'load':
					loadSaved(btn.getAttribute('data-id'));
					break;
				case 'remove-saved':
					removeSaved(btn.getAttribute('data-id'));
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
		if (ui.saveName) {
			ui.saveName.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					saveDesign();
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
		function onText(el, evt, fn) {
			if (el) {
				el.addEventListener(evt, function () {
					if (active && active.type === 'text') {
						fn();
						changed();
					}
				});
			}
		}
		onText(ui.font, 'change', function () {
			active.font = ui.font.value;
			loadFont(active.font);
		});
		onText(ui.bold, 'change', function () {
			active.bold = ui.bold.checked;
		});
		onText(ui.outline, 'input', function () {
			active.outline = ui.outline.value;
			if (!(active.ow > 0)) {
				active.ow = 6;
				if (ui.ow) {
					ui.ow.value = 6;
				}
			}
		});
		onText(ui.ow, 'input', function () {
			active.ow = clamp(parseFloat(ui.ow.value) || 0, 0, 30);
		});
		if (ui.color) {
			ui.color.addEventListener('input', function () {
				if (active && (active.type === 'text' || active.type === 'clipart')) {
					active.color = ui.color.value;
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
			writeFields(true, true);
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

		/* ---------- follow the chosen variation's photo (front) ---------- */

		if (cfg.followVariation && typeof window.jQuery === 'function' && form) {
			var $form = window.jQuery(form);
			$form.on('found_variation', function (e, variation) {
				var img = variation && variation.image;
				var src = img && (img.src || img.full_src);
				if (src && src !== sides.front.src) {
					setPhoto('front', src, function () {
						draw();
						refresh();
					});
				}
			});
			$form.on('reset_data', function () {
				if (sides.front.src !== sides.front.home) {
					setPhoto('front', sides.front.home, function () {
						draw();
						refresh();
					});
				}
			});
		}

		/* ---------- start ---------- */

		var pending = Object.keys(sides).length;
		function started() {
			pending--;
			if (pending > 0) {
				return;
			}
			fitSide(side);
			syncControls();
			draw();
			Object.keys(fonts).forEach(loadFont);
			commit();
		}
		Object.keys(sides).forEach(function (s) {
			setPhoto(s, sides[s].src, started);
		});
		window.addEventListener('resize', draw);

		// Small test/automation hook (read-only state + export), harmless in production.
		root.scltCanvas = {
			layers: function () {
				return serialize();
			},
			select: function (i) {
				select(sideLayers(side)[i] || null);
			},
			side: function () {
				return side;
			},
			height: function () {
				return H;
			},
			switchSide: switchSide,
			undo: undo,
			redo: redo,
			exportPreview: exportPreview,
			exportPrint: exportPrint,
			applyDesign: applyDesign,
			commit: commit
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
