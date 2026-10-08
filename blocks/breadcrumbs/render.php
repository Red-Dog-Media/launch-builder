<?php
/**
 * Breadcrumbs: front-end render.
 */
defined( 'ABSPATH' ) || exit;

$lb_trail = \LaunchBuilder\Breadcrumbs::render();
if ( '' !== $lb_trail ) {
	echo '<div ' . get_block_wrapper_attributes( [ 'class' => 'lb-crumbs-wrap' ] ) . '>' . $lb_trail . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Breadcrumbs::render().
}
