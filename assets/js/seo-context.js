// Rank Math content analysis for Launch Builder pages and entries. Rank Math scores only the post's own content, where
// tokens such as {{lb.city}} are still raw and the hero / entry template (Elements, synced patterns) is missing. This
// filter resolves the tokens with the values Launch Builder would print for this post and appends the role chrome
// (hero, and the service/area template) as rendered HTML. Data: GET /launch-builder/v1/seo-context/{id} (edit_post),
// loaded once; Compat::editor_assets() passes the post id. Analysis only: nothing is saved.
( function ( wp, cfg ) {
	if ( ! wp || ! wp.hooks || ! wp.apiFetch || ! cfg || ! cfg.postId ) {
		return;
	}
	let ctx = null;

	function value( key, fallback ) {
		const v = ctx && ctx.tokens ? ctx.tokens[ key ] : undefined;
		return 'string' === typeof v && '' !== v ? v : ( fallback || '' );
	}

	function resolve( content ) {
		if ( ! ctx || 'string' !== typeof content ) {
			return content;
		}
		let out = content.replace( /\{\{\s*([a-z]+\.[a-z0-9_]+)\s*(?:\|([^}]*))?\}\}/g, ( m, key, fb ) => value( key, fb ) );
		out = out.replace( /%7B%7B([a-z]+\.[a-z0-9_]+)%7D%7D/gi, ( m, key ) => value( key ) );
		return ctx.chrome_html ? out + '\n' + ctx.chrome_html : out;
	}

	wp.hooks.addFilter( 'rank_math_content', 'launch-builder/seo-context', resolve );

	wp.apiFetch( { path: '/launch-builder/v1/seo-context/' + parseInt( cfg.postId, 10 ) } )
		.then( ( r ) => {
			ctx = r && 'object' === typeof r ? r : null;
			if ( ctx && window.rankMathEditor && 'function' === typeof window.rankMathEditor.refresh ) {
				window.rankMathEditor.refresh( 'content' );
			}
		} )
		.catch( () => {} );
} )( window.wp, window.lbSeoContext );
