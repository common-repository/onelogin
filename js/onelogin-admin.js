(function($){
	$(document).ready(function(){
		var field;

		$('.onelogin-media').click(function(){
			field = $(this).parent('td').find('.onelogin-media-field');
			tb_show('', 'media-upload.php?type=image&post_ID=0&TB_iframe=true');

			return false;
		})

		window.original_send_to_editor = window.send_to_editor;

		window.send_to_editor = function(html) {
			if (field) {
				$(field).val($('img', html).attr('src'));
				tb_remove();
				field = false;
			} else {
				window.original_send_to_editor(html);
			}
		};
	})
})(jQuery)