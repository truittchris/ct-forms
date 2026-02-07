/* File: assets/frontend.js */
(function($){
	'use strict';

	function submitForm($form){
		if (typeof ctFrontend === 'undefined' || !ctFrontend.ajaxurl) {
			// No AJAX config – fall back to normal submit.
			return;
		}

		var $wrap = $form.closest('.ct-form-wrapper');
		var $btn  = $form.find('button[type="submit"], input[type="submit"]').first();
		var origText = $btn.is('button') ? $btn.text() : $btn.val();

		if ($btn.length) {
			if ($btn.is('button')) $btn.text('Sending...');
			else $btn.val('Sending...');
			$btn.prop('disabled', true);
		}

		$.post(ctFrontend.ajaxurl, $form.serialize())
			.done(function(res){
				if (res && res.success) {
					$form.slideUp(200);
					$wrap.find('.ct-success-msg').html(res.data || '').fadeIn(200);
					return;
				}

				// Show server-provided error if present.
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Submission failed.';
				window.alert(msg);
			})
			.fail(function(xhr){
				var msg = 'Submission failed.';
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				window.alert(msg);
			})
			.always(function(){
				if ($btn.length) {
					if ($btn.is('button')) $btn.text(origText);
					else $btn.val(origText);
					$btn.prop('disabled', false);
				}
			});
	}

	$(document).on('submit', 'form.ct-live-form', function(e){
		e.preventDefault();
		submitForm($(this));
	});

})(jQuery);
