// Block editor: "Duplicate" in the More (three dots) menu, and a "Copy of #ID" line in the Summary/Status panel for
// copies. It never saves the post: with unsaved changes it asks first, because the copy is made from the saved version.
// The nonce'd link and the strings come from Duplicate::editor_assets() (window.lbDuplicate).
( function ( wp, data ) {
	if ( ! wp || ! data || ! wp.plugins || ! wp.data || ! wp.element ) {
		return;
	}
	const el = wp.element.createElement;
	const pick = ( name ) => ( wp.editor && wp.editor[ name ] ) || ( wp.editPost && wp.editPost[ name ] ) || null;
	const MenuItem = pick( 'PluginMoreMenuItem' );
	const StatusInfo = pick( 'PluginPostStatusInfo' );
	const i18n = data.i18n || {};

	function duplicate() {
		const editor = wp.data.select( 'core/editor' );
		if ( editor && editor.isEditedPostDirty() && ! window.confirm( i18n.dirty ) ) {
			return;
		}
		window.location.href = data.url;
	}

	function CopyOf() {
		const src = data.source;
		const label = ( i18n.copyOf || 'Copy of #%d' ).replace( '%d', String( src.id ) );
		return el( StatusInfo, { className: 'lb-duplicate-of' },
			el( 'span', null,
				src.url ? el( 'a', { href: src.url }, label ) : label,
				src.title ? ' ' + src.title : ''
			)
		);
	}

	function DuplicatePlugin() {
		const isNew = wp.data.useSelect( ( s ) => s( 'core/editor' ).isEditedPostNew(), [] );
		return el( wp.element.Fragment, null,
			MenuItem && data.url ? el( MenuItem, { icon: 'admin-page', onClick: duplicate, disabled: isNew }, i18n.label || 'Duplicate' ) : null,
			StatusInfo && data.source ? el( CopyOf ) : null
		);
	}

	wp.plugins.registerPlugin( 'lb-duplicate', { render: DuplicatePlugin } );
} )( window.wp, window.lbDuplicate );
