(function (blocks, element, i18n, components, blockEditor) {
  const { registerBlockType } = blocks;
  const { Fragment } = element;
  const { __ } = i18n;
  const { InspectorControls } = blockEditor;
  const { PanelBody, TextControl, ToggleControl } = components;

  registerBlockType('voice-notes/recorder', {
    title: __('Voice Note Recorder', 'voice-notes'),
    icon: 'microphone',
    category: 'widgets',
    attributes: {
      label: { type: 'string' },
      auto_open: { type: 'boolean', default: false },
      recipient_email: { type: 'string' },
      phone: { type: 'string' },
      min_seconds: { type: 'number' },
      max_seconds: { type: 'number' },
      theme: { type: 'string' },
    },
    edit: ({ attributes, setAttributes }) => {
      return (
        element.createElement(
          Fragment,
          null,
          element.createElement(
            InspectorControls,
            null,
            element.createElement(
              PanelBody,
              { title: __('Voice Note Settings', 'voice-notes') },
              element.createElement(TextControl, {
                label: __('Button label', 'voice-notes'),
                value: attributes.label || '',
                onChange: (value) => setAttributes({ label: value }),
              }),
              element.createElement(ToggleControl, {
                label: __('Auto open modal', 'voice-notes'),
                checked: attributes.auto_open,
                onChange: (value) => setAttributes({ auto_open: value }),
              }),
              element.createElement(TextControl, {
                label: __('Recipient email', 'voice-notes'),
                value: attributes.recipient_email || '',
                onChange: (value) => setAttributes({ recipient_email: value }),
              }),
              element.createElement(TextControl, {
                label: __('Phone number', 'voice-notes'),
                value: attributes.phone || '',
                onChange: (value) => setAttributes({ phone: value }),
              }),
              element.createElement(TextControl, {
                label: __('Min seconds', 'voice-notes'),
                type: 'number',
                value: attributes.min_seconds || '',
                onChange: (value) => setAttributes({ min_seconds: Number(value) }),
              }),
              element.createElement(TextControl, {
                label: __('Max seconds', 'voice-notes'),
                type: 'number',
                value: attributes.max_seconds || '',
                onChange: (value) => setAttributes({ max_seconds: Number(value) }),
              }),
              element.createElement(TextControl, {
                label: __('Theme', 'voice-notes'),
                value: attributes.theme || '',
                onChange: (value) => setAttributes({ theme: value }),
              })
            )
          ),
          element.createElement(
            'div',
            { className: 'vn-block-preview' },
            element.createElement('strong', null, __('Voice Note Recorder', 'voice-notes')),
            element.createElement(
              'p',
              null,
              __('The recorder will render a button on the front end.', 'voice-notes')
            )
          )
        )
      );
    },
    save: () => null,
  });
})(window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.components, window.wp.blockEditor);
