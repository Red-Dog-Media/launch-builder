// Document sidebar panel in the block editor. On pages it is "Page role" (the _lb_role meta) and the hero choice; on posts,
// services and areas it is "Shared hero" with the hero choice only. Both metas are registered for REST, so the editor saves
// them with the post and no legacy meta box is needed. Data from Roles::editor_panel().
( function ( wp, data ) {
	if ( ! wp || ! data || ! wp.plugins || ! wp.data || ! wp.components ) {
		return;
	}
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}
	const el = wp.element.createElement;
	const { useSelect, useDispatch } = wp.data;
	const { SelectControl } = wp.components;
	const isPage = 'page' === data.type;
	const hero = data.hero || null;
	const options = isPage ? [ { label: 'Automatic', value: '' } ].concat( Object.keys( data.roles ).map( ( r ) => ( { label: data.roles[ r ], value: r } ) ) ) : [];

	function RolePanel() {
		const postType = useSelect( ( s ) => s( 'core/editor' ).getCurrentPostType(), [] );
		const meta = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'meta' ), [] );
		const { editPost } = useDispatch( 'core/editor' );
		if ( data.type !== postType || ! meta ) {
			return null;
		}
		const kids = [];
		if ( isPage ) {
			const value = data.roles[ meta[ data.meta ] ] ? meta[ data.meta ] : '';
			// What the page will be once saved: the chosen role, else what it resolves to without one.
			const now = data.locked ? data.current : ( value ? { label: data.roles[ value ], why: 'set here' } : data.auto );
			kids.push( el( SelectControl, {
				key: 'role',
				label: 'Role',
				value,
				options,
				disabled: !! data.locked,
				onChange: ( v ) => editPost( { meta: { [ data.meta ]: v } } ),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ) );
			kids.push( el( 'p', { key: 'why', className: 'description', style: { marginTop: '8px' } },
				'Role: ', el( 'strong', null, now.label ), ' (' + now.why + '). The role decides the page hero, breadcrumbs and header/footer variant. Conversion pages (contact, quote, booking) get no breadcrumbs.'
			) );
		}
		if ( hero ) {
			const own = [ 'content', 'none' ].indexOf( meta[ hero.meta ] ) > -1 ? meta[ hero.meta ] : '';
			kids.push( el( 'div', { key: 'hero', style: isPage ? { marginTop: '16px' } : null },
				el( SelectControl, {
					label: hero.label,
					value: own,
					options: hero.options,
					help: hero.help,
					onChange: ( v ) => editPost( { meta: { [ hero.meta ]: v } } ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} )
			) );
		}
		return el( Panel, { name: isPage ? 'lb-page-role' : 'lb-shared-hero', title: isPage ? 'Page role' : 'Shared hero', className: 'lb-page-role' }, kids );
	}

	wp.plugins.registerPlugin( 'lb-page-role', { render: RolePanel } );
} )( window.wp, window.lbPageRole );
