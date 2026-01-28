(function($){
  function getExt(filename){
    if(!filename){return '';}
    var idx = filename.lastIndexOf('.');
    if(idx === -1){return '';}
    return filename.substring(idx+1).toLowerCase();
  }

  function showMessage($input, type, text){
    // Prefer the dedicated message container if present (file fields).
    var $field = $input.closest('.ct-forms-field');
    var $msg = $field.find('.ct-forms-field-message').first();
    if(!$msg.length){
      $msg = $field.find('.ct-forms-field-error').first();
    }
    if(!$msg.length){
      $msg = $('<div class="ct-forms-field-message" aria-live="polite"></div>').appendTo($field);
    }
    $msg.removeClass('ct-forms-field-message--error ct-forms-field-message--warning');
    if(type === 'error'){
      $msg.addClass('ct-forms-field-message--error');
    } else if(type === 'warning'){
      $msg.addClass('ct-forms-field-message--warning');
    }
    $msg.text(text);
  }

  function clearMessage($input){
    var $field = $input.closest('.ct-forms-field');
    var $msg = $field.find('.ct-forms-field-message').first();
    if($msg.length){
      $msg.text('').removeClass('ct-forms-field-message--error ct-forms-field-message--warning');
    }
  }

  $(document).on('change', '.ct-forms-field input[type=file]', function(){
    var $input = $(this);
    clearMessage($input);

    var allowed = ($input.data('truitt-allowed') || '').toString().toLowerCase();
    if(!allowed){
      return;
    }
    var allowedSet = {};
    allowed.split(',').map(function(x){return x.trim();}).filter(Boolean).forEach(function(ext){allowedSet[ext] = true;});

    var files = this.files ? Array.prototype.slice.call(this.files) : [];
    if(!files.length){
      return;
    }

    var bad = [];
    files.forEach(function(f){
      var ext = getExt(f.name);
      if(ext && !allowedSet[ext]){
        bad.push(f.name);
      }
    });

    if(bad.length){
      // Browser FileList is immutable; safest is to clear the field and ask again.
      $input.val('');
      showMessage($input, 'error', 'Invalid file type selected. Allowed: ' + allowed.split(',').map(function(x){return x.trim();}).filter(Boolean).join(', ') + '.');
      return;
    }

    // Multi-file UX: if this file field allows multiple files, add a new
    // empty file input once the current (last) input has a selection.
    var $multiWrap = $input.closest('.ct-forms-file-multi');
    if($multiWrap.length && $multiWrap.data('ct-forms-multi')){
      var $inputs = $multiWrap.find('input[type=file]');
      var isLast = $inputs.last()[0] === $input[0];
      if(isLast){
        // Cap to prevent uncontrolled growth.
        if($inputs.length >= 10){
          return;
        }
        // Clone the input but ensure it is empty and not required.
        var $clone = $input.clone(false);
        $clone.val('');
        $clone.removeAttr('id');
        $clone.prop('required', false);
        $clone.attr('aria-required', 'false');
        $multiWrap.append($clone);
      }
    }
  });
})(jQuery);
