<?php
/**
 * Menu links: front-end render. Variables: $attributes, $content, $block.
 */
defined( 'ABSPATH' ) || exit;

$lb_html = \LaunchBuilder\Menu_Links::render( $attributes );
if ( '' !== $lb_html ) {
	echo '<div ' . get_block_wrapper_attributes() . '>' . $lb_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Menu_Links::render.
}
