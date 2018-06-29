$(function(){
	$('.votebox').click(function(){
		var votebox = $(this);
		var value = votebox.hasClass('voted') ? -1 : 1;
		var qid = votebox.attr('qid');
		$.post(getURL(),{ qid: qid, value: value },function(data){
			if (!isNumeric(data)) {
				alert(woops);
				window.location.reload();
			}
			var voteword = (data*1 === 1) ? ' vote' : ' votes';
			$('#vc_'+qid).html(data+voteword);
			if (value > 0) {
				votebox.removeClass('notvoted').addClass('voted');
			} else {
				votebox.removeClass('voted').addClass('notvoted');
			}
		});
	});
	
	$('#newquestion_submit').click(function(){
		var questiontext = trim($('#newquestion').val());
		if (questiontext == '') {
			$('#newquestion').focus();
			return;
		}
		$('#newquestion_form').submit();
	});
	
	if ($('.alert').length) {
		setTimeout(function(){
			$('.alert').hide('fade');
		},3000);
		modifyURL(getURL());
	}
	
	initRefreshPage();
});

function getURL() {
	return 'index.php?NOAUTH&pid='+getParameterByName('pid')+'&sid='+getParameterByName('sid')+'&prefix='+getParameterByName('prefix')+'&page='+getParameterByName('page');
}
	
// Refresh page periodically
function initRefreshPage() {
	setTimeout(function(){
		if (!($("#newquestion").is(':focus') || ($("#newquestion_submit").is(':focus') && trim($('#newquestion').val()) != ''))) {
			window.location.reload();
		} else {
			initRefreshPage();
		}
	},30000);
}