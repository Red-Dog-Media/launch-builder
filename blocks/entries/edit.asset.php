<?php
// Hand-written asset file (no build step).
return [
	'dependencies' => [ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ],
	'version'      => (string) @filemtime( __DIR__ . '/edit.js' ),
];
