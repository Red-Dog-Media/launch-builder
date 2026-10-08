<?php
/**
 * Services / Areas list: front-end render. Variables: $attributes, $content, $block.
 */
defined( 'ABSPATH' ) || exit;

$lb_html = \LaunchBuilder\Entries::render( $attributes );
if ( '' !== $lb_html ) {
	echo '<div ' . get_block_wrapper_attributes() . '>' . $lb_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Entries::render.
}
