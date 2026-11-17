(function () {
	'use strict';

	function cfg() {
		return typeof scltLivePrice !== 'undefined' ? scltLivePrice : null;
	}

	// Per-unit price contribution for one field. Mirrors PHP SCLT_Cart_Order::price_for() exactly
	// so the preview matches what the server charges: qty multiplies the configured amount by the
	// number entered in the field (not the cart quantity); flat may be negative (a discount).
	function fieldExtra(field, value, base) {
		var type = field.price_type || 'none';
		if (type === 'none' || value === '' || value === null || value === undefined) {
			return 0;
		}
		if ((field.type || '') === 'checkbox' && !value) {
			return 0;
		}
		if (type === 'lookup') {
			// Sum the per-choice prices for the selected value(s), mirroring the server.
			var prices = field.choice_prices || {};
			var picked = Array.isArray(value) ? value : [value];
			var sum = 0;
			picked.forEach(function (v) {
				var key = String(v);
				if (Object.prototype.hasOwnProperty.call(prices, key)) {
					sum += parseFloat(prices[key]) || 0;
				}
			});
			return sum;
		}
		var amount = parseFloat(field.price) || 0;
		switch (type) {
			case 'flat':
				return amount;
			case 'percent':
				return base * (amount / 100);
			case 'qty':
				// Same rule as the server (SCLT_Cart_Order::sanitize_qty): plain digits only, whole units, capped.
				var q = String(value == null ? '' : value).trim();
				if (!/^\d+(\.\d+)?$/.test(q)) return 0;
				return amount * Math.min(10000, Math.floor(parseFloat(q)));
			case 'per_char':
				return amount * String(value).length;
			default:
				return 0;
		}
	}

	// Quantity tier for this quantity: the highest minimum not above it. Mirrors
	// SCLT_Qty_Tiers::tier_for(); the cart also counts this product's other cart lines.
	function tierFor(tiers, qty) {
		var best = null;
		(tiers || []).forEach(function (t) {
			if (t && qty >= (parseInt(t.min, 10) || 0)) best = t;
		});
		return best;
	}

	// Mirrors SCLT_Qty_Tiers::discounted_unit().
	function discountedUnit(unit, tier) {
		if (tier) {
			var amount = parseFloat(tier.amount) || 0;
			if (tier.type === 'fixed') {
				unit -= amount;
			} else {
				unit -= unit * (Math.min(100, amount) / 100);
			}
		}
		return unit < 0 ? 0 : unit;
	}

	function readValues(root) {
		var values = {};
		if (!root) return values;
		root.querySelectorAll('[name^="sclt_option["]').forEach(function (el) {
			var m = el.name.match(/^sclt_option\[([^\]]+)\]/);
			if (!m) return;
			var id = m[1];
			if (el.type === 'checkbox') {
				values[id] = el.checked ? el.value || '1' : '';
			} else if (el.type === 'radio') {
				if (el.checked) values[id] = el.value;
			} else {
				values[id] = el.value;
			}
		});
		return values;
	}

	function qtyValue() {
		var q =
			document.querySelector('form.cart input.qty') ||
			document.querySelector('input.qty') ||
			document.querySelector('[name="quantity"]');
		var n = q ? parseFloat(q.value) : 1;
		return isNaN(n) || n < 1 ? 1 : n;
	}

	function formatMoney(amount) {
		var c = cfg();
		if (!c) return String(amount);
		// Prefer WooCommerce accounting if present.
		if (typeof accounting !== 'undefined' && accounting.formatMoney && c.currency) {
			try {
				return accounting.formatMoney(amount, c.currency);
			} catch (e) {
				/* fall through */
			}
		}
		var decimals = c.decimals != null ? c.decimals : 2;
		var symbol = c.symbol || '';
		var fixed = amount.toFixed(decimals);
		if (c.currencyPos === 'left') return symbol + fixed;
		if (c.currencyPos === 'left_space') return symbol + ' ' + fixed;
		if (c.currencyPos === 'right_space') return fixed + ' ' + symbol;
		return fixed + symbol;
	}

	// Base price set at runtime: the sc:base-price event, or the WooCommerce variation the
	// customer picked. null means "use the localized scltLivePrice.basePrice".
	var baseOverride = null;
	// Named per-item extras and one-time charges from other scripts (sc:extra-price).
	var extras = {};
	// Quantity reported by another script (sc:qty), e.g. a size grid that posts several sizes.
	var qtyOverride = null;

	function productId() {
		var c = cfg();
		return c && c.productId ? String(c.productId) : '';
	}

	function forOtherProduct(d) {
		return !!(d && typeof d === 'object' && d.productId != null && productId() && String(d.productId) !== productId());
	}

	function extrasTotals() {
		var tot = { amount: 0, once: 0 };
		Object.keys(extras).forEach(function (k) {
			tot.amount += extras[k].amount;
			tot.once += extras[k].once;
		});
		return tot;
	}

	function calc() {
		var c = cfg();
		if (!c) return null;
		var base = baseOverride !== null ? baseOverride : parseFloat(c.basePrice) || 0;
		var fields = c.fields || [];
		var root = document.querySelector('.sclt-options');
		var values = readValues(root);
		var qty = qtyOverride !== null ? qtyOverride : qtyValue();
		// sclt_price_extra is a per-unit total; WooCommerce multiplies the unit price by the cart
		// quantity. Sum the per-unit field extras exactly as the server does.
		var unitExtra = 0;
		fields.forEach(function (f) {
			if (!f || !f.id) return;
			// Hidden by show_if: skip pricing when not visible.
			var wrap = root
				? root.querySelector('.sclt-option-field[data-field-id="' + f.id + '"]')
				: null;
			if (wrap && wrap.style.display === 'none') return;
			var val = values[f.id];
			if (val === undefined) val = '';
			unitExtra += fieldExtra(f, val, base);
		});
		var ex = extrasTotals();
		unitExtra += ex.amount;
		// The server floors a discounted unit price at zero; mirror that so the preview agrees.
		var unit = base + unitExtra;
		if (unit < 0) unit = 0;
		var tier = tierFor(c.tiers, qty);
		var undiscounted = unit;
		unit = discountedUnit(unit, tier);
		return {
			base: base,
			extra: unitExtra,
			tier: tier,
			saving: undiscounted - unit,
			unit: unit,
			line: unit * qty + ex.once,
			once: ex.once,
			qty: qty,
		};
	}

	function priceNodes() {
		var nodes = [];
		var selectors = [
			'.summary .price',
			'.product .price',
			'.wp-block-woocommerce-product-price .price',
			'.wc-block-components-product-price',
			'p.price',
		];
		selectors.forEach(function (sel) {
			document.querySelectorAll(sel).forEach(function (el) {
				if (nodes.indexOf(el) === -1) nodes.push(el);
			});
		});
		return nodes;
	}

	function ensureBreakdown() {
		var el = document.getElementById('sclt-live-price');
		if (el) return el;
		var anchor =
			document.querySelector('.summary .price') ||
			document.querySelector('.product .price') ||
			document.querySelector('form.cart');
		el = document.createElement('p');
		el.id = 'sclt-live-price';
		el.className = 'sclt-live-price description';
		el.style.margin = '6px 0';
		if (anchor && anchor.parentNode) {
			anchor.parentNode.insertBefore(el, anchor.nextSibling);
		} else if (anchor) {
			anchor.appendChild(el);
		} else {
			document.body.appendChild(el);
		}
		return el;
	}

	function hasPricing(c) {
		return !!(c && ((c.fields && c.fields.length) || (c.tiers && c.tiers.length)));
	}

	function markTierRow(tier) {
		var min = tier ? String(tier.min) : '1';
		document.querySelectorAll('.sclt-qty-tiers tr[data-sclt-tier-min]').forEach(function (tr) {
			tr.classList.toggle('is-active', tr.getAttribute('data-sclt-tier-min') === min);
		});
	}

	function update() {
		var c = cfg();
		if (!hasPricing(c)) return;
		var r = calc();
		if (!r) return;
		var html =
			'<span class="sclt-live-base">' +
			(c.i18n.base || 'Base') +
			': ' +
			formatMoney(r.base) +
			'</span>';
		if (Math.abs(r.extra) > 0.00001) {
			html +=
				' + <span class="sclt-live-extra">' +
				(c.i18n.extras || 'options') +
				': ' +
				formatMoney(r.extra) +
				'</span>';
		}
		if (r.tier && r.saving > 0.00001) {
			html +=
				' − <span class="sclt-live-tier">' +
				((c.i18n && c.i18n.tier) || 'quantity discount') +
				': ' +
				formatMoney(r.saving) +
				'</span>';
		}
		markTierRow(r.tier);
		html +=
			' = <strong class="sclt-live-total">' +
			formatMoney(r.unit) +
			'</strong>';
		if (r.qty > 1 || r.once > 0.00001) {
			html +=
				' <span class="sclt-live-line">(' +
				(c.i18n.line || 'line') +
				': ' +
				formatMoney(r.line) +
				')</span>';
		}
		var breakdown = ensureBreakdown();
		breakdown.innerHTML = html;

		// Update visible main price amounts to unit total (classic + block).
		priceNodes().forEach(function (node) {
			if (!node.getAttribute('data-sclt-base-html')) {
				node.setAttribute('data-sclt-base-html', node.innerHTML);
			}
			var amounts = node.querySelectorAll('.woocommerce-Price-amount bdi, .woocommerce-Price-amount, .wc-block-components-product-price__value');
			if (amounts.length) {
				amounts.forEach(function (a) {
					// Keep structure; replace text.
					var sym = a.querySelector('.woocommerce-Price-currencySymbol');
					var symbolHtml = sym ? sym.outerHTML : '';
					if (c.currencyPos === 'left' || c.currencyPos === 'left_space') {
						a.innerHTML =
							symbolHtml +
							(c.currencyPos === 'left_space' ? ' ' : '') +
							r.unit.toFixed(c.decimals != null ? c.decimals : 2);
					} else {
						a.innerHTML =
							r.unit.toFixed(c.decimals != null ? c.decimals : 2) +
							(c.currencyPos === 'right_space' ? ' ' : '') +
							symbolHtml;
					}
				});
			} else {
				node.innerHTML = formatMoney(r.unit);
			}
		});
	}

	// Read a price out of an sc:base-price payload: a number, a numeric string, or an object
	// { price, productId }. null for anything unusable or for another product's payload.
	function priceFromPayload(p) {
		if (p && typeof p === 'object') {
			if (forOtherProduct(p)) return null;
			p = p.price;
		}
		if (p === null || p === undefined || p === '') return null;
		var n = parseFloat(p);
		return isNaN(n) || n < 0 ? null : n;
	}

	// Events fired from inside another product's cart form are not ours.
	function foreignTarget(t) {
		if (!t || !t.closest || t === document || t === window) return false;
		var form = t.closest('form.cart');
		if (!form) return false;
		var mine = document.querySelector('.sclt-options');
		var myForm = mine ? mine.closest('form.cart') : null;
		return !!myForm && form !== myForm;
	}

	function setBase(p, target) {
		if (target && foreignTarget(target)) return;
		if (p === 'reset') {
			baseOverride = null;
		} else {
			var n = priceFromPayload(p);
			if (n === null) return;
			baseOverride = n;
		}
		update();
	}

	function bind() {
		var c = cfg();
		if (!hasPricing(c)) return;
		// Themes and other scripts set the base price with
		//   el.dispatchEvent(new CustomEvent('sc:base-price', { bubbles: true, detail: 12.5 }))
		//   jQuery(el).trigger('sc:base-price', [12.5])
		// detail may be { price: 12.5, productId: 123 }; null or '' goes back to the product price.
		document.addEventListener('sc:base-price', function (e) {
			var d = e.detail;
			setBase(d === null || d === '' ? 'reset' : d, e.target);
		});
		// Per-item extras and one-time charges:
		//   document.dispatchEvent(new CustomEvent('sc:extra-price', { detail: {
		//     productId: 23, key: 'engraving', amount: 3.5, once: 10 } }))
		document.addEventListener('sc:extra-price', function (e) {
			var d = e.detail;
			if (!d || typeof d !== 'object' || forOtherProduct(d)) return;
			var amount = parseFloat(d.amount);
			var once = parseFloat(d.once);
			extras[String(d.key || 'extra')] = {
				amount: isNaN(amount) || amount < 0 ? 0 : amount,
				once: isNaN(once) || once < 0 ? 0 : once,
			};
			update();
		});
		document.addEventListener('sc:qty', function (e) {
			var d = e.detail || {};
			if (forOtherProduct(d)) return;
			var n = parseInt(d.qty, 10);
			qtyOverride = isNaN(n) || n < 1 ? null : n;
			update();
		});
		if (typeof window.jQuery === 'function') {
			var $ = window.jQuery;
			$(document).on('sc:base-price', function (e, p) {
				if (e.originalEvent) return; // native event, handled above.
				setBase(p === null || p === '' || p === undefined ? 'reset' : p, e.target);
			});
			// WooCommerce variation forms: follow the picked variation's price.
			$(document).on('found_variation', 'form.variations_form', function (e, variation) {
				if (variation && variation.display_price !== undefined) setBase(variation.display_price, e.target);
			});
			$(document).on('reset_data', 'form.variations_form', function (e) {
				setBase('reset', e.target);
			});
		}
		document.addEventListener('change', function (e) {
			if (!e.target) return;
			if (e.target.closest && (e.target.closest('.sclt-options') || e.target.matches('input.qty') || e.target.name === 'quantity')) {
				update();
			}
		});
		document.addEventListener('input', function (e) {
			if (!e.target) return;
			if (e.target.closest && e.target.closest('.sclt-options')) update();
			if (e.target.matches && (e.target.matches('input.qty') || e.target.name === 'quantity')) update();
		});
		update();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
})();
