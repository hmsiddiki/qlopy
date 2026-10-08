(function($){
  var originalParent = null;
  var formPlaceholder = null;
  $(document).on('click', '.comment-reply-link', function(e){
    e.preventDefault();
    var $this = $(this);
    var commentId = $this.data('comment-id');
    var $comment = $('#comment-' + commentId);
    var $form = $('#commentForm');
    var $parentInput = $('#comment_parent_id');
    if (!$form.length) return;
    if (!formPlaceholder) {
      formPlaceholder = $('<div id="comment-form-placeholder"></div>');
      $form.before(formPlaceholder);
    }
    // Move form under the comment
    $comment.after($form);
    $parentInput.val(commentId);
    // show cancel link
    if ($('#cancel-reply').length === 0) {
      var $cancel = $('<a href="#" id="cancel-reply" class="btn btn-sm btn-secondary" style="margin-left:8px;">Cancel reply</a>');
      $cancel.on('click', function(ev){ ev.preventDefault(); cancelReply(); });
      $form.find('button[type=submit]').after($cancel);
    }
    originalParent = formPlaceholder;
  });

  function cancelReply(){
    var $form = $('#commentForm');
    var $parentInput = $('#comment_parent_id');
    if (!formPlaceholder) return;
    formPlaceholder.before($form);
    $parentInput.val('');
    $('#cancel-reply').remove();
  }

  // expose cancel for possible external use
  window.qpCancelCommentReply = cancelReply;
})(jQuery);
