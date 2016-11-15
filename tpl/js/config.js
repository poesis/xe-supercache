
jQuery(function() {
	
	var $ = jQuery;
	
	$("a.sc_enable_other_pages").on("click", function(event) {
		event.preventDefault();
		$("#sc_full_cache_type_other").removeAttr("disabled");
	});
	
	$("span.console_font").on("click", function() {
		$("#sc_core_object_cache").val($(this).text());
	});
	
	$("p.x_help-block").each(function() {
		var content = $.trim($(this).html());
		content = content.replace(/(경고|Warning): /, '<span class="warning_block">$1</span> ');
		content = content.replace(/(주의|Caution): /, '<span class="caution_block">$1</span> ');
		$(this).html(content);
	});
});
