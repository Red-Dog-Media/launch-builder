( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, RangeControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const menus = [
		{ label: __( 'Footer menu', 'launch-builder' ), value: 'footer' },
		{ label: __( 'Legal menu (privacy, terms)', 'launch-builder' ), value: 'legal' },
		{ label: __( 'Main menu (top level)', 'launch-builder' ), value: 'main' },
	];
	const layouts = [
		{ label: __( 'List, one link per line', 'launch-builder' ), value: 'list' },
		{ label: __( 'Row of links', 'launch-builder' ), value: 'inline' },
	];

	function Edit( { attributes: a, setAttributes } ) {
		const set = ( k ) => ( v ) => setAttributes( { [ k ]: v } );
		const inspector = el( InspectorControls, null,
			el( PanelBody, { title: __( 'Menu links', 'launch-builder' ), initialOpen: true },
				el( SelectControl, { label: __( 'Menu', 'launch-builder' ), value: a.menu, options: menus, onChange: set( 'menu' ) } ),
				el( SelectControl, { label: __( 'Layout', 'launch-builder' ), value: a.layout, options: layouts, onChange: set( 'layout' ) } ),
				el( RangeControl, { label: __( 'Most links', 'launch-builder' ), help: __( '0 = all.', 'launch-builder' ), value: a.limit, min: 0, max: 24, onChange: set( 'limit' ) } )
			)
		);
		return el( 'div', useBlockProps(), inspector, el( ServerSideRender, { block: 'launch/menu-links', attributes: a } ) );
	}

	registerBlockType( 'launch/menu-links', { edit: Edit, save: () => null } );
} )( window.wp );
