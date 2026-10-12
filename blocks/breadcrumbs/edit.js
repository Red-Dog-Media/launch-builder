( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, ToggleControl } = wp.components;
	const { useSelect } = wp.data;
	const ServerSideRender = wp.serverSideRender;

	const VARIANTS = [
		{ value: 'contained', label: __( 'Contained (home icon, divider, soft edge)', 'launch-builder' ) },
		{ value: 'plain', label: __( 'Plain text', 'launch-builder' ) },
		{ value: 'bar', label: __( 'Tinted bar', 'launch-builder' ) },
		{ value: 'pill', label: __( 'Pill', 'launch-builder' ) },
		{ value: 'underline', label: __( 'Underlined', 'launch-builder' ) },
	];
	const SEPARATORS = [
		{ value: 'raquo', label: '»' },
		{ value: 'chevron', label: '›' },
		{ value: 'slash', label: '/' },
		{ value: 'arrow', label: '→' },
		{ value: 'dot', label: '·' },
		{ value: 'pipe', label: '|' },
	];

	registerBlockType( 'launch/breadcrumbs', {
		edit: function ( props ) {
			const a = props.attributes;
			const set = props.setAttributes;
			// The trail is built on the server for the post being edited (a template post shows its role's sample).
			const editorId = useSelect( function ( select ) {
				const ed = select( 'core/editor' );
				return ed && ed.getCurrentPostId ? ed.getCurrentPostId() : 0;
			}, [] );
			const postId = ( props.context && props.context.postId ) || editorId || 0;
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Breadcrumbs', 'launch-builder' ) },
						el( SelectControl, { label: __( 'Style', 'launch-builder' ), value: a.variant, options: VARIANTS, onChange: function ( v ) { set( { variant: v } ); } } ),
						el( SelectControl, { label: __( 'Separator', 'launch-builder' ), value: a.separator, options: SEPARATORS, onChange: function ( v ) { set( { separator: v } ); } } ),
						el( TextControl, { label: __( 'Home label', 'launch-builder' ), help: __( 'Leave empty to show the home icon as the first crumb.', 'launch-builder' ), value: a.homeLabel, onChange: function ( v ) { set( { homeLabel: v } ); } } ),
						el( ToggleControl, { label: __( 'Show the current page', 'launch-builder' ), checked: !! a.showCurrent, onChange: function ( v ) { set( { showCurrent: v } ); } } )
					)
				),
				el(
					'div',
					useBlockProps(),
					el( ServerSideRender, { block: 'launch/breadcrumbs', attributes: a, urlQueryArgs: postId ? { post_id: postId } : {} } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
