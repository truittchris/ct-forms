(function($){
  function getCollapseState(){
    const state = {};
    $('#ct-forms-builder .ct-forms-field-row').each(function(){
      const $row = $(this);
      const id = $row.attr('data-id') || '';
      if (!id) return;
      state[id] = $row.hasClass('is-collapsed') ? 1 : 0;
    });
    return state;
  }

  function parseDef(){
    const raw = $('#ct_form_definition').val();
    try { return JSON.parse(raw); } catch(e){ return {version:1, fields:[]}; }
  }
  function saveDef(def){
    $('#ct_form_definition').val(JSON.stringify(def));
  }
  function friendlyType(t){
    return String(t||'').replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  
  // Track unsaved changes on the edit form page.
  let ctFormsIsDirty = false;
  let ctFormsSuppressDirty = false;

  function ctFormsMarkDirty(){
    if (ctFormsSuppressDirty) return;
    ctFormsIsDirty = true;
    window.ctFormsIsDirty = true;
  }
  function ctFormsClearDirty(){
    ctFormsIsDirty = false;
    window.ctFormsIsDirty = false;
  }

  function ctFormsSetupDirtyGuards(){
    // Mark dirty on any user input within builder/settings.
    $(document).on('input change', '#ct-forms-builder input, #ct-forms-builder select, #ct-forms-builder textarea, #ct-forms-settings input, #ct-forms-settings select, #ct-forms-settings textarea', function(){
      ctFormsMarkDirty();
    });

    // Warn on navigation away.
    window.addEventListener('beforeunload', function(e){
      if (!ctFormsIsDirty) return;
      e.preventDefault();
      e.returnValue = '';
      return '';
    });

    // Intercept in-page navigation links (including WP admin menu clicks).
    $(document).on('click', 'a', function(e){
      if (!ctFormsIsDirty) return;
      const $a = $(this);
      if ($a.attr('target') === '_blank') return;
      const href = $a.attr('href') || '';
      if (!href || href.charAt(0) === '#') return;
      // Allow links that submit forms via JS etc.
      if ($a.hasClass('ct-forms-no-guard')) return;

      if (!window.confirm('You have unsaved changes. Leave this page without saving?')) {
        e.preventDefault();
        e.stopPropagation();
      } else {
        ctFormsClearDirty();
      }
    });

    // Clear dirty on form submit.
    $(document).on('submit', 'form', function(){
      ctFormsClearDirty();
    });
  }
function fieldTemplate(field, collapsed){
    const typeDefs = [
      {value:'text', label:'Text'},
      {value:'textarea', label:'Textarea'},
      {value:'email', label:'Email'},
      {value:'number', label:'Number'},
      {value:'date', label:'Date'},
      {value:'time', label:'Time'},
      {value:'select', label:'Select'},
      {value:'state', label:'State (US)'},
      {value:'checkboxes', label:'Checkboxes'},
      {value:'radios', label:'Radios'},
      {value:'file', label:'File upload'},
      {value:'diagnostics', label:'Diagnostics (internal)'}
    ];
    const typeOptions = typeDefs.map(t => `<option value="${t.value}" ${field.type===t.value?'selected':''}>${t.label}</option>`).join('');
    const requiredChecked = field.required ? 'checked' : '';
    const globalAllowed = (window.CTFormsAdmin && CTFormsAdmin.allowed_mimes) ? String(CTFormsAdmin.allowed_mimes).trim() : '';
    const globalMaxMb = (window.CTFormsAdmin && CTFormsAdmin.max_file_mb) ? parseInt(CTFormsAdmin.max_file_mb, 10) : 0;

    const optionsArea = (field.type==='diagnostics') ? `
      <div class="ct-forms-inline">
        <div>
          <label>Diagnostics</label>
          <div class="ct-forms-readonly">This field is auto–populated at submit time with site and environment details (WordPress, PHP, CT Forms version, theme, and active plugins). It is not shown to the visitor.</div>
        </div>
      </div>
    ` : (field.type==='file') ? `
      <div class="ct-forms-inline">
        <div>
          <label>Allowed file types</label>
          <div class="ct-forms-readonly">${globalAllowed ? escapeHtml(globalAllowed) : '(not set)'} <span class="description">(controlled in CT Forms n– Settings)</span></div>
        </div>
        <div>
          <label>Max file size (MB)</label>
          <input type="number" min="1" step="1" class="widefat truitt-file-maxmb" value="${field.file_max_mb||''}" placeholder="${globalMaxMb ? globalMaxMb : ''}">
          <p class="description" style="margin:4px 0 0;">Leave blank to use the global limit.</p>
        </div>
      </div>
      <div style="margin-top:8px;">
        <label>
          <input type="checkbox" class="truitt-file-multiple" ${field.file_multiple ? 'checked' : ''}>
          Allow multiple files
        </label>
      </div>
    ` : (field.type==='state') ? `
      <div class="ct-forms-inline">
        <div>
          <label>US State dropdown</label>
          <div class="ct-forms-readonly">This field renders a pre–populated dropdown of U.S. states and DC. No options are needed.</div>
        </div>
      </div>
    ` : (field.type==='select' || field.type==='checkboxes' || field.type==='radios') ? `
      <div class="ct-forms-options-editor">
        <label>Options</label>
        <div class="ct-forms-options-grid">
          <div class="ct-forms-options-head">Value</div>
          <div class="ct-forms-options-head">Label</div>
          <div class="ct-forms-options-head"></div>
          ${(field.options||[]).length ? (field.options||[]).map(o => `
            <input type="text" class="widefat truitt-opt-value" value="${escapeHtml(o.value||'')}">
            <input type="text" class="widefat truitt-opt-label" value="${escapeHtml(o.label||o.value||'')}">
            <button type="button" class="button link-button truitt-opt-del" title="Remove option">Remove</button>
          `).join('') : `
            <input type="text" class="widefat truitt-opt-value" value="">
            <input type="text" class="widefat truitt-opt-label" value="">
            <button type="button" class="button link-button truitt-opt-del" title="Remove option">Remove</button>
          `}
        </div>
        <div style="margin-top:8px;">
          <button type="button" class="button truitt-opt-add">Add option</button>
        </div>
        <p class="description" style="margin:6px 0 0;">Leave Label blank to use the Value.</p>
      </div>
    ` : ``;
    const isCollapsed = collapsed ? 'is-collapsed' : '';
    const ariaExpanded = collapsed ? 'false' : 'true';
    const labelText = (field.label||'').trim() ? (field.label||'').trim() : field.id;
    const typeBadge = friendlyType(field.type);
return `
      <div class="ct-forms-field-row ${isCollapsed}" data-id="${field.id}">
        <div class="ct-forms-field-header">
          <div class="handle" title="Drag to reorder" aria-hidden="true">≡</div>
          <button type="button" class="truitt-field-toggle" aria-expanded="${ariaExpanded}" title="Toggle advanced options">▾</button>
          <button type="button" class="ct-forms-move-up" title="Move up" aria-label="Move field up">↑</button>
          <button type="button" class="ct-forms-move-down" title="Move down" aria-label="Move field down">↓</button>

          <div class="truitt-field-header-main">
            <div class="truitt-field-header-title">
              <strong>${escapeHtml(labelText)}</strong>
              <span class="truitt-field-typemeta">${escapeHtml(typeBadge)}</span>
              ${field.required ? '<span class="truitt-field-required">Required</span>' : ''}
              <span class="truitt-field-idpill">ID: ${escapeHtml(field.id)}</span>
            </div>
          </div>

          <div class="actions">
            <button type="button" class="button truitt-dup">Duplicate</button>
            <button type="button" class="button truitt-del">Delete</button>
          </div>
        </div>

        <div class="ct-forms-field-body"${collapsed ? ' style="display:none"' : ''}>
          <div class="ct-forms-advanced-grid">
            <div>
              <label>Field id (no spaces)</label>
              <input type="text" class="widefat truitt-id" value="${escapeHtml(field.id)}">
            </div>
            <div>
              <label>Type</label>
              <select class="widefat truitt-type">${typeOptions}</select>
            </div>
          </div>

          <div class="ct-forms-advanced-grid" style="margin-top:10px;">
            <div>
              <label>Label</label>
              <input type="text" class="widefat truitt-label" value="${escapeHtml(field.label||'')}">
            </div>
            <div style="display:flex;align-items:flex-end;gap:12px;">
              <label style="margin:0;"><input type="checkbox" class="truitt-required" ${requiredChecked}> Required</label>
            </div>
          </div>

          <div class="ct-forms-advanced-grid" style="margin-top:10px;">
            <div>
              <label>Placeholder</label>
              <input type="text" class="widefat truitt-placeholder" value="${escapeHtml(field.placeholder||'')}">
            </div>
            <div>
              <label>Help text</label>
              <input type="text" class="widefat truitt-help" value="${escapeHtml(field.help||'')}">
            </div>
          </div>

          <div style="margin-top:12px;">${optionsArea}</div>
        </div>
      </div>
    `;
  }

  function escapeHtml(str){
    return String(str||'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function rebuild(preserveCollapse){
    ctFormsSuppressDirty = true;

    const def = parseDef();
    const $wrap = $('#ct-forms-builder');
    const collapseState = preserveCollapse ? getCollapseState() : {};
    $wrap.empty();
    (def.fields||[]).forEach((f, idx) => {
      const collapsed = Object.prototype.hasOwnProperty.call(collapseState, f.id)
        ? !!collapseState[f.id]
        : (idx === 0 ? false : true);
      $wrap.append(fieldTemplate(f, collapsed));
    });

    // Apply initial collapsed state.
    $wrap.find('.ct-forms-field-row').each(function(){
      const $row = $(this);
      const $body = $row.find('.ct-forms-field-body');
      const collapsed = $row.hasClass('is-collapsed') || $body.is(':hidden');
      if (collapsed) {
        $body.hide();
        $row.find('.truitt-field-toggle').attr('aria-expanded','false');
      } else {
        $body.show();
        $row.find('.truitt-field-toggle').attr('aria-expanded','true');
      }
    });

    $wrap.sortable({
      handle: '.handle',
      update: function(){ sync(); }
    });
  }

  function sync(){
    const def = parseDef();
    const fields = [];
    $('#ct-forms-builder .ct-forms-field-row').each(function(){
      const $row = $(this);
      const id = ($row.find('.truitt-id').val()||'').trim().toLowerCase().replace(/[^a-z0-9_\-]/g,'_');
      const type = $row.find('.truitt-type').val();
      const label = $row.find('.truitt-label').val()||id;
      const required = $row.find('.truitt-required').is(':checked');
      const help = $row.find('.truitt-help').val()||'';
      const placeholder = $row.find('.truitt-placeholder').val()||'';
      let options = [];
      if(type==='select' || type==='checkboxes' || type==='radios'){
        options = [];
        const $vals = $row.find('.truitt-opt-value');
        const $labs = $row.find('.truitt-opt-label');
        $vals.each(function(i){
          const v = (this.value||'').trim();
          if(!v) return;
          const labEl = $labs.get(i);
          const l = (labEl && labEl.value ? labEl.value : v).trim();
          options.push({value:v, label:(l || v)});
        });
      }
      const fieldObj = {id, type, label, required, help, placeholder, options};
      if (type === 'file') {
        const el = $row.get(0);
        const maxEl = el ? el.querySelector('.truitt-file-maxmb') : null;
        const multiEl = el ? el.querySelector('.truitt-file-multiple') : null;
        fieldObj.file_max_mb = maxEl ? (maxEl.value || '').trim() : '';
        fieldObj.file_multiple = multiEl && multiEl.checked ? 1 : 0;
      }
      fields.push(fieldObj);
    });
    def.version = 1;
    def.fields = fields;
    saveDef(def);
    ctFormsMarkDirty();
    var $settingsDef = $('#ct_form_definition_settings');
    if ($settingsDef.length) { $settingsDef.val($('#ct_form_definition').val()); }
  }

  function newField(){
    const def = parseDef();
    def.fields = def.fields || [];
    let base = 'field';
    let idx = def.fields.length + 1;
    let id = base + '_' + idx;
    while(def.fields.find(f => f.id===id)){ idx++; id = base + '_' + idx; }
    def.fields.push({id, type:'text', label:'New field', required:false, placeholder:'', help:'', options:[]});
    saveDef(def);
    ctFormsMarkDirty();
    rebuild();
      // Auto-expand the newly-added field
    setTimeout(function(){
      const $row = $('#ct-forms-builder .ct-forms-field-row[data-id=\"'+id+'\"]');
      if ($row.length){
        $row.removeClass('is-collapsed');
        $row.find('.truitt-field-toggle').attr('aria-expanded','true');
        $row.find('.ct-forms-field-body').show();
        $('html, body').animate({ scrollTop: $row.offset().top - 120 }, 200);
      }
    }, 0);
  }

  $(document).on('change keyup', '#ct-forms-builder input, #ct-forms-builder select, #ct-forms-builder textarea', function(){
    sync();
  });

  // When field type changes, rebuild to swap configuration blocks.
  $(document).on('change', '#ct-forms-builder .truitt-type', function(){
    sync();
    rebuild(true);
  });

  // Toggle a single field (no double-fire from the toggle button bubbling to the header).
  function ctFormsToggleRow($row){
    const $body = $row.find('.ct-forms-field-body');
    const collapsed = $row.hasClass('is-collapsed');

    if (collapsed) {
      $row.removeClass('is-collapsed');
      $row.find('.truitt-field-toggle').attr('aria-expanded','true');
      $body.stop(true,true).slideDown(120);
    } else {
      $row.addClass('is-collapsed');
      $row.find('.truitt-field-toggle').attr('aria-expanded','false');
      $body.stop(true,true).slideUp(120);
    }
      ctFormsSuppressDirty = false;
  }

  // Click on the caret button.
  $(document).on('click', '.truitt-field-toggle', function(e){
    e.preventDefault();
    e.stopPropagation();
    ctFormsToggleRow($(this).closest('.ct-forms-field-row'));
  });


  // Move field up/down (click-based reordering in addition to drag-and-drop).
  $(document).on('click', '.ct-forms-move-up', function(e){
    e.preventDefault();
    e.stopPropagation();
    const $row = $(this).closest('.ct-forms-field-row');
    const $prev = $row.prev('.ct-forms-field-row');
    if ($prev.length){
      $row.insertBefore($prev);
      sync();
      ctFormsMarkDirty();
    }
  });
  $(document).on('click', '.ct-forms-move-down', function(e){
    e.preventDefault();
    e.stopPropagation();
    const $row = $(this).closest('.ct-forms-field-row');
    const $next = $row.next('.ct-forms-field-row');
    if ($next.length){
      $row.insertAfter($next);
      sync();
      ctFormsMarkDirty();
    }
  });

  // Click anywhere on the header (except interactive controls).
  $(document).on('click', '.ct-forms-field-header', function(e){
    const $t = $(e.target);
    if ($t.closest('select, input, textarea, .actions, .handle, button').length) { return; }
    ctFormsToggleRow($(this).closest('.ct-forms-field-row'));
  });

  // Keep the ID badge in sync as the Field id is edited.
  $(document).on('keyup change', '#ct-forms-builder .truitt-id', function(){
    const $row = $(this).closest('.ct-forms-field-row');
    const v = ($(this).val() || '').trim();
    $row.find('.truitt-field-idpill').text('ID: ' + (v || '—'));
  });


  // Expand/collapse all.
  $(document).on('click', '#ct-expand-all', function(){
    $('#ct-forms-builder .ct-forms-field-row').each(function(){
      const $row = $(this);
      const $body = $row.find('.ct-forms-field-body');
      $row.removeClass('is-collapsed');
      $row.find('.truitt-field-toggle').attr('aria-expanded','true');
      $body.show();
    });
  });
  $(document).on('click', '#ct-collapse-all', function(){
    $('#ct-forms-builder .ct-forms-field-row').each(function(){
      const $row = $(this);
      const $body = $row.find('.ct-forms-field-body');
      $row.addClass('is-collapsed');
      $row.find('.truitt-field-toggle').attr('aria-expanded','false');
      $body.hide();
    });
  });

  $(document).on('click', '#ct-add-field', function(){
    newField();
  });

  $(document).on('click', '.truitt-del', function(){
    $(this).closest('.ct-forms-field-row').remove();
    sync();
  });


  // Options grid: add/remove option rows for select/checkboxes/radios
  $(document).on('click', '.truitt-opt-add', function(){
    const $row = $(this).closest('.ct-forms-field-row');
    const $grid = $row.find('.ct-forms-options-grid').first();
    if(!$grid.length) return;
    const $val = $('<input type="text" class="widefat truitt-opt-value" value="">');
    const $lab = $('<input type="text" class="widefat truitt-opt-label" value="">');
    const $del = $('<button type="button" class="button link-button truitt-opt-del" title="Remove option">Remove</button>');
    $grid.append($val, $lab, $del);
    $val.trigger('focus');
    sync();
  });

  $(document).on('click', '.truitt-opt-del', function(){
    const $btn = $(this);
    const $lab = $btn.prev('.truitt-opt-label');
    const $val = $lab.prev('.truitt-opt-value');
    if($val.length) $val.remove();
    if($lab.length) $lab.remove();
    $btn.remove();
    sync();
  });

  $(document).on('click', '.truitt-dup', function(){
    const $row = $(this).closest('.ct-forms-field-row');
    const def = parseDef();
    const id = ($row.find('.truitt-id').val()||'').trim();
    const f = (def.fields||[]).find(x => x.id===id);
    if(!f){ return; }
    const copy = JSON.parse(JSON.stringify(f));
    copy.id = copy.id + '_copy';
    def.fields.push(copy);
    saveDef(def);
    ctFormsMarkDirty();
    rebuild();
  });

  $(function(){
    if($('#ct-forms-builder').length){
      rebuild(false);
      ctFormsSetupDirtyGuards();
    }
  });

  // Settings tabs
  $(document).on('click', '.ct-forms-tab', function(){
    const tab = $(this).data('tab');
    $('.ct-forms-tab').removeClass('is-active').attr('aria-selected','false');
    $(this).addClass('is-active').attr('aria-selected','true');
    $('.ct-forms-tab-panel').removeClass('is-active');
    $('.ct-forms-tab-panel[data-panel="'+tab+'"]').addClass('is-active');
    $('#ct_active_tab').val(tab);
  });


  // Ensure the latest builder state is serialized before saving.
  $(document).on('submit', '#ct-forms-builder-form', function(){
    try { sync(); } catch(e) {}
  });
  // If saving settings, also keep builder definition in sync when present.
  $(document).on('submit', '#ct-forms-settings-form', function(){
    try { if ($('#ct_form_definition').length) { sync(); } } catch(e) {}
  });

})(jQuery);
