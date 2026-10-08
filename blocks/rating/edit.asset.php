<?php
// Hand-written asset file (no build step). Add deps here if edit.js uses more wp.* packages.
return [
	'dependencies' => [ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ],
	'version'      => (string) @filemtime( __DIR__ . '/edit.js' ), // cache-busts the editor script whenever it changes
];
