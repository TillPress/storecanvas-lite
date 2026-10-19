/* StoreCanvas Lite: show/hide option fields by variation, show-if and AND/OR rules, plus
   a quick size/type check on file fields. Enqueued (was inline: block themes run
   product-form HTML through content filters that turned && into &#038;&#038;). */
(function(){
	function selectedVariation(){
		var el=document.querySelector('input[name="variation_id"], select[name="variation_id"]');
		return el ? parseInt(el.value||'0',10)||0 : 0;
	}
	/* Submitted value(s) of a field as a list of strings, matching what the server sees:
	   nothing chosen is [''] (an empty scalar), multi-value fields give every choice. */
	function fieldValues(root, fid){
		var srcs=root.querySelectorAll('[name="sclt_option['+fid+']"], [name="sclt_option['+fid+'][]"]');
		var out=[];
		Array.prototype.forEach.call(srcs,function(src){
			if(src.type==='checkbox' || src.type==='radio'){ if(src.checked) out.push(src.value); }
			else if(src.type==='file'){ if(src.files && src.files.length) out.push(src.files[0].name); }
			else if(src.multiple){ Array.prototype.forEach.call(src.selectedOptions||[],function(o){ out.push(o.value); }); }
			else if(src.value!==''){ out.push(src.value); }
		});
		return out.length ? out : [''];
	}
	function isNum(s){ return /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(s); }
	/* Mirrors SCLT_Product_Options::rule_matches(). */
	function ruleMatches(op, want, got){
		want = want==null ? '' : String(want);
		var str = got.join(',');
		var num = isNum(str) && isNum(want);
		switch(op){
			case 'is': return got.indexOf(want)>=0;
			case 'is_not': return got.indexOf(want)<0;
			case 'contains': return want!=='' && str.indexOf(want)>=0;
			case 'not_contains': return want==='' || str.indexOf(want)<0;
			case 'gt': return num && parseFloat(str)>parseFloat(want);
			case 'gte': return num && parseFloat(str)>=parseFloat(want);
			case 'lt': return num && parseFloat(str)<parseFloat(want);
			case 'lte': return num && parseFloat(str)<=parseFloat(want);
			case 'empty': return str==='';
			case 'not_empty': return str!=='';
			case 'in':
				var list=want.split(',').map(function(x){ return x.trim(); });
				return got.some(function(g){ return list.indexOf(g)>=0; });
		}
		return false;
	}
	/* Mirrors SCLT_Product_Options::evaluate_conditions(). */
	function conditionsMatch(root, cond){
		var rules=(cond && cond.rules) || [];
		var results=[];
		rules.forEach(function(r){
			if(!r || !r.field) return;
			results.push(ruleMatches(r.op||'is', r.value, fieldValues(root, r.field)));
		});
		if(!results.length) return true;
		return cond.logic==='or' ? results.indexOf(true)>=0 : results.indexOf(false)<0;
	}
	function apply(){
		var root=document.querySelector('.sclt-options');
		if(!root) return;
		var vid=selectedVariation();
		root.querySelectorAll('.sclt-option-field').forEach(function(el){
			var hide=false;
			var vids=(el.getAttribute('data-variation-ids')||'').split(',').filter(Boolean).map(function(x){return parseInt(x,10);});
			if(vids.length){
				if(!vid || vids.indexOf(vid)<0) hide=true;
			}
			var condAttr=el.getAttribute('data-sclt-conditions');
			if(condAttr){
				var cond=null;
				try{ cond=JSON.parse(condAttr); }catch(e){ cond=null; }
				if(cond && !conditionsMatch(root, cond)) hide=true;
			} else if(el.getAttribute('data-show-if-field')){
				var want=el.getAttribute('data-show-if-value')||'';
				var got=fieldValues(root, el.getAttribute('data-show-if-field'));
				if(want && got.indexOf(want)<0) hide=true;
			}
			el.style.display = hide ? 'none' : '';
			el.setAttribute('data-sclt-visible', hide ? '0' : '1');
			var inputs=el.querySelectorAll('input,select,textarea');
			inputs.forEach(function(i){
				if(hide){ i.removeAttribute('required'); }
				else if(el.getAttribute('data-required')==='1' && !(i.type==='checkbox' && /\[\]$/.test(i.name||''))){ i.setAttribute('required','required'); }
			});
		});
	}
	/* Tell the customer straight away when a file is too big or the wrong type. */
	function checkFile(input){
		if(!input || input.type!=='file' || !input.setCustomValidity) return;
		var msg='';
		var f=input.files && input.files[0];
		if(f){
			var max=parseInt(input.getAttribute('data-max-bytes')||'0',10);
			var accept=(input.getAttribute('accept')||'').toLowerCase().split(',').filter(Boolean);
			var dot=f.name.lastIndexOf('.');
			var ext=dot>=0 ? f.name.slice(dot).toLowerCase() : '';
			var hint=input.parentNode && input.parentNode.querySelector('.sclt-file-hint');
			if((max && f.size>max) || (accept.length && accept.indexOf(ext)<0)){
				msg=hint ? hint.textContent : 'File not allowed.';
			}
		}
		input.setCustomValidity(msg);
		if(msg && input.reportValidity) input.reportValidity();
	}
	document.addEventListener('change', function(e){
		var t=e.target;
		if(t && t.type==='file' && t.closest && t.closest('.sclt-options')) checkFile(t);
		if(t && (t.closest&&t.closest('.sclt-options') || t.name==='variation_id' || (t.name&&t.name.indexOf('attribute_')===0))) apply();
	});
	document.addEventListener('input', function(e){
		var t=e.target;
		if(t && t.closest && t.closest('.sclt-options') && (t.type==='text' || t.type==='number' || t.tagName==='TEXTAREA' || t.type==='email' || t.type==='tel')) apply();
	});
	document.addEventListener('found_variation', apply);
	document.addEventListener('reset_data', apply);
	if(window.jQuery){
		jQuery(document.body).on('found_variation reset_data check_variations', apply);
	}
	apply();
})();
