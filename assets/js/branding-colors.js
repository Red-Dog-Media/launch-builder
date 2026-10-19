// Launch Builder > Branding > Dashboard colours: the colour maths, the ready-made schemes and the live preview.
// The maths below is the same as Branding_Colors::resolve() in PHP (the unit tests compare the two), so what the preview
// shows is what the dashboard gets. Plain JavaScript; loaded on the Branding screen only. Data comes from
// window.lbBrandingColors (schemes, the defaults of this WordPress version, page colour, strings), set by Branding_Admin::assets().
( function ( root ) {
	'use strict';

	var MIN_TEXT = 4.5, MIN_UI = 3;
	var KEYS = [ 'sidebar', 'sidebar_text', 'highlight', 'topbar', 'accent' ];
	var DEFAULTS = { sidebar: '#1E1E1E', sidebar_text: '#FFFFFF', highlight: '#3858E9', topbar: '#1E1E1E', accent: '#3858E9' };
	var PAGE = '#F0F0F1';

	/* ------------------------------------------------------------------ colour maths */

	function hex( c ) {
		c = String( c || '' ).trim().toLowerCase();
		if ( /^#[0-9a-f]{3}$/.test( c ) ) { c = '#' + c[ 1 ] + c[ 1 ] + c[ 2 ] + c[ 2 ] + c[ 3 ] + c[ 3 ]; }
		return /^#[0-9a-f]{6}$/.test( c ) ? c.toUpperCase() : null;
	}
	function lum( h ) {
		var l = 0, w = [ 0.2126, 0.7152, 0.0722 ];
		for ( var i = 0; i < 3; i++ ) {
			var v = parseInt( h.substr( 1 + i * 2, 2 ), 16 ) / 255;
			l += w[ i ] * ( v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 ) );
		}
		return l;
	}
	function ratio( a, b ) {
		var x = lum( a ), y = lum( b );
		return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
	}
	function shown( r ) { return ( Math.floor( r * 100 + 1e-9 ) / 100 ).toFixed( 2 ); }
	function blend( over, base, alpha ) {
		var out = '#';
		for ( var i = 0; i < 3; i++ ) {
			// PHP's round() settles a value to 15 significant digits first (so 80.4999999999999 becomes 80.5 and rounds up).
			var v = Math.round( Number( ( parseInt( over.substr( 1 + i * 2, 2 ), 16 ) * alpha + parseInt( base.substr( 1 + i * 2, 2 ), 16 ) * ( 1 - alpha ) ).toPrecision( 15 ) ) );
			out += ( '0' + v.toString( 16 ) ).slice( -2 );
		}
		return out.toUpperCase();
	}
	function readable( fg, bg, min ) {
		min = min || MIN_TEXT;
		if ( ratio( fg, bg ) >= min ) { return fg; }
		var target = ratio( '#FFFFFF', bg ) >= ratio( '#000000', bg ) ? '#FFFFFF' : '#000000';
		for ( var i = 1; i <= 20; i++ ) {
			var c = blend( target, fg, i / 20 );
			if ( ratio( c, bg ) >= min ) { return c; }
		}
		return target;
	}
	function on( bg, min ) {
		min = min || MIN_TEXT;
		var cands = [ '#FFFFFF', '#1D2327', '#000000' ];
		for ( var i = 0; i < cands.length; i++ ) {
			if ( ratio( cands[ i ], bg ) >= min ) { return cands[ i ]; }
		}
		return ratio( '#FFFFFF', bg ) >= ratio( '#000000', bg ) ? '#FFFFFF' : '#000000';
	}
	function darken( h, amount ) { return blend( '#000000', h, amount ); }
	function deepen( h, amount ) { return darken( h, lum( h ) < 0.35 ? amount : amount / 2 ); }

	// The same steps as Branding_Colors::resolve(). `notes` holds codes (the PHP side words them).
	function resolve( input ) {
		var set = {}, c = {}, adj = [], notes = [], r = {};
		KEYS.forEach( function ( k ) { set[ k ] = hex( ( input || {} )[ k ] ) || ''; c[ k ] = set[ k ] || DEFAULTS[ k ]; } );
		var sb = c.sidebar;
		if ( '' === set.sidebar_text ) { c.sidebar_text = on( sb ); }
		var st = readable( c.sidebar_text, sb );
		r.sidebar_text = ratio( st, sb );
		if ( st !== c.sidebar_text ) { adj.push( 'sidebar_text' ); notes.push( 'sidebar_text' ); }
		var sub = deepen( sb, 0.22 );
		var subText = readable( blend( st, sub, 0.78 ), sub );
		var subHover = readable( st, sub );
		var hl = c.highlight, hlText = on( hl );
		r.active_text = ratio( hlText, hl );
		r.active_on_sidebar = ratio( hl, sb );
		if ( r.active_on_sidebar < MIN_UI && ( '' !== set.highlight || '' !== set.sidebar ) ) { notes.push( 'active_on_sidebar' ); }
		var bubbleOn = on( sub );
		var tb = c.topbar, tbText = on( tb );
		r.bar_text = ratio( tbText, tb );
		var tbHover = deepen( tb, 0.25 ), tbHoverText = on( tbHover );
		var tbSubText = readable( blend( tbHoverText, tbHover, 0.78 ), tbHover );
		var acc = c.accent, accText = on( acc );
		r.button_text = ratio( accText, acc );
		var accHover = darken( acc, 0.12 ), accActive = darken( acc, 0.24 );
		var link = readable( acc, PAGE );
		r.link = ratio( link, PAGE );
		if ( link !== acc ) { adj.push( 'accent' ); notes.push( 'link' ); }
		r.button_on_page = ratio( acc, PAGE );
		if ( r.button_on_page < MIN_UI ) { notes.push( 'button_on_page' ); }
		var v = {
			sidebar: sb, sidebar_text: st, sub: sub, sub_text: subText, sub_hover: subHover,
			highlight: hl, highlight_text: hlText, bubble_on: bubbleOn,
			topbar: tb, bar_text: tbText, bar_hover: tbHover, bar_hover_text: tbHoverText, bar_sub_text: tbSubText,
			accent: acc, accent_text: accText, accent_hover: accHover, accent_hover_text: on( accHover ), accent_active: accActive, accent_active_text: on( accActive ),
			link: link, link_hover: darken( link, 0.2 ), link_d10: darken( link, 0.1 )
		};
		return { colors: c, v: v, ratios: r, notes: notes, adjusted: adj };
	}

	var api = { hex: hex, ratio: ratio, shown: shown, blend: blend, readable: readable, on: on, darken: darken, deepen: deepen, resolve: resolve, KEYS: KEYS, DEFAULTS: DEFAULTS, PAGE: PAGE };
	root.lbColors = api;
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }

	/* ------------------------------------------------------------------ the card */

	var D = root.lbBrandingColors, doc = root.document;
	if ( D && D.defaults ) { DEFAULTS = D.defaults; api.DEFAULTS = DEFAULTS; } // The default look depends on the WordPress version.
	if ( ! D || ! doc || ! doc.getElementById ) { return; }
	var form = doc.getElementById( 'lbb-form' ), mock = doc.getElementById( 'lbb-adm' ), card = doc.getElementById( 'lbb-colors' );
	if ( ! form || ! mock || ! card ) { return; }
	var i18n = D.i18n || {};
	var field = function ( key ) { return doc.getElementById( 'lbb-admin-colors-' + key.replace( /_/g, '-' ) ); };
	var read = function () {
		var o = {};
		KEYS.forEach( function ( k ) { var f = field( k ); o[ k ] = f ? f.value : ''; } );
		return o;
	};
	var PROPS = { '--a-side': 'sidebar', '--a-side-text': 'sidebar_text', '--a-sub': 'sub', '--a-hl': 'highlight', '--a-hl-text': 'highlight_text', '--a-bar': 'topbar', '--a-bar-text': 'bar_text', '--a-acc': 'accent', '--a-acc-text': 'accent_text', '--a-link': 'link' };
	// label, minimum, ratio key, the note that marks it as corrected
	var ROWS = [ [ 'sidebar_text', MIN_TEXT, 'sidebar_text', 'sidebar_text' ], [ 'active_text', MIN_TEXT, 'active_text', '' ], [ 'bar_text', MIN_TEXT, 'bar_text', '' ], [ 'link', MIN_TEXT, 'link', 'link' ], [ 'button_text', MIN_TEXT, 'button_text', '' ], [ 'active_on_sidebar', MIN_UI, 'active_on_sidebar', '' ] ];

	// The list is redrawn on every keystroke, but a screen reader is told only when a status changes (and only about what needs attention).
	var lastSig = null, statusEl = doc.getElementById( 'lbb-colors-status' );

	function badges( res ) {
		var list = doc.getElementById( 'lbb-colors-contrast' );
		if ( ! list ) { return; }
		list.textContent = '';
		var sig = [], attention = [];
		ROWS.forEach( function ( row ) {
			if ( 'active_on_sidebar' === row[ 0 ] && res.notes.indexOf( 'active_on_sidebar' ) < 0 && res.ratios.active_on_sidebar < MIN_UI ) { return; } // WordPress's own default pair: nothing to correct.
			var val = res.ratios[ row[ 2 ] ], ok = val >= row[ 1 ], fixed = row[ 3 ] && res.notes.indexOf( row[ 3 ] ) > -1;
			var li = doc.createElement( 'li' );
			li.className = ok ? ( fixed ? 'lbb-fixed' : 'lbb-ok' ) : 'lbb-low';
			var word = ok ? ( fixed ? ( i18n.fixed || 'Corrected' ) : ( i18n.ok || 'OK' ) ) : ( i18n.low || 'Low contrast' );
			li.textContent = ( i18n[ row[ 0 ] ] || row[ 0 ] ) + ': ' + shown( val ) + ':1 (' + word + ')';
			list.appendChild( li );
			sig.push( row[ 0 ] + '=' + li.className );
			if ( ! ok || fixed ) { attention.push( ( i18n[ row[ 0 ] ] || row[ 0 ] ) + ': ' + word ); }
		} );
		sig = sig.join( '|' );
		if ( statusEl && null !== lastSig && sig !== lastSig ) {
			statusEl.textContent = attention.length ? attention.join( '. ' ) + '.' : ( i18n.all_ok || 'All text is easy to read.' );
		}
		lastSig = sig;
	}

	// The chosen colours match a ready-made scheme exactly: its id, else '' (custom or none).
	function matching( vals ) {
		var ids = Object.keys( D.schemes || {} );
		for ( var i = 0; i < ids.length; i++ ) {
			var s = D.schemes[ ids[ i ] ];
			if ( KEYS.every( function ( k ) { return ( hex( vals[ k ] ) || '' ) === s[ k ].toUpperCase(); } ) ) { return ids[ i ]; }
		}
		return '';
	}

	function refresh() {
		var vals = read(), res = resolve( vals ), id = matching( vals );
		Object.keys( PROPS ).forEach( function ( p ) { mock.style.setProperty( p, res.v[ PROPS[ p ] ] ); } );
		badges( res );
		var scheme = field( 'scheme' );
		if ( scheme ) { scheme.value = id; }
		Array.prototype.forEach.call( card.querySelectorAll( '.lbb-scheme' ), function ( b ) { b.setAttribute( 'aria-pressed', b.getAttribute( 'data-scheme' ) === id ? 'true' : 'false' ); } );
	}

	function choose( id ) {
		var s = ( D.schemes || {} )[ id ];
		if ( ! s ) { return; }
		KEYS.forEach( function ( k ) {
			var f = field( k );
			if ( ! f ) { return; }
			f.value = s[ k ].toUpperCase();
			var sw = f.parentNode.querySelector( '.lbb-swatch' );
			if ( sw ) { sw.value = s[ k ].toLowerCase(); }
		} );
		form.dispatchEvent( new root.Event( 'input', { bubbles: true } ) ); // The unsaved-changes reminder listens for this.
		refresh();
	}

	card.addEventListener( 'click', function ( e ) {
		var t = e.target && e.target.closest ? e.target.closest( '.lbb-scheme, .lbb-auto' ) : null;
		if ( ! t ) { return; }
		if ( t.classList.contains( 'lbb-scheme' ) ) { choose( t.getAttribute( 'data-scheme' ) ); } else { refresh(); }
	} );
	card.addEventListener( 'input', refresh );
	card.addEventListener( 'change', refresh );
	refresh();
}( typeof window !== 'undefined' ? window : this ) );
