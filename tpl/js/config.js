
jQuery(function() {
	
	var $ = jQuery;
	
	$("p.x_help-block").on("click", "a.sc_enable_other_pages", function(event) {
		event.preventDefault();
		$("#sc_full_cache_type_other").removeAttr("disabled");
	});
	
	$("span.console_font").on("click", function() {
		if ($(this).siblings("span.is_disabled").size()) {
			alert($(this).parents("ul").data("is-disabled"));
		} else {
			$("#sc_core_object_cache").val($(this).text());
		}
	});
	
	$("p.x_help-block").each(function() {
		var content = $.trim($(this).html());
		content = content.replace(/(경고|Warning): /, '<span class="warning_block">$1</span> ');
		content = content.replace(/(주의|Caution): /, '<span class="caution_block">$1</span> ');
		$(this).html(content);
	});

	$("#sc_flush_cache").on("click", function(event) {
		event.preventDefault();
		var success_msg = $(this).data("success");
		var error_msg = $(this).data("error");
		var fast_msg = $(this).data("fast");
		var start_time = new Date().getTime();
		exec_json("supercache.procSupercacheAdminFlushCache", {}, function(response) {
			if (response.flushed) {
				if (new Date().getTime() < start_time + 2000) {
					alert(success_msg + " " + fast_msg);
				} else {
					alert(success_msg);
				}
			} else {
				alert(error_msg);
			}
		}, function(response) {
			alert(error_msg);
		});
	});

});
