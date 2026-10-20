// node shots.mjs <outdir> <width> <path>...: full-page screenshots of front-end pages (logged out) at one width.
import { createRequire } from 'module';
const require = createRequire( '/opt/node22/lib/node_modules/' );
const { chromium } = require( 'playwright' );
const [ out, width, ...paths ] = process.argv.slice( 2 );
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: Number( width ), height: 900 } } );
const errors = [];
page.on( 'pageerror', e => errors.push( 'pageerror: ' + e.message ) );
page.on( 'console', m => { if ( m.type() === 'error' ) errors.push( 'console: ' + m.text() ); } );
for ( const p of paths ) {
	await page.goto( 'http://127.0.0.1:8899' + p, { waitUntil: 'networkidle', timeout: 60000 } ).catch( e => errors.push( 'goto ' + p + ': ' + e.message ) );
	await page.waitForTimeout( 800 );
	const file = `${ out }/${ width }-${ p.replace( /[^a-z0-9]+/gi, '_' ).replace( /^_|_$/g, '' ) || 'home' }.png`;
	await page.screenshot( { path: file, fullPage: true } );
	console.log( file );
}
console.log( errors.length ? 'ERRORS:\n' + [ ...new Set( errors ) ].join( '\n' ) : 'no page errors' );
await browser.close();
