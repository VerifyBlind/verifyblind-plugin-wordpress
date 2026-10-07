(function (blocks, element, blockEditor, components, i18n) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	var rules = window.VerifyBlindRules || [];

	function labelFor(id) {
		for (var i = 0; i < rules.length; i++) {
			if (rules[i].value === id) return rules[i].label;
		}
		return id;
	}

	blocks.registerBlockType('verifyblind/gate', {
		apiVersion: 2,
		title: __('VerifyBlind lock', 'verifyblind'),
		description: __('Shows the inner blocks only to visitors who meet a VerifyBlind rule.', 'verifyblind'),
		icon: 'shield',
		category: 'widgets',
		attributes: { rule: { type: 'string', default: '' } },
		edit: function (props) {
			var options = [{ value: '', label: __('Choose a rule', 'verifyblind') }].concat(rules);
			return el('div', blockEditor.useBlockProps({ className: 'verifyblind-gate-editor' }),
				el(blockEditor.InspectorControls, null,
					el(components.PanelBody, { title: __('VerifyBlind rule', 'verifyblind') },
						el(components.SelectControl, {
							label: __('Rule', 'verifyblind'),
							value: props.attributes.rule,
							options: options,
							onChange: function (v) { props.setAttributes({ rule: v }); }
						}))),
				el('p', { className: 'verifyblind-gate-editor__label' },
					'🔒 ' + (props.attributes.rule ? labelFor(props.attributes.rule) : __('Choose a rule in the block settings', 'verifyblind'))),
				el(blockEditor.InnerBlocks));
		},
		save: function () {
			return el(blockEditor.InnerBlocks.Content);
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
