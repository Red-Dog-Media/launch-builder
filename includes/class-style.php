<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Style DNA: the look Kadence does not own (radius, borders, shadows, section and card spacing, heading treatment,
 * eyebrows, surfaces, button variant, star colour) as CSS variables and lbd-* classes.
 *
 * The variable and class contract is fixed here; get() reads the stored option and css() returns '' (nothing is
 * printed yet).
 *
 * Contract: properties the DNA owns are NEVER written as block attributes on blocks carrying an lbd-* class; class
 * rules use :where() so a per-instance edit in the Kadence inspector still wins.
 */
final class Style {

	const OPT     = 'launch_builder_style';
	const OPT_CSS = 'launch_builder_style_css';

	/** CSS variable contract: name => what it controls. */
	const VARS = [
		'--lb-radius-card'        => 'Corner radius of cards.',
		'--lb-radius-tile'        => 'Corner radius of tiles and small boxes.',
		'--lb-radius-panel'       => 'Corner radius of panels and large boxes.',
		'--lb-radius-media'       => 'Corner radius of images and media.',
		'--lb-radius-button'      => 'Corner radius of buttons.',
		'--lb-radius-input'       => 'Corner radius of form inputs.',
		'--lb-radius-pill'        => 'Radius of pills and badges.',
		'--lb-shadow-card'        => 'Shadow of cards.',
		'--lb-shadow-raised'      => 'Shadow of raised cards and panels.',
		'--lb-shadow-button'      => 'Shadow of buttons.',
		'--lb-border-w'           => 'Border width of outlined cards and panels.',
		'--lb-border-c'           => 'Border colour of outlined cards and panels.',
		'--lb-section-y'          => 'Vertical padding of sections (feeds --global-kb-row-default-top/bottom on .lbd-sec).',
		'--lb-heading-transform'  => 'Heading text-transform.',
		'--lb-heading-weight'     => 'Heading font weight.',
		'--lb-heading-tracking'   => 'Heading letter spacing.',
		'--lb-eyebrow-*'          => 'Eyebrow style (plain, pill, line or bar): colour, background, size, tracking.',
		'--lb-surface-tint'       => 'Background of tinted sections.',
		'--lb-surface-dark'       => 'Background of dark sections.',
		'--lb-surface-brand'      => 'Background of brand-colour sections.',
		'--lb-star-color'         => 'Filled star colour (rating widget).',
	];

	/** Legacy variables still read by existing CSS, and the contract variable each one aliases. */
	const LEGACY_VARS = [
		'--lb-radius'      => '--lb-radius-card',
		'--lb-radius-sm'   => '--lb-radius-tile',
		'--lb-pill-radius' => '--lb-radius-pill',
	];

	/** Kadence globals Style defines (undefined on the front end without it). */
	const KADENCE_VARS = [ '--global-kb-row-default-top', '--global-kb-row-default-bottom', '--global-kb-gap-xs', '--global-kb-gap-sm', '--global-kb-gap-md', '--global-kb-gap-lg', '--global-kb-spacing-*' ];

	/** Class contract: class => meaning. */
	const CLASSES = [
		'lbd-sec'           => 'A section row: vertical padding from --lb-section-y.',
		'lbd-sec--tight'    => 'Section with less vertical padding.',
		'lbd-sec--roomy'    => 'Section with more vertical padding.',
		'lbd-sec--tint'     => 'Section on the tint surface.',
		'lbd-sec--dark'     => 'Section on the dark surface.',
		'lbd-sec--brand'    => 'Section on the brand surface.',
		'lbd-card'          => 'A card (column): radius, shadow, padding.',
		'lbd-card--flat'    => 'Card without shadow.',
		'lbd-card--raised'  => 'Card with the raised shadow.',
		'lbd-card--outline' => 'Card with a border instead of a shadow.',
		'lbd-tile'          => 'A tile (small box).',
		'lbd-panel'         => 'A panel (large box).',
		'lbd-media'         => 'An image or media block: media radius.',
		'lbd-eyebrow'       => 'The small line above a heading.',
		'lbd-title'         => 'A heading that follows the heading treatment.',
		'lbd-btn-light'     => 'A button for dark surfaces (light variant).',
		'lbd-btn-ghost'     => 'A ghost button for dark surfaces.',
		'lbd-badge'         => 'A badge or chip.',
		'lbd-coupon'        => 'A coupon or offer card.',
		'lbd-keep'          => 'Opt-out marker: Style leaves this block exactly as authored.',
		'lbd-sticky-bar'    => 'The mobile call/quote bar.',
	];

