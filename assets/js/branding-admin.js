// Launch Builder > Branding: media pickers, colour fields, the live sign-in preview and a reminder about unsaved
// changes. Plain JavaScript on the wp.* globals; loaded on that screen only. Data comes from window.lbBranding
// (palette, logo URLs, strings), set by Branding_Admin::assets().
( function ( wp, D ) {
	'use strict';
	if ( ! D ) {
		return;
	}
	var form = document.getElementById( 'lbb-form' );
	if ( ! form ) {
		return;
	}
	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };
	var i18n = D.i18n || {};
	var pal = D.palette || {};

	/* ------------------------------------------------------------------ colours */

	function hex( c ) {
		c = String( c || '' ).trim().toLowerCase();
		if ( ! c ) { return null; }
		var m = /^palette(\d{1,2})$/.exec( c );
		if ( m ) { return hex( pal[ 'palette' + m[ 1 ] ] ); }
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
		a = hex( a ); b = hex( b );
		if ( ! a || ! b ) { return 1; }
		var x = lum( a ), y = lum( b );
		return Math.round( ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 ) * 100 ) / 100;
	}
	function blend( over, base, alpha ) {
		var o = hex( over ), b = hex( base ), out = '#';
		for ( var i = 0; i < 3; i++ ) {
			var v = Math.round( parseInt( o.substr( 1 + i * 2, 2 ), 16 ) * alpha + parseInt( b.substr( 1 + i * 2, 2 ), 16 ) * ( 1 - alpha ) );
			out += ( '0' + v.toString( 16 ) ).slice( -2 );
		}
		return out.toUpperCase();
	}
	function best( surface, cands, min ) {
		var top = cands[ 0 ], topr = 0;
		for ( var i = 0; i < cands.length; i++ ) {
			var r = ratio( cands[ i ], surface );
			if ( r >= min ) { return cands[ i ]; }
			if ( r > topr ) { top = cands[ i ]; topr = r; }
		}
		return top;
	}

	var field = function ( key ) { return $( '#lbb-login-' + key.replace( /_/g, '-' ) ); };
	var val = function ( key ) { return field( key ) ? field( key ).value.trim() : ''; };

	// The same rules as Branding_Chrome::login_vars(): palette defaults, then the contrast guard.
	function compute() {
		var p = function ( slug ) { return hex( pal[ slug ] ) || '#FFFFFF'; };
		var pick = function ( key, slug ) { return hex( val( key ) ) || p( slug ); };
		var bg = pick( 'bg', 'palette8' ), form = pick( 'form_bg', 'palette9' ), text = pick( 'text', 'palette3' );
		var btn = pick( 'button_bg', 'palette1' ), hover = pick( 'button_hover_bg', 'palette2' ), link = pick( 'link', 'palette1' );
		var out = { notes: [] }, r = {};
		var hasImage = parseInt( ( field( 'bg_image' ) || {} ).value, 10 ) > 0;
		r.tf = ratio( text, form );
		if ( r.tf < 4.5 ) { text = best( form, [ p( 'palette3' ), '#1D2327', '#FFFFFF' ], 4.5 ); r.tf = ratio( text, form ); out.notes.push( 'tf' ); }
		var btnText = hex( val( 'button_text' ) ) || best( btn, [ p( 'palette9' ), p( 'palette3' ), '#FFFFFF', '#111111' ], 4.5 );
		r.bt = ratio( btnText, btn );
		if ( r.bt < 4.5 ) { btnText = best( btn, [ p( 'palette9' ), p( 'palette3' ), '#FFFFFF', '#111111' ], 4.5 ); r.bt = ratio( btnText, btn ); out.notes.push( 'bt' ); }
		r.bf = ratio( btn, form );
		if ( ratio( hover, form ) < 3 ) { hover = blend( '#000000', btn, 0.25 ); }
		var hoverText = btnText;
		if ( ratio( hoverText, hover ) < 4.5 ) { hoverText = best( hover, [ p( 'palette9' ), p( 'palette3' ), '#FFFFFF', '#111111' ], 4.5 ); }
		var linkSurface = hasImage ? form : bg;
		r.lp = ratio( link, linkSurface );
		if ( r.lp < 4.5 ) { link = best( linkSurface, [ p( 'palette2' ), p( 'palette3' ), '#1D2327', '#FFFFFF' ], 4.5 ); r.lp = ratio( link, linkSurface ); out.notes.push( 'lp' ); }
		var name = hasImage ? text : best( bg, [ text, p( 'palette3' ), '#1D2327', '#FFFFFF' ], 4.5 );
		out.vars = { '--lbb-bg': bg, '--lbb-form': form, '--lbb-text': text, '--lbb-btn': btn, '--lbb-btn-text': btnText, '--lbb-hover': hover, '--lbb-hover-text': hoverText, '--lbb-link': link, '--lbb-name': name, '--lbb-chip': hasImage ? form : 'transparent' };
		out.ratios = r;
		return out;
	}

	/* ------------------------------------------------------------------ preview */

	var mock = $( '#lbb-mock' ), logoA = $( '#lbb-mock-logo' );
	var custom = { logo: D.logos.custom || '', bg: D.logos.bg || '' };

	function logoUrl() {
		var src = ( $( '#lbb-login-logo-source' ) || {} ).value || 'client';
		var order = { client: [ 'client', 'agency' ], agency: [ 'agency', 'client' ], custom: [ 'custom', 'client', 'agency' ] }[ src ] || [ 'client', 'agency' ];
		var urls = { client: D.logos.client, agency: D.logos.agency, custom: custom.logo };
		for ( var i = 0; i < order.length; i++ ) {
			if ( urls[ order[ i ] ] ) { return urls[ order[ i ] ]; }
		}
		return '';
	}

	function applyLogo() {
		if ( ! logoA ) { return; }
		var url = logoUrl(), width = parseInt( ( field( 'logo_width' ) || {} ).value, 10 ) || 200;
		width = Math.max( 80, Math.min( 320, width ) );
		if ( ! url ) {
			logoA.textContent = D.names.business;
			logoA.style.cssText = 'background-image:none;text-indent:0;overflow:visible;width:auto;height:auto;font-size:24px;line-height:1.3;font-weight:600;color:var(--lbb-name);text-decoration:none;margin:0 auto 20px';
			return;
		}
		var img = new Image();
		img.onload = function () {
			var h = Math.round( width * ( img.naturalHeight || 1 ) / ( img.naturalWidth || 1 ) );
			if ( h > 160 ) { width = Math.round( width * 160 / h ); h = 160; }
			logoA.style.cssText = 'background-image:url("' + url.replace( /"/g, '%22' ) + '");background-size:contain;background-position:center;background-repeat:no-repeat;width:' + width + 'px;max-width:100%;height:' + Math.max( 24, h ) + 'px;margin:0 auto 24px;text-indent:-9999px;overflow:hidden';
		};
		img.src = url;
	}

	function applyBackground() {
		if ( ! mock ) { return; }
		var id = parseInt( ( field( 'bg_image' ) || {} ).value, 10 ) || 0;
		if ( id && custom.bg ) {
			var o = Math.max( 0, Math.min( 80, parseInt( ( field( 'bg_overlay' ) || {} ).value, 10 ) || 0 ) ) / 100;
			mock.style.backgroundImage = 'linear-gradient(rgba(0,0,0,' + o + '),rgba(0,0,0,' + o + ')),url("' + custom.bg.replace( /"/g, '%22' ) + '")';
			mock.style.backgroundSize = 'cover';
			mock.style.backgroundPosition = 'center';
		} else {
			mock.style.backgroundImage = 'none';
		}
	}

	function badges( c ) {
		var list = $( '#lbb-contrast' );
		if ( ! list ) { return; }
		var rows = [ [ 'bt', 4.5, c.ratios.bt ], [ 'tf', 4.5, c.ratios.tf ], [ 'lp', 4.5, c.ratios.lp ], [ 'bf', 3, c.ratios.bf ] ];
		list.textContent = '';
		rows.forEach( function ( r ) {
			var li = document.createElement( 'li' ), ok = r[ 2 ] >= r[ 1 ], fixed = c.notes.indexOf( r[ 0 ] ) > -1;
			li.className = ok ? ( fixed ? 'lbb-fixed' : 'lbb-ok' ) : 'lbb-low';
			li.textContent = ( i18n[ r[ 0 ] ] || r[ 0 ] ) + ': ' + r[ 2 ] + ':1 ' + ( ok ? ( fixed ? '(' + ( i18n.fixed || 'Corrected' ) + ')' : '(' + ( i18n.ok || 'OK' ) + ')' ) : '(' + ( i18n.low || 'Low contrast' ) + ')' );
			list.appendChild( li );
		} );
	}

	function refresh() {
		if ( ! mock ) { return; }
		var c = compute();
		Object.keys( c.vars ).forEach( function ( k ) { mock.style.setProperty( k, c.vars[ k ] ); } );
		var msg = $( '#lbb-mock-message' ), m = ( $( '#lbb-login-message' ) || {} ).value || '';
		if ( msg ) { msg.textContent = m; msg.hidden = ! m; }
		var credit = $( '#lbb-mock-credit' ), cb = $( '#lbb-login-credit' );
		if ( credit && cb ) { credit.hidden = ! cb.checked; }
		var back = $( '#backtoblog', mock ), hb = $( '#lbb-login-hide-back-link' );
		if ( back && hb ) { back.style.display = hb.checked ? 'none' : ''; }
		applyBackground();
		badges( c );
	}

	/* ------------------------------------------------------------------ colour fields */

	$$( '.lbb-color' ).forEach( function ( box ) {
		var text = $( '.lbb-hex', box ), sw = $( '.lbb-swatch', box ), auto = box.getAttribute( 'data-auto' );
		function sync() {
			var h = hex( text.value ) || hex( auto ) || '#FFFFFF';
			sw.value = h.toLowerCase();
		}
		sw.addEventListener( 'input', function () { text.value = sw.value.toUpperCase(); refresh(); mark(); } );
		text.addEventListener( 'input', function () { sync(); refresh(); } );
		$( '.lbb-auto', box ).addEventListener( 'click', function () { text.value = ''; sync(); refresh(); mark(); } );
	} );

	/* ------------------------------------------------------------------ media */

	$$( '.lbb-media' ).forEach( function ( box ) {
		var input = $( 'input[type=hidden]', box ), thumb = $( '.lbb-thumb', box ), pick = $( '.lbb-pick', box ), clear = $( '.lbb-clear', box ), path = box.getAttribute( 'data-path' );
		var frame = null;
		function set( id, url, large ) {
			input.value = id || 0;
			thumb.textContent = '';
			if ( url ) {
				var im = document.createElement( 'img' );
				im.src = url; im.alt = '';
				thumb.appendChild( im );
			}
			pick.textContent = id ? ( i18n.change || 'Change image' ) : ( i18n.choose || 'Choose image' );
			clear.hidden = ! id;
			if ( path === 'login.logo' ) { custom.logo = large || ''; applyLogo(); }
			if ( path === 'login.bg_image' ) { custom.bg = large || ''; }
			refresh();
			mark();
		}
		pick.addEventListener( 'click', function () {
			if ( ! wp || ! wp.media ) { return; }
			if ( ! frame ) {
				frame = wp.media( { title: i18n.choose, button: { text: i18n.use }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON(), s = a.sizes || {};
					set( a.id, ( s.thumbnail || {} ).url || a.url, ( s.large || {} ).url || a.url );
				} );
			}
			frame.open();
		} );
		clear.addEventListener( 'click', function () { set( 0, '', '' ); } );
	} );

	/* ------------------------------------------------------------------ unsaved changes */

	var dirty = false, note = $( '.lbb-dirty' );
	function mark() {
		if ( dirty ) { return; }
		dirty = true;
		if ( note ) { note.textContent = i18n.unsaved || ''; note.hidden = false; }
	}
	form.addEventListener( 'input', mark );
	form.addEventListener( 'change', function () { mark(); refresh(); applyLogo(); } );
	form.addEventListener( 'input', function ( e ) {
		if ( e.target && /^lbb-login-(message|logo-width|bg-overlay|bg|form-bg|text|button|link)/.test( e.target.id || '' ) ) { refresh(); applyLogo(); }
	} );
	form.addEventListener( 'submit', function () { dirty = false; } );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) { e.preventDefault(); e.returnValue = ''; }
	} );

	refresh();
	applyLogo();
	dirty = false;
	if ( note ) { note.hidden = true; }
}( window.wp, window.lbBranding ) );
