( function ( wp ) {
	const { registerBlockType, getBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PanelBody, RangeControl, ToggleControl, SelectControl, TextControl, Button, Notice, CheckboxControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __, sprintf } = wp.i18n;
	const el = wp.element.createElement;

	// Set by Rating::editor_data(): Business Info review numbers, the bundled platform logos, the legacy attribute names.
	const DATA = Object.assign( { hasGlobal: false, rating: null, count: null, platforms: [], legacy: [], businessInfoUrl: '' }, window.lbRating || {} );
	const SKIP = [ 'className', 'style', 'anchor', 'align', 'lock', 'metadata', 'backgroundColor', 'textColor', 'gradient', 'fontFamily', 'borderColor' ];
	const same = ( x, y ) => JSON.stringify( x ) === JSON.stringify( y );

	function Edit( { attributes: a, setAttributes: set, name } ) {
		const blockProps = useBlockProps();
		const defaults = getBlockType( name ).attributes;
		const isDefault = ( k ) => a[ k ] === undefined || ( 'default' in ( defaults[ k ] || {} ) && same( a[ k ], defaults[ k ].default ) );

		// What the block saves (defaults are never saved): the preview gets exactly that, so it renders like the front end.
		const saved = {};
		Object.keys( a ).forEach( ( k ) => {
			if ( defaults[ k ] && SKIP.indexOf( k ) < 0 && ! isDefault( k ) ) {
				saved[ k ] = a[ k ];
			}
		} );
		const legacy = DATA.legacy.filter( ( k ) => ! isDefault( k ) );
		const sourceRow = a.source ? DATA.platforms.find( ( p ) => p.key === a.source ) : null;
		const hasNumbers = a.source ? !! ( sourceRow && sourceRow.inBusinessInfo ) : DATA.hasGlobal;

		const select = ( key, label, options, help ) =>
			el( SelectControl, { key, label, help, value: a[ key ], options: options.map( ( o ) => ( { value: o[ 0 ], label: o[ 1 ] } ) ), onChange: ( v ) => set( { [ key ]: v } ), __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } );
		const gap = ( h ) => el( 'div', { style: { height: h || 12 } } );

		/* ---- rating + caption ---- */
		const content = el( PanelBody, { title: __( 'Rating', 'launch-builder' ), initialOpen: true },
			el( SelectControl, {
				label: __( 'Layout', 'launch-builder' ),
				value: 'stacked' === a.type ? 'card' : a.type,
				options: [ [ 'badge', __( 'Badge', 'launch-builder' ) ], [ 'card', __( 'Card', 'launch-builder' ) ], [ 'pill', __( 'Pill', 'launch-builder' ) ], [ 'stars', __( 'Stars only', 'launch-builder' ) ] ].map( ( o ) => ( { value: o[ 0 ], label: o[ 1 ] } ) ),
				onChange: ( v ) => set( { type: v } ),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			gap(),
			el( ToggleControl, {
				label: __( 'Use Business Info', 'launch-builder' ),
				help: __( 'Rating, review count and link come from Launch Builder → Business Info, so every widget updates together.', 'launch-builder' ),
				checked: !! a.useGlobal,
				onChange: ( v ) => set( { useGlobal: v, size: a.size || 'md' } ),
				__nextHasNoMarginBottom: true,
			} ),
			a.useGlobal ? gap() : null,
			a.useGlobal ? select( 'source', __( 'Numbers from', 'launch-builder' ), [ [ '', __( 'All review sites (overall)', 'launch-builder' ) ] ].concat( DATA.platforms.map( ( p ) => [ p.key, p.inBusinessInfo ? p.label : sprintf( __( '%s (not in Business Info yet)', 'launch-builder' ), p.label ) ] ) ) ) : null,
			a.useGlobal && ! hasNumbers ? el( Notice, { status: 'warning', isDismissible: false, className: 'lb-rating-notice' },
				__( 'Business Info has no review numbers for this yet, so the widget shows demo numbers. Add your review sites under Launch Builder → Business Info.', 'launch-builder' ),
				DATA.businessInfoUrl ? el( 'p', null, el( 'a', { href: DATA.businessInfoUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Open Business Info', 'launch-builder' ) ) ) : null
			) : null,
			gap(),
			a.useGlobal ? el( ToggleControl, {
				label: __( 'Type the rating by hand', 'launch-builder' ),
				checked: ! isDefault( 'rating' ),
				onChange: ( v ) => set( { rating: v ? ( null !== DATA.rating && DATA.rating !== defaults.rating.default ? DATA.rating : 5 ) : undefined } ),
				__nextHasNoMarginBottom: true,
			} ) : null,
			! a.useGlobal || ! isDefault( 'rating' ) ? el( RangeControl, { label: __( 'Rating', 'launch-builder' ), value: a.rating, min: 0, max: a.starCount || 5, step: 0.1, onChange: ( v ) => set( { rating: undefined === v ? undefined : v } ), allowReset: true, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ) : null,
			'stars' !== a.type ? gap() : null,
			'stars' !== a.type ? el( TextControl, {
				label: __( 'Caption', 'launch-builder' ),
				help: a.useGlobal ? __( 'Leave as is to use the review count from Business Info; anything you type here replaces it.', 'launch-builder' ) : __( 'e.g. “132+ Combined Reviews”. Empty = no caption.', 'launch-builder' ),
				value: a.useGlobal && isDefault( 'text' ) ? '' : a.text,
				placeholder: a.useGlobal && isDefault( 'text' ) ? __( 'From Business Info', 'launch-builder' ) : '',
				onChange: ( v ) => set( { text: a.useGlobal && '' === v ? undefined : v } ),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ) : null,
			gap(),
			el( TextControl, {
				label: __( 'Link (optional)', 'launch-builder' ),
				help: a.useGlobal && a.source ? __( 'Blank = the link of that review site in Business Info.', 'launch-builder' ) : __( 'Makes the score, stars and caption clickable.', 'launch-builder' ),
				type: 'url',
				value: a.link || '',
				onChange: ( v ) => set( { link: v } ),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} )
		);

		/* ---- platform logos ---- */
		const platforms = a.platforms || [];
		const togglePlatform = ( key, on ) => set( { platforms: on ? platforms.concat( [ key ] ) : platforms.filter( ( k ) => k !== key ), size: a.size || 'md' } );
		const platformsPanel = el( PanelBody, { title: __( 'Review site logos', 'launch-builder' ), initialOpen: false },
			el( 'p', { className: 'description' }, __( 'Round logos shown before the score, overlapping, in the order you tick them.', 'launch-builder' ) ),
			DATA.platforms.map( ( p ) =>
				el( 'div', { key: p.key, style: { display: 'flex', alignItems: 'center', gap: 8, margin: '6px 0' } },
					// The same round white badge the widget shows (each logo file carries its own space around the mark).
					el( 'span', { style: { flex: 'none', display: 'inline-flex', width: 28, height: 28, borderRadius: '50%', background: '#fff', boxShadow: '0 0 0 1px #dcdcde' } },
						el( 'img', { src: p.logo, alt: '', width: 28, height: 28, style: { display: 'block' } } )
					),
					el( CheckboxControl, { label: p.label, checked: platforms.indexOf( p.key ) >= 0, onChange: ( v ) => togglePlatform( p.key, v ), __nextHasNoMarginBottom: true } )
				)
			)
		);

		/* ---- size + alignment ---- */
		const layoutPanel = el( PanelBody, { title: __( 'Size and alignment', 'launch-builder' ), initialOpen: false },
			el( SelectControl, { label: __( 'Size', 'launch-builder' ), value: a.size || 'md', options: [ { value: 'sm', label: __( 'Small', 'launch-builder' ) }, { value: 'md', label: __( 'Medium', 'launch-builder' ) }, { value: 'lg', label: __( 'Large', 'launch-builder' ) } ], onChange: ( v ) => set( { size: v } ), __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			gap(),
			select( 'contentAlign', __( 'Alignment', 'launch-builder' ), [ [ 'start', __( 'Left', 'launch-builder' ) ], [ 'center', __( 'Center', 'launch-builder' ) ], [ 'end', __( 'Right', 'launch-builder' ) ] ] ),
			gap(),
			select( 'phoneAlign', __( 'On phones', 'launch-builder' ), [ [ 'auto', __( 'Center the content', 'launch-builder' ) ], [ 'center', __( 'Center the whole widget', 'launch-builder' ) ], [ 'match', __( 'Same as above', 'launch-builder' ) ] ], __( 'On phones the logos, score, stars and text sit in the middle of the box. A widget without a box follows the alignment above, unless you center the whole widget.', 'launch-builder' ) )
		);

		/* ---- custom (media library) logos ---- */
		const logos = a.logos || [];
		const setLogos = ( l ) => set( { logos: l } );
		const patchLogo = ( i, p ) => setLogos( logos.map( ( l, k ) => ( k === i ? Object.assign( {}, l, p ) : l ) ) );
		const moveLogo = ( i, d ) => {
			const j = i + d;
			if ( j < 0 || j >= logos.length ) {
				return;
			}
			const c = logos.slice();
			[ c[ i ], c[ j ] ] = [ c[ j ], c[ i ] ];
			setLogos( c );
		};
		const logosPanel = el( PanelBody, { title: __( 'Custom logos', 'launch-builder' ), initialOpen: false },
			logos.map( ( l, i ) =>
				el( 'div', { key: i, style: { display: 'flex', gap: 8, alignItems: 'flex-start', padding: '8px 0', borderBottom: '1px solid #e0e0e0' } },
					el( 'img', { src: l.url, alt: '', style: { width: 48, height: 48, objectFit: 'contain', background: '#f6f6f6', borderRadius: 4, flex: 'none' } } ),
					el( 'div', { style: { flex: 1, minWidth: 0 } },
						el( TextControl, { label: __( 'Alt text', 'launch-builder' ), value: l.alt || '', onChange: ( v ) => patchLogo( i, { alt: v } ), __nextHasNoMarginBottom: true } ),
						gap( 6 ),
						el( TextControl, { label: __( 'Link (optional)', 'launch-builder' ), type: 'url', value: l.link || '', onChange: ( v ) => patchLogo( i, { link: v } ), __nextHasNoMarginBottom: true } )
					),
					el( 'div', { style: { display: 'flex', flexDirection: 'column', flex: 'none' } },
						el( Button, { icon: 'arrow-up-alt2', label: __( 'Move up', 'launch-builder' ), size: 'small', disabled: 0 === i, onClick: () => moveLogo( i, -1 ) } ),
						el( Button, { icon: 'arrow-down-alt2', label: __( 'Move down', 'launch-builder' ), size: 'small', disabled: i === logos.length - 1, onClick: () => moveLogo( i, 1 ) } ),
						el( Button, { icon: 'trash', label: __( 'Remove', 'launch-builder' ), size: 'small', isDestructive: true, onClick: () => setLogos( logos.filter( ( _, k ) => k !== i ) ) } )
					)
				)
			),
			el( MediaUploadCheck, null,
				el( MediaUpload, {
					allowedTypes: [ 'image' ],
					multiple: true,
					value: logos.map( ( l ) => l.id ).filter( Boolean ),
					onSelect: ( items ) => setLogos( logos.concat( ( Array.isArray( items ) ? items : [ items ] ).map( ( m ) => ( { id: m.id, url: m.url, alt: m.alt || '', link: '' } ) ) ) ),
					render: ( { open } ) => el( Button, { variant: 'secondary', onClick: open, style: { marginTop: 12 } }, __( 'Add logos', 'launch-builder' ) ),
				} )
			)
		);

		/* ---- legacy sizing: shown only when the block carries old per-block values ---- */
		const legacyPanel = legacy.length ? el( PanelBody, { title: __( 'Legacy custom sizing', 'launch-builder' ), initialOpen: false },
			el( 'p', null, sprintf( __( 'This widget was styled with the older settings (%s). They still apply. Reset them to follow the size above and the colours around it.', 'launch-builder' ), legacy.join( ', ' ) ) ),
			el( Button, {
				variant: 'secondary',
				onClick: () => {
					const reset = { size: a.size || 'md' };
					legacy.forEach( ( k ) => {
						reset[ k ] = undefined;
					} );
					set( reset );
				},
			}, __( 'Reset', 'launch-builder' ) )
		) : null;

		return el( 'div', blockProps,
			el( InspectorControls, null, content, platformsPanel, layoutPanel, logosPanel, legacyPanel ),
			el( ServerSideRender, { block: name, attributes: saved, skipBlockSupportAttributes: true } )
		);
	}

	const star = ( x ) => el( 'path', { key: x, d: 'M' + x + ' 4.2l1.1 2.3 2.5.3-1.8 1.8.5 2.5-2.3-1.2-2.3 1.2.5-2.5-1.8-1.8 2.5-.3z' } );
	const icon = el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, fill: 'currentColor' }, star( 5 ), star( 12 ), star( 19 ), el( 'rect', { key: 'l', x: 4, y: 15, width: 16, height: 2.5, rx: 1.25 } ), el( 'rect', { key: 'l2', x: 7, y: 19, width: 10, height: 2, rx: 1, opacity: 0.55 } ) );
	registerBlockType( 'launch/rating', { icon, edit: Edit, save: () => null } );
} )( window.wp );
