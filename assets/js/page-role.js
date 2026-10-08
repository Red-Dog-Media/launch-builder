// "Page role" panel in the block editor's document sidebar (pages only). It edits the registered _lb_role meta, which
// the editor saves with the post over REST, so pages need no legacy meta box. Data from Roles::editor_panel().
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
	const options = [ { label: 'Automatic', value: '' } ].concat( Object.keys( data.roles ).map( ( r ) => ( { label: data.roles[ r ], value: r } ) ) );

	function RolePanel() {
		const postType = useSelect( ( s ) => s( 'core/editor' ).getCurrentPostType(), [] );
		const meta = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'meta' ), [] );
		const { editPost } = useDispatch( 'core/editor' );
		if ( 'page' !== postType || ! meta ) {
			return null;
		}
		const value = data.roles[ meta[ data.meta ] ] ? meta[ data.meta ] : '';
		// What the page will be once saved: the chosen role, else what it resolves to without one.
		const now = data.locked ? data.current : ( value ? { label: data.roles[ value ], why: 'set here' } : data.auto );
		return el( Panel, { name: 'lb-page-role', title: 'Page role', className: 'lb-page-role' },
			el( SelectControl, {
				label: 'Role',
				value,
				options,
				disabled: !! data.locked,
				onChange: ( v ) => editPost( { meta: { [ data.meta ]: v } } ),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			el( 'p', { className: 'description', style: { marginTop: '8px' } },
				'Role: ', el( 'strong', null, now.label ), ' (' + now.why + '). The role decides the page hero, breadcrumbs and header/footer variant. Conversion pages (contact, quote, booking) get no breadcrumbs.'
			)
		);
	}

	wp.plugins.registerPlugin( 'lb-page-role', { render: RolePanel } );
} )( window.wp, window.lbPageRole );
