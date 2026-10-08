(function($){
  $(function(){
    var $sortable = $(".sortable");
    if (!$sortable.length) return;

    // Determine AJAX URL: prefer WP-style `window.ajaxurl`, fall back to relative `ajax.php`
    var ajaxUrl = (typeof window !== 'undefined' && window.ajaxurl) ? window.ajaxurl : 'ajax.php';

    // If the table has data-autosave="1", we'll POST the order automatically after reorder.
    var autoSave = !!$sortable.data('autosave');
    var $form = $sortable.closest('form');
    var taxonomy = $form.find('input[name="taxonomy"]').val() || '';

    $sortable.sortable({
      placeholder: "ui-state-highlight",
      helper: function(e, tr) {
        // qp-sortable may pass a raw DOM node; ensure we operate on a jQuery object
        var $tr = (tr && tr.jquery) ? tr : $(tr);
        var $originals = $tr.children();
        var $helper = $tr.clone();
        $helper.children().each(function(index) {
          $(this).width($originals.eq(index).width());
        });
        return $helper;
      },
      update: function() {
        // update the numeric order inputs
        $sortable.find('tr').each(function(index) {
          $(this).find('input[name^="order"]').val(index);
        });

        if (!autoSave) return;

        // build POST data in the same shape PHP expects: order[ID]=value
        var data = { action: 'update_term_order', taxonomy: taxonomy };
        $sortable.find('tr').each(function(index){
          var termId = $(this).data('term-id');
          if (!termId) return;
          var val = $(this).find('input[name^="order"]').val();
          if (val === undefined || val === null || val === '') val = index;
          data['order[' + termId + ']'] = val;
        });

        // Post to ajaxUrl; expect JSON response
        try {
          $.post(ajaxUrl, data, function(resp){
            if (resp && resp.status === 'success') {
              // small visual feedback: console and brief flash on form
              try { console.info('Term order auto-saved'); $form.find('.autosave-feedback').remove(); $form.prepend('<div class="autosave-feedback alert alert-success">Order saved</div>'); setTimeout(function(){ $form.find('.autosave-feedback').fadeOut(400, function(){ $(this).remove(); }); }, 900); } catch(e) {}
            } else {
              try { console.warn('Auto-save failed', resp); } catch(e) {}
            }
          }, 'json').fail(function(){ console.warn('Auto-save request failed'); });
        } catch(e) { console.error('Auto-save error', e); }
      }
    }).disableSelection();
  });
})(jQuery);
