( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { useBlockProps } = wp.blockEditor;
	registerBlockType( 'launch/post-body', {
		edit: () => el( 'div', useBlockProps( { style: { padding: '28px', border: '2px dashed #94a3b8', borderRadius: '12px', textAlign: 'center', color: '#475569', background: '#f8fafc' } } ), __( 'The text of each service or area appears here', 'launch-builder' ) ),
		save: () => null,
	} );
} )( window.wp );
