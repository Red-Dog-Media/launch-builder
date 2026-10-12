// "URL" panel in the block editor's document sidebar: the post's URL path (custom, automatic from the URL pattern of
// its type, or the WordPress default), saved through /launch-builder/v1/uri (the old URL gets a 301 automatically).
// Data from Permalinks::editor_panel(). Plain wp.* globals, no build step.
( function ( wp, data ) {
	if ( ! wp || ! data || ! wp.plugins || ! wp.data || ! wp.components || ! wp.apiFetch || ! wp.element ) {
		return;
	}
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}
	const el = wp.element.createElement;
	const { useState, useEffect, useRef } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { TextControl, Button, Notice, ExternalLink } = wp.components;
	const t = data.i18n || {};

	function UrlPanel() {
		const ed = useSelect( ( s ) => {
			const e = s( 'core/editor' );
			return {
				id: e.getCurrentPostId(),
				type: e.getCurrentPostType(),
				status: e.getEditedPostAttribute( 'status' ),
				saving: e.isSavingPost() && ! e.isAutosavingPost(),
			};
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );
		const [ info, setInfo ] = useState( null );
		const [ value, setValue ] = useState( '' );
		const [ msg, setMsg ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const wasSaving = useRef( false );
		const managed = ed.type && ( data.types || [] ).indexOf( ed.type ) !== -1;
		const object = 'post:' + ed.id;

		const load = () => {
			if ( ! managed || ! ed.id || 'auto-draft' === ed.status ) {
				return;
			}
			wp.apiFetch( { path: '/launch-builder/v1/uri?object=' + encodeURIComponent( object ) } )
				.then( ( r ) => {
					setInfo( r );
					setValue( 'custom' === r.mode ? r.uri : '' );
				} )
				.catch( ( e ) => setMsg( { status: 'error', text: e && e.message ? e.message : String( e ) } ) );
		};

		useEffect( load, [ ed.id, managed ] );
		useEffect( () => { // The URL may change when the post is saved (slug, parent, category): reload after a save.
			if ( wasSaving.current && ! ed.saving ) {
				load();
			}
			wasSaving.current = ed.saving;
		}, [ ed.saving ] );

		if ( ! managed ) {
			return null;
		}

		const send = ( uri ) => {
			setBusy( true );
			setMsg( null );
			wp.apiFetch( { path: '/launch-builder/v1/uri', method: 'POST', data: { object, uri } } )
				.then( ( r ) => {
					setInfo( r.info );
					setValue( 'custom' === r.info.mode ? r.info.uri : '' );
					if ( r.info && r.info.slug ) {
						editPost( { slug: r.info.slug } ); // Keep the editor's slug in step with slug sync.
					}
					const moved = r.redirect && r.redirect.redirect;
					setMsg( { status: 'success', text: ( t.saved || 'Saved.' ) + ( moved ? ' ' + ( t.moved || '' ) : '' ) } );
				} )
				.catch( ( e ) => setMsg( { status: 'error', text: e && e.message ? e.message : String( e ) } ) )
				.finally( () => setBusy( false ) );
		};

		const children = [];
		if ( ! info ) {
			children.push( el( 'p', { key: 'wait', className: 'description' }, 'auto-draft' === ed.status ? ( t.unsaved || '' ) : '…' ) );
		} else {
			if ( info.url ) {
				children.push( el( 'p', { key: 'url', style: { wordBreak: 'break-all' } }, el( ExternalLink, { href: info.url }, info.url ) ) );
			}
			children.push( el( 'p', { key: 'mode', className: 'description' }, t[ info.mode ] || info.mode, info.live ? '' : ' ' + ( t.draft || '' ) ) );
			children.push( el( TextControl, {
				key: 'field',
				label: t.label || 'URL path',
				value,
				placeholder: info.auto || '',
				help: info.auto ? ( t.autoIs || '' ) + ' /' + info.auto + '/' : '',
				onChange: setValue,
				disabled: busy,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ) );
			children.push( el( 'div', { key: 'buttons', style: { display: 'flex', gap: '8px', marginTop: '10px', flexWrap: 'wrap' } },
				el( Button, { variant: 'secondary', isBusy: busy, disabled: busy || '' === value.trim(), onClick: () => send( value.trim() ) }, t.save || 'Save URL' ),
				'custom' === info.mode ? el( Button, { variant: 'tertiary', disabled: busy, onClick: () => send( '' ) }, t.reset || 'Use automatic URL' ) : null
			) );
		}
		if ( msg ) {
			children.push( el( Notice, { key: 'msg', status: msg.status, isDismissible: true, onRemove: () => setMsg( null ) }, msg.text ) );
		}
		return el( Panel, { name: 'lb-permalinks', title: t.title || 'URL', className: 'lb-permalinks' }, children );
	}

	wp.plugins.registerPlugin( 'lb-permalinks', { render: UrlPanel } );
} )( window.wp, window.lbPermalinks );
