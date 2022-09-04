jQuery(document).ready(function($) {
	
	var errorMessage = function(msg)
	{
		if(!msg)
			msg = pdf_forms_for_wpforms.__Unknown_error;
		jQuery('.wpforms-admin-settings-pdf-ninja #pdf-forms-for-wpforms-messages').append(
			jQuery('<div class="error"/>').text(msg)
		);
	};
	
	jQuery(".wpforms-admin-settings-pdf-ninja #pdf-forms-wpforms-generate-key-btn").on("click", function (event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		jQuery('.wpforms-admin-settings-pdf-ninja #pdf-forms-for-wpforms-messages').empty();
		
		jQuery.ajax({
			url: pdf_forms_for_wpforms.ajax_url,
			type: 'POST',
			data: { 'action': 'pdf_forms_for_wpforms_generate_pdf_ninja_key', 'nonce': pdf_forms_for_wpforms.ajax_nonce },
			cache: false,
			dataType: 'json',
			
			success: function (data, textStatus, jqXHR) {
				if (!data.success)
					return errorMessage(data.error_message);
				
				location.reload(true);
			},
			
			error: function (jqXHR, textStatus, errorThrown) {
				return errorMessage(textStatus);
			},
		
			beforeSend: function() { PdfFormsFillerSpinner.show(); },
			complete: function() { PdfFormsFillerSpinner.hide(); }
		});
	})
});
