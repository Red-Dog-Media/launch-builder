( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { useBlockProps } = wp.blockEditor;
	registerBlockType( 'launch/breadcrumbs', {
		edit: () => el( 'div', useBlockProps( { className: 'lb-crumbs-wrap' } ), el( 'nav', { className: 'lb-crumbs' }, el( 'ol', null,
			el( 'li', null, __( 'Home', 'launch-builder' ) ), el( 'li', null, __( 'Parent', 'launch-builder' ) ), el( 'li', { 'aria-current': 'page' }, __( 'Current page', 'launch-builder' ) )
		) ) ),
		save: () => null,
	} );
} )( window.wp );
