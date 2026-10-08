( function ( wp ) {
	const { registerBlockType, getBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, MediaUpload, MediaUploadCheck, PanelColorSettings } = wp.blockEditor;
	const { PanelBody, RangeControl, ToggleControl, SelectControl, TextControl, Button } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;
	const el = wp.element.createElement;

	const WEIGHTS = [ [ '300', 'Light' ], [ '400', 'Regular' ], [ '500', 'Medium' ], [ '600', 'Semibold' ], [ '700', 'Bold' ], [ '800', 'Extra bold' ] ];

	function Edit( { attributes: a, setAttributes: set, name } ) {
		const blockProps = useBlockProps();
		const defaults = getBlockType( name ).attributes;
		const reset = ( key, v ) => ( v === undefined || v === null ? defaults[ key ].default : v );

		const range = ( key, label, min, max, step ) =>
			el( RangeControl, { key, label, value: a[ key ], min, max, step: step || 1, allowReset: true, resetFallbackValue: defaults[ key ].default, onChange: ( v ) => set( { [ key ]: reset( key, v ) } ), __nextHasNoMarginBottom: true } );
		const select = ( key, label, options ) =>
			el( SelectControl, { key, label, value: a[ key ], options: options.map( ( o ) => ( { value: o[ 0 ], label: o[ 1 ] } ) ), onChange: ( v ) => set( { [ key ]: v } ), __nextHasNoMarginBottom: true } );
		const toggle = ( key, label, help ) =>
			el( ToggleControl, { key, label, help, checked: !! a[ key ], onChange: ( v ) => set( { [ key ]: v } ), __nextHasNoMarginBottom: true } );
		const input = ( key, label, help, type ) =>
			el( TextControl, { key, label, help, type: type || 'text', value: a[ key ] === undefined ? '' : a[ key ], onChange: ( v ) => set( { [ key ]: v } ), __nextHasNoMarginBottom: true } );

		/* ---- logos ---- */
		const logos = a.logos || [];
		const setLogos = ( l ) => set( { logos: l } );
		const patchLogo = ( i, p ) => setLogos( logos.map( ( l, k ) => ( k === i ? Object.assign( {}, l, p ) : l ) ) );
		const moveLogo = ( i, d ) => {
			const j = i + d;
			if ( j < 0 || j >= logos.length ) return;
			const c = logos.slice();
			[ c[ i ], c[ j ] ] = [ c[ j ], c[ i ] ];
			setLogos( c );
		};

		const logoRows = logos.map( ( l, i ) =>
			el( 'div', { key: i, className: 'lb-logo-row', style: { display: 'flex', gap: 8, alignItems: 'flex-start', padding: '8px 0', borderBottom: '1px solid #e0e0e0' } },
				el( 'img', { src: l.url, alt: '', style: { width: 48, height: 48, objectFit: 'contain', background: '#f6f6f6', borderRadius: 4, flex: 'none' } } ),
				el( 'div', { style: { flex: 1, minWidth: 0 } },
					el( TextControl, { label: __( 'Alt text', 'launch-builder' ), value: l.alt || '', onChange: ( v ) => patchLogo( i, { alt: v } ), __nextHasNoMarginBottom: true } ),
					el( 'div', { style: { height: 6 } } ),
					el( TextControl, { label: __( 'Link (optional)', 'launch-builder' ), type: 'url', value: l.link || '', onChange: ( v ) => patchLogo( i, { link: v } ), __nextHasNoMarginBottom: true } ),
					el( 'div', { style: { marginTop: 8 } }, el( RangeControl, { label: __( 'Height of this logo (px)', 'launch-builder' ), value: l.height || a.logoHeight, min: 12, max: 160, allowReset: true, resetFallbackValue: undefined, onChange: ( v ) => patchLogo( i, { height: v === undefined ? '' : v } ), __nextHasNoMarginBottom: true } ) ),
					i > 0 ? el( 'div', { style: { marginTop: 8 } }, el( RangeControl, { label: __( 'Space before this logo (px)', 'launch-builder' ), value: l.space === undefined || l.space === '' ? a.logoGap : l.space, min: -40, max: 60, allowReset: true, resetFallbackValue: undefined, onChange: ( v ) => patchLogo( i, { space: v === undefined ? '' : v } ), __nextHasNoMarginBottom: true } ) ) : null
				),
				el( 'div', { style: { display: 'flex', flexDirection: 'column', flex: 'none' } },
					el( Button, { icon: 'arrow-up-alt2', label: __( 'Move up', 'launch-builder' ), size: 'small', disabled: i === 0, onClick: () => moveLogo( i, -1 ) } ),
					el( Button, { icon: 'arrow-down-alt2', label: __( 'Move down', 'launch-builder' ), size: 'small', disabled: i === logos.length - 1, onClick: () => moveLogo( i, 1 ) } ),
					el( Button, { icon: 'trash', label: __( 'Remove', 'launch-builder' ), size: 'small', isDestructive: true, onClick: () => setLogos( logos.filter( ( _, k ) => k !== i ) ) } )
				)
			)
		);

		const addLogos = el( MediaUploadCheck, { key: 'add' },
			el( MediaUpload, {
				allowedTypes: [ 'image' ],
				multiple: true,
				value: logos.map( ( l ) => l.id ).filter( Boolean ),
				onSelect: ( items ) => setLogos( logos.concat( ( Array.isArray( items ) ? items : [ items ] ).map( ( m ) => ( { id: m.id, url: m.url, alt: m.alt || '', link: '' } ) ) ) ),
				render: ( { open } ) => el( Button, { variant: 'secondary', onClick: open, style: { marginTop: 12 } }, __( 'Add logos', 'launch-builder' ) ),
			} )
		);

		const inspector = el( InspectorControls, null,
			el( PanelBody, { title: __( 'Content', 'launch-builder' ), initialOpen: true },
				select( 'type', __( 'Layout', 'launch-builder' ), [ [ 'badge', 'Badge' ], [ 'card', 'Card' ], [ 'pill', 'Pill' ], [ 'stacked', 'Stacked' ] ] ),
				range( 'rating', __( 'Rating', 'launch-builder' ), 0, a.starCount || 5, 0.1 ),
				toggle( 'showRating', __( 'Show rating number', 'launch-builder' ) ),
				input( 'text', __( 'Caption', 'launch-builder' ), __( 'e.g. “887+ Combined Reviews”', 'launch-builder' ) ),
				input( 'link', __( 'Link (optional)', 'launch-builder' ), __( 'Makes the score, stars and caption clickable.', 'launch-builder' ), 'url' ),
				a.link ? toggle( 'newTab', __( 'Open in new tab', 'launch-builder' ) ) : null
			),
			el( PanelBody, { title: __( 'Shape & spacing', 'launch-builder' ), initialOpen: false },
				select( 'shape', __( 'Corners', 'launch-builder' ), [ [ 'sharp', 'Sharp' ], [ 'soft', 'Soft' ], [ 'round', 'Round' ] ] ),
				select( 'density', __( 'Spacing', 'launch-builder' ), [ [ 'compact', 'Compact' ], [ 'default', 'Default' ], [ 'airy', 'Airy' ] ] ),
				select( 'starStyle', __( 'Star shape', 'launch-builder' ), [ [ 'sharp', 'Sharp' ], [ 'rounded', 'Rounded' ] ] )
			),
			el( PanelBody, { title: __( 'Stars', 'launch-builder' ), initialOpen: false },
				range( 'starCount', __( 'Number of stars', 'launch-builder' ), 1, 10 ),
				range( 'starSize', __( 'Size (px)', 'launch-builder' ), 10, 80 ),
				range( 'starGap', __( 'Gap (px)', 'launch-builder' ), 0, 24 )
			),
			el( PanelColorSettings, {
				title: __( 'Star colors', 'launch-builder' ),
				initialOpen: false,
				colorSettings: [
					{ label: __( 'Filled', 'launch-builder' ), value: a.starColor, onChange: ( v ) => set( { starColor: v || defaults.starColor.default } ) },
					{ label: __( 'Empty', 'launch-builder' ), value: a.starEmpty, onChange: ( v ) => set( { starEmpty: v || defaults.starEmpty.default } ) },
				],
			} ),
			el( PanelBody, { title: __( 'Logos', 'launch-builder' ), initialOpen: false },
				logoRows,
				addLogos,
				el( 'div', { style: { height: 16 } } ),
				select( 'logoPosition', __( 'Position', 'launch-builder' ), [ [ 'start', 'Before' ], [ 'end', 'After' ], [ 'top', 'Above' ], [ 'bottom', 'Below' ] ] ),
				range( 'logoHeight', __( 'Height (px)', 'launch-builder' ), 12, 120 ),
				range( 'logoGap', __( 'Space between logos (px, negative overlaps)', 'launch-builder' ), -40, 60 ),
				select( 'logoStyle', __( 'Treatment', 'launch-builder' ), [ [ 'original', 'Original colors' ], [ 'grayscale', 'Grayscale' ], [ 'grayscale-hover', 'Grayscale, color on hover' ] ] ),
				toggle( 'logoDivider', __( 'Divider after logos', 'launch-builder' ) )
			),
			el( PanelBody, { title: __( 'Typography', 'launch-builder' ), initialOpen: false },
				range( 'ratingSize', __( 'Rating size (px)', 'launch-builder' ), 12, 100 ),
				select( 'ratingWeight', __( 'Rating weight', 'launch-builder' ), WEIGHTS ),
				range( 'textSize', __( 'Caption size (px)', 'launch-builder' ), 10, 40 ),
				select( 'textWeight', __( 'Caption weight', 'launch-builder' ), WEIGHTS )
			),
			el( PanelColorSettings, {
				title: __( 'Text colors', 'launch-builder' ),
				initialOpen: false,
				colorSettings: [
					{ label: __( 'Rating', 'launch-builder' ), value: a.ratingColor, onChange: ( v ) => set( { ratingColor: v || defaults.ratingColor.default } ) },
					{ label: __( 'Caption', 'launch-builder' ), value: a.textColor, onChange: ( v ) => set( { textColor: v || defaults.textColor.default } ) },
				],
			} ),
			el( PanelBody, { title: __( 'Layout', 'launch-builder' ), initialOpen: false },
				select( 'contentAlign', __( 'Alignment', 'launch-builder' ), [ [ 'start', 'Left' ], [ 'center', 'Center' ], [ 'end', 'Right' ] ] ),
				range( 'gap', __( 'Gap between logos and score (px)', 'launch-builder' ), 0, 80 ),
				range( 'maxWidth', __( 'Max width (px, 0 = auto)', 'launch-builder' ), 0, 1600, 10 )
			),
			el( PanelBody, { title: __( 'Responsive', 'launch-builder' ), initialOpen: false },
				select( 'breakpoint', __( 'Switch when the widget is narrower than', 'launch-builder' ), [ [ '360', '360px' ], [ '480', '480px' ], [ '640', '640px' ], [ '768', '768px' ] ] ),
				select( 'mobile', __( 'Behavior', 'launch-builder' ), [ [ 'stack', 'Stack logos above the score' ], [ 'hide-logos', 'Hide logos' ], [ 'keep', 'Keep layout, scale down' ] ] ),
				range( 'mobileScale', __( 'Scale when narrow (%)', 'launch-builder' ), 40, 100 )
			)
		);

		return el( 'div', blockProps, inspector, el( ServerSideRender, { block: name, attributes: a, skipBlockSupportAttributes: true } ) );
	}

	const star = ( x ) => el( 'path', { key: x, d: 'M' + x + ' 4.2l1.1 2.3 2.5.3-1.8 1.8.5 2.5-2.3-1.2-2.3 1.2.5-2.5-1.8-1.8 2.5-.3z' } );
	const icon = el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, fill: 'currentColor' }, star( 5 ), star( 12 ), star( 19 ), el( 'rect', { key: 'l', x: 4, y: 15, width: 16, height: 2.5, rx: 1.25 } ), el( 'rect', { key: 'l2', x: 7, y: 19, width: 10, height: 2, rx: 1, opacity: 0.55 } ) );
	registerBlockType( 'launch/rating', { icon, edit: Edit, save: () => null } );
} )( window.wp );
