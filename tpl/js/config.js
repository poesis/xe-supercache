
jQuery(function() {
	
	var $ = jQuery;
	
	$("a.sc_enable_other_pages").on("click", function(event) {
		event.preventDefault();
		$("#sc_full_cache_type_other").removeAttr("disabled");
	});
	
	$("span.console_font").on("click", function() {
		$("#sc_core_object_cache").val($(this).text());
	});
	
});
