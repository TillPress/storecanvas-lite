(function ($) {
	'use strict';

	function uid() {
		return 'f' + Math.random().toString(36).slice(2, 8);
	}

	function parseJSON(sel, fallback) {
		try {
			var v = $(sel).val();
			if (!v) return fallback;
			var d = JSON.parse(v);
			return Array.isArray(d) ? d : fallback;
		} catch (e) {
			return fallback;
		}
	}

	function fieldTypes() {
		try {
			return JSON.parse($('#sclt-field-types').text() || '{}');
		} catch (e) {
			return {};
		}
	}

	function priceTypes() {
		try {
			return JSON.parse($('#sclt-price-types').text() || '{}');
		} catch (e) {
			return {};
		}
	}

	/* ---- Fields ---- */
	function renderFields() {
		var fields = parseJSON('#sclt_options_fields_json', []);
		var types = fieldTypes();
		var prices = priceTypes();
		var $list = $('#sclt-fields-list').empty();
		fields.forEach(function (f) {
			var typeOpts = Object.keys(types)
				.map(function (k) {
					return (
						'<option value="' +
						k +
						'"' +
						(f.type === k ? ' selected' : '') +
						'>' +
						esc(types[k]) +
						'</option>'
					);
				})
				.join('');
			var priceOpts = Object.keys(prices)
				.map(function (k) {
					return (
						'<option value="' +
						k +
						'"' +
						(f.price_type === k ? ' selected' : '') +
						'>' +
						esc(prices[k]) +
						'</option>'
					);
				})
				.join('');
			var choicesStr = '';
			if (Array.isArray(f.choices)) {
				choicesStr = f.choices
					.map(function (c) {
						if (typeof c !== 'object') return String(c);
						var s = c.label || c.value || '';
						if (c.image_url) s += '|' + c.image_url;
						else if (c.image_id) s += '|#' + c.image_id;
						if (c.price != null && c.price !== '' && Number(c.price) !== 0) s += '=' + c.price;
						if (c.stock_qty != null && c.stock_qty !== '') s += '@' + c.stock_qty;
						return s;
					})
					.join(', ');
			}
			var showIfField = (f.show_if && f.show_if.field) || f.show_if_field || '';
			var showIfValue = (f.show_if && f.show_if.value != null) ? f.show_if.value : (f.show_if_value || '');
			var rolesStr = Array.isArray(f.roles_allowed) ? f.roles_allowed.join(',') : (f.roles_allowed || '');
			var varsStr = Array.isArray(f.variation_ids) ? f.variation_ids.join(',') : (f.variation_ids || '');
			var defVal = Array.isArray(f.default_value) ? f.default_value.join(',') : (f.default_value != null ? f.default_value : '');
			var $row = $(
				'<div class="sclt-row sclt-field-row" style="margin-bottom:8px;padding:8px;border:1px solid #ddd;background:#fff;">' +
					'<input type="hidden" class="sclt-field-id" value="' +
					esc(f.id || uid()) +
					'" />' +
					'<label>Label <input type="text" class="sclt-field-label" value="' +
					esc(f.label || '') +
					'" /></label> ' +
					'<label>Type <select class="sclt-field-type">' +
					typeOpts +
					'</select></label> ' +
					'<label><input type="checkbox" class="sclt-field-required"' +
					(f.required ? ' checked' : '') +
					'/> Required</label><br/>' +
					'<label>Price <select class="sclt-field-price-type">' +
					priceOpts +
					'</select></label> ' +
					'<input type="number" class="sclt-field-price" value="' +
					(f.price != null ? f.price : 0) +
					'" step="0.01" style="width:80px" /> ' +
					'<label class="sclt-choices-wrap">Choices <input type="text" class="sclt-field-choices" placeholder="A, B=5, C|url@stock" title="=n adds a per-choice price (lookup pricing). |url = image. @n = stock." value="' +
					esc(choicesStr) +
					'" style="width:220px" title="label or label|image_url@stock" /></label><br/>' +
					'<label>Default <input type="text" class="sclt-field-default" value="' +
					esc(defVal) +
					'" style="width:100px" /></label> ' +
					'<label>Min chars <input type="number" class="sclt-field-min-chars" value="' +
					(f.min_chars != null ? f.min_chars : '') +
					'" style="width:60px" /></label> ' +
					'<label>Max chars <input type="number" class="sclt-field-max-chars" value="' +
					(f.max_chars != null ? f.max_chars : '') +
					'" style="width:60px" /></label> ' +
					'<label>Min <input type="number" class="sclt-field-min" value="' +
					(f.min != null ? f.min : '') +
					'" style="width:60px" step="any" /></label> ' +
					'<label>Max <input type="number" class="sclt-field-max" value="' +
					(f.max != null ? f.max : '') +
					'" style="width:60px" step="any" /></label> ' +
					'<label>Step <input type="number" class="sclt-field-step" value="' +
					(f.step != null ? f.step : '') +
					'" style="width:60px" step="any" /></label><br/>' +
					'<label>Roles <input type="text" class="sclt-field-roles" placeholder="customer,wholesale" value="' +
					esc(rolesStr) +
					'" style="width:140px" /></label> ' +
					'<label>Variation IDs <input type="text" class="sclt-field-variations" placeholder="101,102" value="' +
					esc(varsStr) +
					'" style="width:120px" /></label> ' +
					'<label>Show if field <input type="text" class="sclt-field-show-if-field" placeholder="other_field_id" value="' +
					esc(showIfField) +
					'" style="width:120px" /></label> ' +
					'<label>equals <input type="text" class="sclt-field-show-if-value" placeholder="value" value="' +
					esc(showIfValue) +
					'" style="width:100px" /></label> ' +
					'<button type="button" class="button sclt-remove-field">Remove</button>' +
					'</div>'
			);
			$row.data('scltChoices', Array.isArray(f.choices) ? f.choices : []);
			$list.append($row);
		});
	}

	function parseChoiceToken(s) {
		s = s.trim();
		if (!s) return null;
		var stock = null;
		var at = s.lastIndexOf('@');
		if (at > 0 && /^\d+$/.test(s.slice(at + 1))) {
			stock = parseInt(s.slice(at + 1), 10);
			s = s.slice(0, at);
		}
		// Per-choice price for lookup pricing: "Large=10", "Student=-5".
		var price = null;
		var eq = s.lastIndexOf('=');
		if (eq > 0 && /^-?\d+(?:\.\d+)?$/.test(s.slice(eq + 1).trim())) {
			price = parseFloat(s.slice(eq + 1));
			s = s.slice(0, eq).trim();
		}
		var image_url = '';
		var image_id = 0;
		var pipe = s.indexOf('|');
		var label = s;
		if (pipe >= 0) {
			label = s.slice(0, pipe).trim();
			var img = s.slice(pipe + 1).trim();
			if (img.charAt(0) === '#') image_id = parseInt(img.slice(1), 10) || 0;
			else image_url = img;
		}
		var row = { value: label, label: label };
		if (image_url) row.image_url = image_url;
		if (image_id) row.image_id = image_id;
		if (stock != null) row.stock_qty = stock;
		if (price != null) row.price = price;
		return row;
	}

	function syncFields() {
		var fields = [];
		$('#sclt-fields-list .sclt-field-row').each(function () {
			var $r = $(this);
			var type = $r.find('.sclt-field-type').val();
			var choicesRaw = $r.find('.sclt-field-choices').val() || '';
			var choices = [];
			var choiceTypes = ['select', 'radio', 'checkbox', 'multi_select', 'image_choice'];
			if (choicesRaw && choiceTypes.indexOf(type) >= 0) {
				choices = choicesRaw.split(',').map(parseChoiceToken).filter(Boolean);
				var prevChoices = $r.data('scltChoices') || [];
				choices.forEach(function (c) {
					for (var i = 0; i < prevChoices.length; i++) {
						var p = prevChoices[i];
						if (p && typeof p === 'object' && (p.label || p.value) === c.label && p.value) {
							c.value = p.value;
							break;
						}
					}
				});
			}
			var showIfField = ($r.find('.sclt-field-show-if-field').val() || '').trim();
			var showIfValue = $r.find('.sclt-field-show-if-value').val() || '';
			var row = {
				id: $r.find('.sclt-field-id').val() || uid(),
				type: type,
				label: $r.find('.sclt-field-label').val() || '',
				required: $r.find('.sclt-field-required').is(':checked'),
				price_type: $r.find('.sclt-field-price-type').val() || 'none',
				price: parseFloat($r.find('.sclt-field-price').val()) || 0,
				choices: choices,
			};
			var def = ($r.find('.sclt-field-default').val() || '').trim();
			if (def) row.default_value = def;
			['min_chars', 'max_chars', 'min', 'max', 'step'].forEach(function (k) {
				var cls = '.sclt-field-' + k.replace('_', '-');
				if (k === 'min_chars') cls = '.sclt-field-min-chars';
				if (k === 'max_chars') cls = '.sclt-field-max-chars';
				var v = $r.find(cls).val();
				if (v !== '' && v != null) row[k] = parseFloat(v);
			});
			var roles = ($r.find('.sclt-field-roles').val() || '').trim();
			if (roles) row.roles_allowed = roles.split(/[\s,]+/).filter(Boolean);
			var vars = ($r.find('.sclt-field-variations').val() || '').trim();
			if (vars) row.variation_ids = vars.split(/[\s,]+/).map(function (x) { return parseInt(x, 10); }).filter(Boolean);
			if (showIfField) {
				row.show_if = { field: showIfField, value: showIfValue };
			}
			fields.push(row);
		});
		$('#sclt_options_fields_json').val(JSON.stringify(fields));
	}

	function esc(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	$(function () {
		if (!$('#sclt_product_data').length) {
			return;
		}

		renderFields();

		$('#sclt-add-field').on('click', function () {
			syncFields();
			var fields = parseJSON('#sclt_options_fields_json', []);
			fields.push({
				id: uid(),
				type: 'text',
				label: 'New field',
				required: false,
				price_type: 'none',
				price: 0,
				choices: [],
				show_if: null,
			});
			$('#sclt_options_fields_json').val(JSON.stringify(fields));
			renderFields();
		});

		$('#sclt-fields-list')
			.on('click', '.sclt-remove-field', function () {
				$(this).closest('.sclt-field-row').remove();
				syncFields();
			})
			.on('change', 'input,select', syncFields);

		// Before product save, force sync.
		$('#post').on('submit', function () {
			syncFields();
		});
	});
})(jQuery);
