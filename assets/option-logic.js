/* StoreCanvas Lite: show/hide option fields by variation and show-if rules. Enqueued (was inline:
   block themes run product-form HTML through content filters that turned && into &#038;&#038;). */
(function(){
	function selectedVariation(){
		var el=document.querySelector('input[name="variation_id"], select[name="variation_id"]');
		return el ? parseInt(el.value||'0',10)||0 : 0;
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
			if(el.getAttribute('data-show-if-field')){
				var fid=el.getAttribute('data-show-if-field');
				var want=el.getAttribute('data-show-if-value')||'';
				var srcs=root.querySelectorAll('[name="sclt_option['+fid+']"], [name="sclt_option['+fid+'][]"]');
				var val='';
				if(srcs.length){
					var src=srcs[0];
					if(src.type==='checkbox' && srcs.length===1){ val=src.checked?'1':''; }
					else if(src.type==='radio'){
						srcs.forEach(function(s){ if(s.checked) val=s.value; });
					} else if(src.multiple){
						val=Array.prototype.map.call(src.selectedOptions||[],function(o){return o.value;}).join(',');
					} else { val=src.value||''; }
				}
				if(want && val!==want && (','+val+',').indexOf(','+want+',')<0) hide=true;
			}
			el.style.display = hide ? 'none' : '';
			el.setAttribute('data-sclt-visible', hide ? '0' : '1');
			var inputs=el.querySelectorAll('input,select,textarea');
			inputs.forEach(function(i){
				if(hide){ i.removeAttribute('required'); }
				else if(el.getAttribute('data-required')==='1'){ i.setAttribute('required','required'); }
			});
		});
	}
	document.addEventListener('change', function(e){
		if(e.target && (e.target.closest&&e.target.closest('.sclt-options') || e.target.name==='variation_id' || (e.target.name&&e.target.name.indexOf('attribute_')===0))) apply();
	});
	document.addEventListener('found_variation', apply);
	document.addEventListener('reset_data', apply);
	if(window.jQuery){
		jQuery(document.body).on('found_variation reset_data check_variations', apply);
	}
	apply();
})();
