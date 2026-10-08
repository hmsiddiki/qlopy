(function($){
  var ajaxUrl = window.ajaxurl || 'ajax.php';

  function rebuildFromServer($field){
    var taxonomy = $field.data('taxonomy');
    var objectId = $field.data('object-id');
    var $chips = $field.find('.qpmeta-tags-chips');
    $.post(ajaxUrl, {action:'qpmeta_tag_get', post_id: objectId, taxonomy: taxonomy}, function(resp){
      if(resp && resp.status==='success' && Array.isArray(resp.tags)){
        $chips.empty();
        var $hiddenWrap = $field.find('.qpmeta-tags-hidden');
        if(!$hiddenWrap.length){ $hiddenWrap = $('<div class="qpmeta-tags-hidden"></div>').appendTo($field); }
        $hiddenWrap.empty();
        resp.tags.forEach(function(tag){
          var chip = $('<span class="qpmeta-tag-chip" data-tag-id="'+tag.id+'">'+tag.term+' <span class="qpmeta-tag-remove">&times;</span></span> ');
          $chips.append(chip);
          $hiddenWrap.append('<input type="hidden" class="qpmeta-tag-hidden-input" name="terms['+taxonomy+'][]" value="'+tag.id+'">');
        });
      }
    }, 'json');
  }

  function saveFieldTags($field){
    var taxonomy = $field.data('taxonomy');
    var objectId = $field.data('object-id');
    var $chips = $field.find('.qpmeta-tags-chips');
    var tagIds = $chips.find('.qpmeta-tag-chip').map(function(){ return $(this).attr('data-tag-id'); }).get();
    $.post(ajaxUrl, {action:'qpmeta_tag_set', post_id: objectId, taxonomy: taxonomy, tag_ids: tagIds}, function(resp){
      if(!resp || resp.status!=='success'){ console.warn('Tag set failed', resp); return; }
      rebuildFromServer($field);
    }, 'json');
  }

  function addTagChip($field, tagId, tagText){
    var $chips = $field.find('.qpmeta-tags-chips');
    if ($chips.find('[data-tag-id="'+tagId+'"]').length) return;
    var chip = $('<span class="qpmeta-tag-chip" data-tag-id="'+tagId+'">'+tagText+' <span class="qpmeta-tag-remove">&times;</span></span> ');
    $chips.append(chip);
    var taxonomy = $field.data('taxonomy');
    var $hiddenWrap = $field.find('.qpmeta-tags-hidden');
    if(!$hiddenWrap.length){ $hiddenWrap = $('<div class="qpmeta-tags-hidden"></div>').appendTo($field); }
    $hiddenWrap.append('<input type="hidden" class="qpmeta-tag-hidden-input" name="terms['+taxonomy+'][]" value="'+tagId+'">');
  }

  // Global remove handler (works even if input not focused)
  $(document).on('click', '.qpmeta-tags-field .qpmeta-tag-remove', function(e){
    e.preventDefault();
    var $chip = $(this).closest('.qpmeta-tag-chip');
    var $field = $chip.closest('.qpmeta-tags-field');
    var tagId = $chip.attr('data-tag-id');
    $chip.remove();
    $field.find('.qpmeta-tag-hidden-input').filter(function(){ return $(this).val() === tagId; }).remove();
    saveFieldTags($field);
  });

  // Focus handler sets up suggestions and add behavior
  $(document).on('focus', '.qpmeta-tags-input', function(){
    var $input = $(this);
    var $field = $input.closest('.qpmeta-tags-field');
    var taxonomy = $field.data('taxonomy');
    var $dropdown = $field.find('.qpmeta-tags-dropdown');
    if(!$dropdown.length){
      $dropdown = $('<div class="qpmeta-tags-dropdown"></div>').css({position:'absolute',zIndex:1000,background:'#fff',border:'1px solid #ccc',minWidth:'180px'}).hide();
      $field.append($dropdown);
    }

    $input.off('input.qpmetaTags').on('input.qpmetaTags', function(){
      var q = $input.val();
      if (!q) { $dropdown.hide(); return; }
      $.post(ajaxUrl, {action:'qpmeta_tag_search', taxonomy: taxonomy, q: q}, function(resp){
        $dropdown.empty();
        if (resp && resp.tags){
          resp.tags.forEach(function(tag){
            var item = $('<div>').addClass('qpmeta-tags-suggestion').text(tag.term).attr('data-tag-id', tag.id);
            $dropdown.append(item);
          });
        }
        $dropdown.show();
      }, 'json');
    });

    $dropdown.off('mousedown.qpmetaTags').on('mousedown.qpmetaTags', '.qpmeta-tags-suggestion', function(e){
      e.preventDefault();
      var tagId = $(this).attr('data-tag-id');
      var tagText = $(this).text();
      addTagChip($field, tagId, tagText);
      $dropdown.hide();
      $input.val('');
      saveFieldTags($field);
    });

    $input.off('keydown.qpmetaTags').on('keydown.qpmetaTags', function(e){
      if (e.key === 'Enter' && $input.val()){
        var term = $input.val();
        $.post(ajaxUrl, {action:'qpmeta_tag_add', taxonomy: taxonomy, term: term}, function(resp){
          if (resp && resp.id){
            addTagChip($field, resp.id, term);
            saveFieldTags($field);
            $input.val('');
            $dropdown.hide();
          } else {
            console.warn('Tag add failed', resp);
          }
        }, 'json');
        e.preventDefault();
      }
    });
  });
})(jQuery);
