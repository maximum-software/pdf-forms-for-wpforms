jQuery(document).ready(function($) {
	
	var cookies = [];
	try { cookies = decodeURIComponent(document.cookie).split('; '); }
	catch(e) { } // ignore cookie corruption related errors
	
	jQuery('.pdf-forms-for-wpforms-notice').each(function() {
		
		var notice_id = jQuery(this).data('notice-id');
		
		if(typeof notice_id === "undefined")
			return;
		
		var hidden = false;
		jQuery.each(cookies, function(key, value) {
			var kv = value.trim().split('=');
			if((kv[0] == "pdf-forms-for-wpforms-notice-"+notice_id) && (kv[1] == "hidden"))
			{
				hidden = true;
				return false;
			}
		});
		
		if(hidden)
			jQuery(this).hide();
	});
	
	jQuery('.pdf-forms-for-wpforms-notice').on("click", ".notice-dismiss", function(event) {
		
		var notice_id = jQuery(this).closest('.pdf-forms-for-wpforms-notice').data('notice-id');
		if(typeof notice_id == 'string')
		{
			var date = new Date();
			date.setDate(date.getDate() + 10);
			document.cookie = "pdf-forms-for-wpforms-notice-"+notice_id+"=hidden; expires="+date.toUTCString()+"; path=/; domain="+window.location.hostname+"; SameSite=Lax";
		}
	});
	
});
