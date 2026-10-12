( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, RangeControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const useSelect = wp.data && wp.data.useSelect;

	// The allow-lists come from the server (Entries::LAYOUTS / VARIANTS), so PHP and the editor never drift.
	const server = window.launchBuilderEntries || {};
	const layouts = server.layouts && server.layouts.length ? server.layouts : [ { label: __( 'Cards', 'launch-builder' ), value: 'cards' } ];
	const variants = [ { label: __( 'Default', 'launch-builder' ), value: '' } ].concat( server.variants || [] );
	const viewAll = server.viewAll || [];
	const types = [ { label: __( 'Services', 'launch-builder' ), value: 'service' }, { label: __( 'Service areas', 'launch-builder' ), value: 'area' } ];
	const SLUG = '__slug';
	const categoryModes = [
		{ label: __( 'All categories', 'launch-builder' ), value: '' },
		{ label: __( 'The category of the page being viewed', 'launch-builder' ), value: 'current' },
		{ label: __( 'Chosen categories (slugs)…', 'launch-builder' ), value: SLUG },
	];
	const parentModes = [
		{ label: __( 'All areas', 'launch-builder' ), value: '' },
		{ label: __( 'Top-level areas only', 'launch-builder' ), value: 'top' },
		{ label: __( 'Areas inside the area being viewed', 'launch-builder' ), value: 'current' },
		{ label: __( 'Every area below the area being viewed', 'launch-builder' ), value: 'children' },
		{ label: __( 'Other areas with the same parent', 'launch-builder' ), value: 'siblings' },
		{ label: __( 'Areas inside a chosen area…', 'launch-builder' ), value: SLUG },
	];
	const orders = [ { label: __( 'Order field, then title', 'launch-builder' ), value: '' }, { label: __( 'Title', 'launch-builder' ), value: 'title' } ];
	const mode = ( value, modes ) => ( '' === ( value || '' ) || modes.some( ( m ) => m.value === value && m.value !== SLUG ) ? ( value || '' ) : SLUG );

	function Edit( { attributes: a, setAttributes } ) {
		const set = ( k ) => ( v ) => setAttributes( { [ k ]: v } );
		// The post being edited, so lists that follow "the page being viewed" preview against it.
		const postId = useSelect ? useSelect( ( select ) => { const ed = select( 'core/editor' ); return ed && ed.getCurrentPostId ? ed.getCurrentPostId() : 0; }, [] ) : 0;
		const [ typing, setTyping ] = wp.element.useState( { category: false, parent: false } ); // "Chosen…" picked, nothing typed yet.
		const isService = a.type !== 'area';
		const catMode = typing.category ? SLUG : mode( a.category, categoryModes );
		const parentMode = typing.parent ? SLUG : mode( a.parent, parentModes );
		const pick = ( key, current ) => ( v ) => {
			setTyping( Object.assign( {}, typing, { [ key ]: v === SLUG } ) );
			setAttributes( { [ key ]: v === SLUG ? ( current === SLUG ? a[ key ] : '' ) : v } );
		};
		const ids = Array.isArray( a.ids ) ? a.ids.join( ',' ) : ( a.ids || '' );
		const inspector = el( InspectorControls, null,
			el( PanelBody, { title: __( 'List', 'launch-builder' ), initialOpen: true },
				el( SelectControl, { label: __( 'Show', 'launch-builder' ), value: a.type, options: types, onChange: set( 'type' ) } ),
				el( SelectControl, { label: __( 'Layout', 'launch-builder' ), value: a.layout, options: layouts, onChange: set( 'layout' ) } ),
				el( SelectControl, { label: __( 'Style', 'launch-builder' ), value: a.variant || '', options: variants, onChange: set( 'variant' ) } ),
				el( ToggleControl, { label: __( 'Show each entry\'s icon', 'launch-builder' ), checked: !! a.showIcon || a.layout === 'icon-cards', disabled: a.layout === 'icon-cards', onChange: set( 'showIcon' ) } ),
				el( RangeControl, { label: __( 'Columns', 'launch-builder' ), value: a.columns, min: 1, max: 4, onChange: set( 'columns' ) } ),
				el( RangeControl, { label: __( 'Maximum entries', 'launch-builder' ), help: viewAll.indexOf( a.layout ) >= 0 ? __( '0 = all. A "View all" link follows when there are more.', 'launch-builder' ) : __( '0 = all.', 'launch-builder' ), value: a.limit, min: 0, max: 100, onChange: set( 'limit' ) } )
			),
			el( PanelBody, { title: __( 'Filter', 'launch-builder' ), initialOpen: false },
				isService
					? el( SelectControl, { label: __( 'Category', 'launch-builder' ), value: catMode, options: categoryModes, onChange: pick( 'category', catMode ) } )
					: el( SelectControl, { label: __( 'Areas', 'launch-builder' ), value: parentMode, options: parentModes, onChange: pick( 'parent', parentMode ) } ),
				isService && catMode === SLUG ? el( TextControl, { label: __( 'Category slugs (comma separated)', 'launch-builder' ), value: a.category, onChange: set( 'category' ) } ) : null,
				! isService && parentMode === SLUG ? el( TextControl, { label: __( 'Parent area (slug or path such as texas/travis-county)', 'launch-builder' ), value: a.parent, onChange: set( 'parent' ) } ) : null,
				isService ? el( ToggleControl, { label: __( 'Only services offered in the area being viewed', 'launch-builder' ), checked: a.inArea === 'current', onChange: ( v ) => setAttributes( { inArea: v ? 'current' : '' } ) } ) : null,
				isService ? el( ToggleControl, { label: __( 'Only services in the same category as this page', 'launch-builder' ), checked: !! a.related, onChange: set( 'related' ) } ) : null,
				el( ToggleControl, { label: __( 'Leave out the page being viewed', 'launch-builder' ), checked: !! a.excludeCurrent, onChange: set( 'excludeCurrent' ) } ),
				el( TextControl, { label: __( 'Only these entries (IDs, comma separated)', 'launch-builder' ), value: ids, onChange: set( 'ids' ) } ),
				el( SelectControl, { label: __( 'Order', 'launch-builder' ), value: a.orderby || '', options: orders, onChange: set( 'orderby' ) } )
			),
			el( PanelBody, { title: __( 'Text', 'launch-builder' ), initialOpen: false },
				el( ToggleControl, { label: __( 'Show summaries', 'launch-builder' ), checked: a.showSummary !== false, onChange: set( 'showSummary' ) } ),
				el( TextControl, { label: __( 'Link text ("Learn more", or the "View all" text)', 'launch-builder' ), value: a.linkLabel, onChange: set( 'linkLabel' ) } ),
				el( TextControl, { label: __( 'Your own design: synced pattern key', 'launch-builder' ), help: __( 'Make a synced pattern with {{entry.title}}, {{entry.summary}}, {{entry.image}} and a link to #entry-url; it is repeated for each entry.', 'launch-builder' ), value: a.template, onChange: set( 'template' ) } )
			)
		);
		return el( 'div', useBlockProps(), inspector, el( ServerSideRender, { block: 'launch/entries', attributes: a, urlQueryArgs: postId ? { post_id: postId } : {} } ) );
	}

	registerBlockType( 'launch/entries', { edit: Edit, save: () => null } );
} )( window.wp );