	/**
	 * The block attributes the DNA owns, by block and property group (verified against Kadence Blocks' block.json; a
	 * dotted name is a path inside the attribute, as core/image keeps its radius in style.border.radius). On a block that
	 * carries any lbd-* class other than lbd-keep, none of these is ever written: the generator (tools/gen run.js) fails a
	 * def that sets one, and the render filters drop legacy values. tools/gen/dna-owned.json is an exact copy for the
	 * Node tools (chk-phase0 fails when they differ); edit both together.
	 */
	const DNA_ATTRS = [
		'kadence/rowlayout' => [
			'radius' => [ 'borderRadius', 'tabletBorderRadius', 'mobileBorderRadius' ],
			'border' => [ 'border', 'tabletBorder', 'mobileBorder', 'borderWidth', 'tabletBorderWidth', 'mobileBorderWidth', 'borderStyle', 'tabletBorderStyle', 'mobileBorderStyle' ],
			'shadow' => [ 'displayBoxShadow', 'boxShadow' ],
			'padding' => [ 'padding', 'tabletPadding', 'mobilePadding', 'topPadding', 'bottomPadding', 'leftPadding', 'rightPadding', 'topPaddingM', 'bottomPaddingM', 'leftPaddingM', 'rightPaddingM' ],
		],
		'kadence/column' => [
			'radius' => [ 'borderRadius', 'tabletBorderRadius', 'mobileBorderRadius', 'borderHoverRadius', 'tabletBorderHoverRadius', 'mobileBorderHoverRadius' ],
			'border' => [ 'border', 'borderOpacity', 'borderWidth', 'tabletBorderWidth', 'mobileBorderWidth', 'borderStyle', 'tabletBorderStyle', 'mobileBorderStyle', 'borderHover', 'borderHoverWidth', 'tabletBorderHoverWidth', 'mobileBorderHoverWidth', 'borderHoverStyle', 'tabletBorderHoverStyle', 'mobileBorderHoverStyle' ],
			'shadow' => [ 'displayShadow', 'shadow', 'displayHoverShadow', 'shadowHover' ],
			'padding' => [ 'padding', 'tabletPadding', 'mobilePadding', 'topPadding', 'bottomPadding', 'leftPadding', 'rightPadding', 'topPaddingT', 'bottomPaddingT', 'leftPaddingT', 'rightPaddingT', 'topPaddingM', 'bottomPaddingM', 'leftPaddingM', 'rightPaddingM' ],
		],
		'kadence/infobox' => [
			'radius' => [ 'containerBorderRadius', 'borderRadius', 'tabletBorderRadius', 'mobileBorderRadius', 'borderHoverRadius', 'tabletBorderHoverRadius', 'mobileBorderHoverRadius' ],
			'border' => [ 'containerBorder', 'containerBorderOpacity', 'containerHoverBorder', 'containerHoverBorderOpacity', 'containerBorderWidth', 'borderStyle', 'tabletBorderStyle', 'mobileBorderStyle', 'borderHoverStyle', 'tabletBorderHoverStyle', 'mobileBorderHoverStyle' ],
			'shadow' => [ 'displayShadow', 'shadow', 'tabletShadow', 'mobileShadow', 'shadowHover', 'tabletShadowHover', 'mobileShadowHover' ],
			'padding' => [ 'containerPadding', 'containerTabletPadding', 'containerMobilePadding' ],
		],
		'kadence/advancedheading' => [
			'heading' => [ 'textTransform', 'fontWeight', 'letterSpacing', 'tabletLetterSpacing', 'mobileLetterSpacing' ],
			'radius' => [ 'borderRadius', 'tabletBorderRadius', 'mobileBorderRadius' ],
			'border' => [ 'borderStyle', 'tabletBorderStyle', 'mobileBorderStyle' ],
			'padding' => [ 'padding', 'tabletPadding', 'mobilePadding' ],
		],
		'kadence/singlebtn' => [
			'radius' => [ 'borderRadius', 'tabletBorderRadius', 'mobileBorderRadius', 'borderHoverRadius', 'tabletBorderHoverRadius', 'mobileBorderHoverRadius' ],
			'border' => [ 'borderStyle', 'tabletBorderStyle', 'mobileBorderStyle', 'borderHoverStyle', 'tabletBorderHoverStyle', 'mobileBorderHoverStyle' ],
			'shadow' => [ 'displayShadow', 'shadow', 'displayHoverShadow', 'shadowHover' ],
		],
		'kadence/image' => [
			'radius' => [ 'borderRadius', 'tabletBorderRadius', 'mobileBorderRadius' ],
		],
		'core/image' => [
			'radius' => [ 'style.border.radius' ],
		],
	];

	/** Presets shipped (each also names default header/footer/hero designs per role and the button variant). */
	const PRESETS = [ 'clean', 'bold', 'sharp', 'soft' ];

	/**
	 * The chrome designs the chosen preset brings (a partial chrome map, e.g. [ 'header' => [ 'default' => ... ],
	 * 'footer' => [ ... ], 'hero' => [ 'service' => ... ] ]): Chrome::get() lays it over Chrome::DEFAULT_MAP and under the
	 * stored map, so the brief's style drives the chrome. Currently none.
	 */
	public static function chrome_defaults(): array {
		return [];
	}

	/** @return array{preset:string,tokens:array} the stored style (empty preset = none chosen yet). */
	public static function get(): array {
		$s = get_option( self::OPT, [] );
		$s = is_array( $s ) ? $s : [];
		return [ 'preset' => in_array( $s['preset'] ?? '', self::PRESETS, true ) ? $s['preset'] : '', 'tokens' => is_array( $s['tokens'] ?? null ) ? $s['tokens'] : [] ];
	}

	/** The compiled CSS for the variables and classes above. Currently '' (nothing printed). */
	public static function css(): string {
		return '';
	}
}
