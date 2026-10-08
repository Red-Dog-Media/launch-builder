( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, RangeControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const types = [ { label: __( 'Services', 'launch-builder' ), value: 'service' }, { label: __( 'Service areas', 'launch-builder' ), value: 'area' } ];
	const layouts = [
		{ label: __( 'Cards', 'launch-builder' ), value: 'cards' },
		{ label: __( 'Category cards with checklists (services)', 'launch-builder' ), value: 'category-cards' },
		{ label: __( 'Pills (buttons)', 'launch-builder' ), value: 'pills' },
		{ label: __( 'Tiles with check icons', 'launch-builder' ), value: 'tiles' },
		{ label: __( 'Check list', 'launch-builder' ), value: 'checklist' },
		{ label: __( 'Columns of links', 'launch-builder' ), value: 'columns' },
		{ label: __( 'Plain links', 'launch-builder' ), value: 'links' },
	];

	function Edit( { attributes: a, setAttributes } ) {
		const set = ( k ) => ( v ) => setAttributes( { [ k ]: v } );
		const inspector = el( InspectorControls, null,
			el( PanelBody, { title: __( 'List', 'launch-builder' ), initialOpen: true },
				el( SelectControl, { label: __( 'Show', 'launch-builder' ), value: a.type, options: types, onChange: set( 'type' ) } ),
				el( SelectControl, { label: __( 'Layout', 'launch-builder' ), value: a.layout, options: layouts, onChange: set( 'layout' ) } ),
				el( RangeControl, { label: __( 'Columns', 'launch-builder' ), value: a.columns, min: 1, max: 4, onChange: set( 'columns' ) } ),
				el( RangeControl, { label: __( 'Maximum entries', 'launch-builder' ), value: a.limit, min: 1, max: 100, onChange: set( 'limit' ) } )
			),
			el( PanelBody, { title: __( 'Filter', 'launch-builder' ), initialOpen: false },
				a.type === 'service'
					? el( TextControl, { label: __( 'Category slug (blank = all)', 'launch-builder' ), value: a.category, onChange: set( 'category' ) } )
					: el( TextControl, { label: __( 'Parent area slug, or "top" (blank = all)', 'launch-builder' ), value: a.parent, onChange: set( 'parent' ) } ),
				el( ToggleControl, { label: __( 'Leave out the page being viewed', 'launch-builder' ), checked: !! a.excludeCurrent, onChange: set( 'excludeCurrent' ) } ),
				a.type === 'service' ? el( ToggleControl, { label: __( 'Only services in the same category as this page', 'launch-builder' ), checked: !! a.related, onChange: set( 'related' ) } ) : null,
				el( ToggleControl, { label: __( 'Show summaries (cards)', 'launch-builder' ), checked: a.showSummary !== false, onChange: set( 'showSummary' ) } ),
				el( TextControl, { label: __( 'Link text', 'launch-builder' ), value: a.linkLabel, onChange: set( 'linkLabel' ) } ),
				el( TextControl, { label: __( 'Your own design: synced pattern key', 'launch-builder' ), help: __( 'Make a synced pattern with {{entry.title}}, {{entry.summary}}, {{entry.image}} and a link to #entry-url; it is repeated for each entry.', 'launch-builder' ), value: a.template, onChange: set( 'template' ) } )
			)
		);
		return el( 'div', useBlockProps(), inspector, el( ServerSideRender, { block: 'launch/entries', attributes: a } ) );
	}

	registerBlockType( 'launch/entries', { edit: Edit, save: () => null } );
} )( window.wp );
