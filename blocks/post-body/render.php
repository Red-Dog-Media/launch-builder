<?php
/**
 * Entry text: front-end render. Prints the text of the Service / Service Area being shown through its template.
 */
defined( 'ABSPATH' ) || exit;

echo \LaunchBuilder\Entries::post_body(); // phpcs:ignore WordPress.Security.EscapeOutput -- block output rendered by do_blocks.
