
jQuery(function() {
	
	var $ = jQuery;
	
	$("span.console_font").on("click", function() {
		$("#sc_core_object_cache").val($(this).text());
	});
	
});
