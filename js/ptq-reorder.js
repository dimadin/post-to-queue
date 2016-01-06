jQuery(document).ready(function($) {	
	// Get list of posts
	var postList = $('#ptq-reorder-post-list');

	// Make list of posts sortable
	postList.sortable({
		// Attach function executed when order is changed
		update: function(event, ui) {
			// Show the animate loading gif while waiting
			$('#ptq-reorder-loading').show();

			// Get new post order as JSON array of post IDs
			var order = postList.sortable('toArray', { attribute: 'data-postid' });
			order = JSON.stringify( order );

			// Set options passed to AJAX function
			opts = {
				url: ajaxurl,
				type: 'POST',
				async: true,
				cache: false,
				dataType: 'json',
				data:{
					action: 'ptq-save-reorder',
					order: order, // Passes IDs of list items in [ 1,3,2 ] format
					nonce: ptqReorder.nonce,
				},
				success: function(response) {
					// Hide the loading animation
					$('#ptq-reorder-loading').hide();

					// Check if it's expected response
					if ( typeof response.success == 'undefined' ) {
						$class = 'error';
						$msg = ptqReorder.errorMsg;
					} else {
						// Set class and message according to response success
						if ( response.success ) {
							$class = 'update-nag';
							if ( typeof response.data.msg !== 'undefined') {
								$msg = response.data.msg;
							}
						} else {
							$class = 'error';
							$msg = ptqReorder.errorMsg;
						}
					}

					// Create result message that is displayed
					$txt = '<div class="'+$class+'" id="ptq-reorder-txt">'+$msg+'</div>';

					// Display result message and fade it away
					$('#ptq-reorder-result').html($txt);
					$('#ptq-reorder-txt').fadeOut(5000, function() {
						$(this).remove();
					});

					return;
				},
				error: function(xhr,textStatus,e) {
					// Hide the loading animation
					$('#ptq-reorder-loading').hide();

					// Create result message that is displayed
					$txt = '<div class="error" id="ptq-reorder-txt">'+ptqReorder.errorMsg+'</div>';

					// Display result message and fade it away
					$('#ptq-reorder-result').html($txt);
					$('#ptq-reorder-txt').fadeOut(5000, function() {
						$( this ).remove();
					});

					return;
				}
			};

			// Post options using AJAX
			$.ajax(opts);
		}
	});
});