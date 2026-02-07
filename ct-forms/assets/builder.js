
jQuery(document).ready(function($) {
    const canvas = $('#ct-active-fields');
    if(!canvas.length) return;

    function createCard(type, label, required, placeholder, options) {
        const displayType = type.toUpperCase().replace('_', ' ');
        const hasOptions = (type === 'select' || type === 'checkbox');

        return `
            <div class="ct-field-item ct-card" data-type="${type}">
                <div class="ct-field-handle">
                    <strong>${displayType} FIELD</strong>
                    <button type="button" class="ct-remove-field">×</button>
                </div>
                <div class="ct-field-settings">
                    <div class="ct-input-wrap"><label>Label Text</label><input type="text" value="${label}" class="ct-field-label"></div>
                    <div class="ct-input-wrap"><label>Field Hint</label><input type="text" value="${placeholder}" class="ct-field-placeholder"></div>
                    <div style="grid-column: span 2; display:flex; gap:20px; align-items:center; border-top:1px solid #f1f5f9; padding-top:15px; margin-top:5px;">
                        <label style="font-weight:700; color:#475569;"><input type="checkbox" class="ct-field-required" ${required==='yes'?'checked':''}> Required</label>
                        ${hasOptions ? `<input type="text" value="${options}" class="ct-field-options" placeholder="Options (comma separated)" style="flex:1;">` : ''}
                    </div>
                </div>
            </div>`;
    }

    function initEngine() {
        $('.ct-palette-item').draggable({
            helper: 'clone', revert: 'invalid', appendTo: 'body', zIndex: 1000
        });
        canvas.droppable({
            accept: '.ct-palette-item',
            drop: function(e, ui) {
                $(this).append(createCard(ui.draggable.data('type'), '', 'no', '', ''));
                $('.ct-empty-msg').hide();
            }
        });
        canvas.sortable({ handle: '.ct-field-handle' });
    }

    const existing = canvas.data('existing');
    if(existing && existing.length > 0) {
        $('.ct-empty-msg').hide();
        existing.forEach(f => canvas.append(createCard(f.type, f.label, f.required || 'no', f.placeholder || '', f.options || '')));
    }

    initEngine();

    $(document).on('click', '.ct-remove-field', function(){ $(this).closest('.ct-field-item').remove(); if(!canvas.children('.ct-field-item').length) $('.ct-empty-msg').show(); });
    $(document).on('click', '.ct-tab-btn', function(e) { e.preventDefault(); $('.ct-tab-btn, .ct-tab-content').removeClass('active'); $(this).addClass('active'); $('#'+$(this).data('tab')).addClass('active'); });

    $('#ct-save-form').on('click', function() {
        const fields = [];
        $('.ct-field-item').each(function() {
            fields.push({ 
                type: $(this).data('type'), 
                label: $(this).find('.ct-field-label').val(), 
                placeholder: $(this).find('.ct-field-placeholder').val(), 
                options: $(this).find('.ct-field-options').val() || '', 
                required: $(this).find('.ct-field-required').is(':checked') ? 'yes' : 'no' 
            });
        });
        const d = { 
            action: 'ct_save_form', security: ctFormsBuilder.nonce, edit_id: $('#ct-edit-id').val(), 
            title: $('#ct-form-title').val(), email_to: $('#ct-email-to').val(),
            notif_body: tinyMCE.get('ctnotifbody').getContent(), ar_enabled: $('#ct-ar-enabled').is(':checked')?'yes':'no',
            ar_body: tinyMCE.get('ctarbody').getContent(), success_msg: tinyMCE.get('ctsuccessmsg').getContent(),
            fields: JSON.stringify(fields) 
        };
        $.post(ctFormsBuilder.ajaxurl, d, function() { alert('Design Saved (Logic Removed)'); });
    });
});
