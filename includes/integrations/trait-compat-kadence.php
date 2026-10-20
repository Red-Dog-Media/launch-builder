<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Compat, one part: Kadence: no theme microdata next to Rank Math's JSON-LD, and the hero's first image loads first.
 *
 * Methods of Compat (class-compat.php), which holds the constants and the per-request state every part shares; split
 * by integration so each file reads on its own. Code here runs as Compat: self:: and __CLASS__ are Compat.
 */
trait Compat_Kadence {

	/* ------------------------------------------------------------------ Kadence */

	/** No Kadence microdata while Rank Math outputs JSON-LD (two descriptions of one page disagree). */
	public static function kadence_microdata( $on ) {
		return self::rm_active() ? false : $on;
	}

	public static function hero_open(): void {
		self::$in_hero = true;
	}

	public static function hero_close(): void {
		self::$in_hero = false;
	}

	/** The first image printed in the hero (kadence_hero_header) loads eagerly with high fetch priority (LCP). */
	public static function hero_image( $html ) {
		if ( ! self::$in_hero || self::$hero_done || ! is_string( $html ) || false === stripos( $html, '<img' ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$t = new \WP_HTML_Tag_Processor( $html );
		if ( ! $t->next_tag( 'img' ) ) {
			return $html;
		}
		self::$hero_done = true;
		if ( 'lazy' === strtolower( (string) $t->get_attribute( 'loading' ) ) ) {
			$t->remove_attribute( 'loading' );
		}
		if ( null === $t->get_attribute( 'fetchpriority' ) ) {
			$t->set_attribute( 'fetchpriority', 'high' );
		}
		return $t->get_updated_html();
	}
}
