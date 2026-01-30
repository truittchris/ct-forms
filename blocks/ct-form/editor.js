(function(wp){
  const { registerBlockType } = wp.blocks;
  const { InspectorControls } = wp.blockEditor;
  const { PanelBody, SelectControl } = wp.components;
  const { createElement: el, Fragment, useEffect, useState } = wp.element;

  registerBlockType('ct-forms/ct-form', {
    edit: function(props){
      const [forms, setForms] = useState([]);
      useEffect(() => {
        wp.apiFetch({ path: '/wp/v2/ct_form?per_page=100' }).then(res => {
          const opts = (res||[]).map(p => ({ label: p.title.rendered || ('Form #' + p.id), value: p.id }));
          setForms([{label:'Select a form', value: 0}].concat(opts));
        }).catch(() => {
          setForms([{label:'Select a form', value: 0}]);
        });
      }, []);

      const formId = props.attributes.formId || 0;

      return el(Fragment, {},
        el(InspectorControls, {},
          el(PanelBody, { title: 'CT Form', initialOpen: true },
            el(SelectControl, {
              label: 'Form',
              value: formId,
              options: forms,
              onChange: (v) => props.setAttributes({ formId: parseInt(v||0, 10) })
            })
          )
        ),
        el('div', { className: 'ct-forms-block-placeholder' },
          formId ? 'CT Form embedded (ID ' + formId + ').' : 'Select a form in the block settings.'
        )
      );
    },
    save: function(){ return null; }
  });
})(window.wp);
