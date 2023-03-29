jQuery(document).ready(function($) {
	jQuery(".copy-pdf-ninja-key-btn").click(function(event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		var tmp = jQuery("<input>");
		jQuery("body").append(tmp);
		tmp.val(jQuery("input#wpforms-setting-pdf-ninja-api_key").val()).select();
		document.execCommand("copy");
		tmp.remove();
		var btn = this;
		setTimeout(function() { jQuery(btn).text(pdf_forms_for_wpforms_copy_key.__key_copied_btn_label); }, 1);
		setTimeout(function() { jQuery(btn).text(pdf_forms_for_wpforms_copy_key.__key_copy_btn_label); }, 1000);
		
		return false;
	});
});
