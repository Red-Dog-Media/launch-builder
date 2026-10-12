<?php
/**
 * Breadcrumbs: front-end (and ServerSideRender) render. The variant is a class on the wrapper, so a full-width band
 * (bar) or the container width (contained) is drawn around the trail.
 *
 * @var array $attributes
 */
defined( 'ABSPATH' ) || exit;

$lb_args  = \LaunchBuilder\Breadcrumbs::args( (array) ( $attributes ?? [] ) );
$lb_trail = \LaunchBuilder\Breadcrumbs::render( (array) ( $attributes ?? [] ) );
if ( '' !== $lb_trail ) {
	echo '<div ' . get_block_wrapper_attributes( [ 'class' => 'lb-crumbs-wrap lb-crumbs-wrap--' . $lb_args['variant'] ] ) . '>' . $lb_trail . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Breadcrumbs::render().
}
