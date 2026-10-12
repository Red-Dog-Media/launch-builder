<?php
/**
 * Rating block front-end render. Variables: $attributes, $content, $block.
 */
defined( 'ABSPATH' ) || exit;

echo \LaunchBuilder\Rating::render( $attributes, $block ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
