<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Style DNA: the look Kadence does not own (radius, borders, shadows, section and card spacing, heading treatment,
 * eyebrows, surfaces, on-dark buttons, star colour, hero overlay) as CSS variables and lbd-* classes.
 * Kadence keeps the palette, fonts, theme buttons and content width.
 *
 * Storage: option launch_builder_style { preset, tokens } (tokens = only what differs from the preset). The resolved
 * tokens are BASE < preset < stored tokens. The CSS (variables + assets/css/dna.css) is compiled once and cached in
 * launch_builder_style_css under a key made of LB_VERSION, the template's mtime and a hash of everything it reads (the
 * style option, the active Kadence palette and the theme button/dropdown mods it measures); it is never snapshotted.
 * It is printed inline on an LB handle: on the front end at wp_enqueue_scripts (after the theme's kadence-global), in
 * the block editor through enqueue_block_assets with the selector ":root, .editor-styles-wrapper", after Kadence
 * Blocks' editor globals.
 *
 * Contract: properties the DNA owns are NEVER written as block attributes on blocks carrying an lbd-* class (other than
 * lbd-keep); Kadence's attribute filters drop them at render if they are there anyway, and class rules use :where() so
 * a per-instance edit in the Kadence inspector (per-block CSS) still wins. Legacy content (blocks whose uniqueID is in
 * patterns/legacy-ids.json) keeps everything except its numeric radius, which is mapped to a DNA class by value.
 */
final class Style {

	const OPT      = 'launch_builder_style';
	const OPT_CSS  = 'launch_builder_style_css';
	const HANDLE   = 'launch-builder-style';
	const TEMPLATE = 'assets/css/dna.css';

	/** Ledger key of the theme button mods set-style (apply_buttons) and set-buttons write. */
	const LEDGER_BUTTONS = 'style-buttons';

	/** CSS variable contract: name => what it controls. */
	const VARS = [
		'--lb-radius-card'           => 'Corner radius of cards.',
		'--lb-radius-tile'           => 'Corner radius of tiles and small boxes.',
		'--lb-radius-panel'          => 'Corner radius of panels and large boxes.',
		'--lb-radius-media'          => 'Corner radius of images and media.',
		'--lb-radius-button'         => 'Corner radius of buttons.',
		'--lb-radius-input'          => 'Corner radius of form inputs.',
		'--lb-radius-pill'           => 'Radius of pills and badges.',
		'--lb-shadow-card'           => 'Shadow of cards.',
		'--lb-shadow-raised'         => 'Shadow of raised cards and panels.',
		'--lb-shadow-button'         => 'Shadow of buttons.',
		'--lb-border-w'              => 'Border width of outlined cards and panels.',
		'--lb-border-c'              => 'Border colour of outlined cards and panels.',
		'--lb-section-y'             => 'Vertical padding of sections (feeds --global-kb-row-default-top/bottom on .lbd-sec).',
		'--lb-heading-transform'     => 'Heading text-transform.',
		'--lb-heading-weight'        => 'Heading font weight.',
		'--lb-heading-tracking'      => 'Heading letter spacing.',
		'--lb-eyebrow-*'             => 'Eyebrow style (plain, pill, line or bar): colour, background, size, tracking.',
		'--lb-surface-tint'          => 'Background of tinted sections.',
		'--lb-surface-dark'          => 'Background of dark sections.',
		'--lb-surface-brand'         => 'Background of brand-colour sections.',
		'--lb-star-color'            => 'Filled star colour (rating widget).',
		'--lb-section-y-tight'       => 'Section padding of .lbd-sec--tight.',
		'--lb-section-y-roomy'       => 'Section padding of .lbd-sec--roomy.',
		'--lb-card-pad'              => 'Inner padding of cards (tiles: --lb-tile-pad, panels: --lb-panel-pad).',
		'--lb-hero-overlay-color'    => 'Overlay colour of .lbd-hero (over the hero photo or graphic).',
		'--lb-hero-overlay-opacity'  => 'Overlay strength of .lbd-hero (0-1).',
		'--lb-on-dark-*'             => 'Button colours on dark surfaces (light and ghost variants, hover, focus ring).',
		'--lb-on-{tint,dark,brand}-*' => 'Text, heading and accent colours chosen per surface for >= 4.5:1 contrast.',
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
		'lbd-hero'              => 'A hero row: the hero overlay (colour and strength tokens) over its background; buttons use the on-dark states.',
		'lbd-eyebrow--center'   => 'Eyebrow centred (pill eyebrows size to their text; this centres the pill).',
		'lbd-eyebrow--right'    => 'Eyebrow aligned right.',
		'lbd-on-dark'           => 'Added at render time to Kadence rows/columns whose own background is dark: theme buttons inside get visible hover/focus states.',
		'lbd-on-dark--swap'     => 'Added with lbd-on-dark when the theme button colour itself would vanish on that background: the button uses the on-dark variant.',
		'lbd-on-light'          => 'Added at render time to Kadence rows/columns with a light background: theme buttons inside use their own colours again.',
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
	 * Base tokens: what every site gets before a preset is chosen. They reproduce the look the shipped patterns had
	 * baked in (cards 16, tiles 20, panels 28, images 16, no card shadow), so legacy pages render the same.
	 */
	const BASE = [
		'radius'     => [ 'card' => 16, 'tile' => 20, 'panel' => 28, 'media' => 16, 'button' => 8, 'input' => 8, 'pill' => 999 ],
		'shadow'     => [ 'card' => 'none', 'raised' => 'soft', 'button' => 'none' ],
		'border'     => [ 'width' => 1, 'color' => 'palette7' ],
		'space'      => [ 'scale' => 1, 'section' => 'normal', 'card' => 'md' ],
		'type'       => [ 'scale' => 1, 'heading_transform' => 'none', 'heading_weight' => 700, 'heading_tracking' => 0, 'eyebrow' => 'plain' ],
		'surfaces'   => [ 'tint' => 'palette8', 'dark' => 'palette3', 'brand' => 'palette1' ],
		'buttons'    => [ 'variant' => '', 'on_dark' => 'light', 'weight' => 700, 'uppercase' => false, 'padding' => 'md' ],
		'hero'       => [ 'overlay_color' => 'palette3', 'overlay_strength' => 55 ],
		'star_color' => '#F5B301',
	];

	/** The role heroes the pattern library provides (Chrome::DEFAULT_MAP names the same slugs). */
	const ROLE_HEROES = [
		'default'      => 'hero-page',
		'home'         => false,
		'service'      => 'hero-service',
		'area'         => 'hero-area',
		'category'     => 'hero-category',
		'services_hub' => 'hero-services-hub',
		'areas_hub'    => 'hero-areas-hub',
		'conversion'   => 'hero-conversion',
		'thanks'       => 'hero-thanks',
		'legal'        => 'hero-page',
		'post'         => 'hero-post',
		'blog'         => 'hero-blog',
		'search'       => 'hero-search',
		'notfound'     => false,
	];

	/**
	 * Presets: tokens over BASE, plus the chrome designs they bring (a partial chrome map, layered by Chrome::get()).
	 * Every preset's default header is header-main, a MAIN-ROW-ONLY header (logo, menu, phone, call to action) that keeps
	 * one row from the desktop breakpoint up for six to eight top-level items and collapses to the mobile header below
	 * it. header-split (menu, centred logo) wraps with a long menu and is never a preset default; multi-row, top-bar and
	 * utility headers stay available but are never a preset default either. Conversion and thanks pages keep
	 * header-conversion from the default map.
	 */
	const PRESET_DEFS = [
		'clean' => [
			'label'  => 'Clean',
			'about'  => 'Light and airy: small radii, subtle card shadows, hairline borders, sentence-case headings, solid buttons.',
			'tokens' => [
				'radius'   => [ 'card' => 12, 'tile' => 12, 'panel' => 20, 'media' => 12, 'button' => 8, 'input' => 8, 'pill' => 999 ],
				'shadow'   => [ 'card' => 'subtle', 'raised' => 'soft', 'button' => 'none' ],
				'border'   => [ 'width' => 1, 'color' => 'palette7' ],
				'space'    => [ 'scale' => 1, 'section' => 'normal', 'card' => 'md' ],
				'type'     => [ 'scale' => 1, 'heading_transform' => 'none', 'heading_weight' => 700, 'heading_tracking' => -0.01, 'eyebrow' => 'plain' ],
				'buttons'  => [ 'variant' => 'solid', 'on_dark' => 'light', 'weight' => 600, 'uppercase' => false, 'padding' => 'md' ],
				'hero'     => [ 'overlay_color' => 'palette3', 'overlay_strength' => 55 ],
			],
			'chrome' => [
				'header' => [ 'default' => 'header-main' ],
				'footer' => [ 'default' => 'footer-dark', 'conversion' => 'footer-minimal' ],
				'hero'   => self::ROLE_HEROES,
			],
		],
		'bold'  => [
			'label'  => 'Bold',
			'about'  => 'Strong and confident: bigger type scale, heavy headings, roomy sections, lifted cards, chunky uppercase buttons.',
			'tokens' => [
				'radius'   => [ 'card' => 20, 'tile' => 16, 'panel' => 28, 'media' => 20, 'button' => 10, 'input' => 10, 'pill' => 999 ],
				'shadow'   => [ 'card' => 'soft', 'raised' => 'lift', 'button' => 'soft' ],
				'border'   => [ 'width' => 0, 'color' => 'palette7' ],
				'space'    => [ 'scale' => 1.1, 'section' => 'roomy', 'card' => 'lg' ],
				'type'     => [ 'scale' => 1.1, 'heading_transform' => 'none', 'heading_weight' => 800, 'heading_tracking' => -0.02, 'eyebrow' => 'pill' ],
				'buttons'  => [ 'variant' => 'solid', 'on_dark' => 'light', 'weight' => 800, 'uppercase' => true, 'padding' => 'lg' ],
				'hero'     => [ 'overlay_color' => 'palette3', 'overlay_strength' => 65 ],
			],
			'chrome' => [
				'header' => [ 'default' => 'header-main' ],
				'footer' => [ 'default' => 'footer-licensing', 'conversion' => 'footer-minimal' ],
				'hero'   => self::ROLE_HEROES,
			],
		],
		'sharp' => [
			'label'  => 'Sharp',
			'about'  => 'Squared and technical (automotive, trades, B2B): no radii, uppercase headings, hard offset shadows, flat square buttons.',
			'tokens' => [
				'radius'   => [ 'card' => 0, 'tile' => 0, 'panel' => 0, 'media' => 0, 'button' => 0, 'input' => 0, 'pill' => 2 ],
				'shadow'   => [ 'card' => 'hard', 'raised' => 'hard-lg', 'button' => 'none' ],
				'border'   => [ 'width' => 1, 'color' => 'palette6' ],
				'space'    => [ 'scale' => 1, 'section' => 'normal', 'card' => 'md' ],
				'type'     => [ 'scale' => 1.05, 'heading_transform' => 'uppercase', 'heading_weight' => 800, 'heading_tracking' => 0.01, 'eyebrow' => 'bar' ],
				'buttons'  => [ 'variant' => 'sharp', 'on_dark' => 'light', 'weight' => 700, 'uppercase' => true, 'padding' => 'md' ],
				'hero'     => [ 'overlay_color' => 'palette3', 'overlay_strength' => 60 ],
			],
			'chrome' => [
				'header' => [ 'default' => 'header-main' ],
				'footer' => [ 'default' => 'footer-dark', 'conversion' => 'footer-minimal' ],
				'hero'   => self::ROLE_HEROES,
			],
		],
		'soft'  => [
			'label'  => 'Soft',
			'about'  => 'Friendly and rounded: large radii, soft shadows, roomy sections, pill eyebrows and pill buttons.',
			'tokens' => [
				'radius'   => [ 'card' => 24, 'tile' => 20, 'panel' => 32, 'media' => 24, 'button' => 999, 'input' => 14, 'pill' => 999 ],
				'shadow'   => [ 'card' => 'soft', 'raised' => 'lift', 'button' => 'soft' ],
				'border'   => [ 'width' => 0, 'color' => 'palette7' ],
				'space'    => [ 'scale' => 1, 'section' => 'roomy', 'card' => 'lg' ],
				'type'     => [ 'scale' => 1, 'heading_transform' => 'none', 'heading_weight' => 700, 'heading_tracking' => -0.01, 'eyebrow' => 'pill' ],
				'buttons'  => [ 'variant' => 'pill', 'on_dark' => 'light', 'weight' => 700, 'uppercase' => false, 'padding' => 'md' ],
				'hero'     => [ 'overlay_color' => 'palette3', 'overlay_strength' => 45 ],
			],
			'chrome' => [
				'header' => [ 'default' => 'header-main' ],
				'footer' => [ 'default' => 'footer-light', 'conversion' => 'footer-minimal' ],
				'hero'   => self::ROLE_HEROES,
			],
		],
	];

	/** Shadow keywords (tokens.shadow.*). */
	const SHADOWS = [
		'none'    => 'none',
		'subtle'  => '0 1px 2px rgba(16,24,40,.06), 0 2px 6px -1px rgba(16,24,40,.08)',
		'soft'    => '0 10px 28px -12px rgba(16,24,40,.22)',
		'lift'    => '0 22px 44px -18px rgba(16,24,40,.34)',
		'hard'    => '6px 6px 0 0 color-mix(in srgb, var(--global-palette3) 16%, transparent)',
		'hard-lg' => '10px 10px 0 0 color-mix(in srgb, var(--global-palette3) 20%, transparent)',
	];

	/** Kadence spacing scale in rem (its own editor values), multiplied by tokens.space.scale. */
	const SPACE = [ 'xxs' => 0.5, 'xs' => 1, 'sm' => 1.5, 'md' => 2, 'lg' => 3, 'xl' => 4, 'xxl' => 5, '3xl' => 6.5, '4xl' => 8, '5xl' => 10 ];

	/** Kadence gap fallbacks in rem, multiplied by tokens.space.scale. */
	const GAPS = [ 'xs' => 0.5, 'sm' => 1, 'md' => 2, 'lg' => 4 ];

	/** Section padding ladder: tight, normal and roomy pick a step, the --tight/--roomy modifiers one step down/up. */
	const SECTION_LADDER = [ 'lg', 'xl', 'xxl', '3xl', '4xl' ];
	const SECTION_STEP   = [ 'tight' => 1, 'normal' => 2, 'roomy' => 3 ];

	/** Card padding steps (tokens.space.card); tiles use one step less, panels one step more. */
	const PAD_STEPS = [ 'xs', 'sm', 'md', 'lg', 'xl' ];

	/** Legacy radius (px, all four corners) => the DNA class that now supplies it, per block. */
	const LEGACY_ROLE = [
		'kadence/column'    => [ 16 => 'lbd-card', 20 => 'lbd-tile', 28 => 'lbd-panel' ],
		'kadence/infobox'   => [ 16 => 'lbd-card', 20 => 'lbd-tile', 28 => 'lbd-panel' ],
		'kadence/rowlayout' => [ 16 => 'lbd-card', 20 => 'lbd-tile', 28 => 'lbd-panel' ],
		'kadence/image'     => [ 12 => 'lbd-media', 16 => 'lbd-media' ],
	];

	/** core/image inline radii the shipped patterns used (stripped from images inside legacy columns). */
	const LEGACY_IMG_RADII = [ '12px', '16px' ];

	/** The class that marks a Kadence block's own wrapper (+ uniqueID). */
	const WRAPPER_CLASS = [
		'kadence/column'          => 'kadence-column',
		'kadence/rowlayout'       => 'kb-row-layout-id',
		'kadence/infobox'         => 'kt-info-box',
		'kadence/image'           => 'kb-image',
		'kadence/advancedheading' => 'kt-adv-heading',
		'kadence/singlebtn'       => 'kb-btn',
		'kadence/testimonials'    => 'kt-blocks-testimonials-wrap',
	];

	/** Every theme mod the button tools write (one Ledger row covers them all). */
	const BUTTON_MODS = [
		'buttons_color', 'buttons_background', 'buttons_border', 'buttons_border_colors', 'buttons_border_radius', 'buttons_padding', 'buttons_typography', 'buttons_shadow', 'buttons_shadow_hover',
		'buttons_secondary_color', 'buttons_secondary_background', 'buttons_secondary_border', 'buttons_secondary_border_colors', 'buttons_secondary_border_radius', 'buttons_secondary_padding', 'buttons_secondary_typography', 'buttons_secondary_shadow', 'buttons_secondary_shadow_hover',
		'buttons_outline_color', 'buttons_outline_background', 'buttons_outline_border', 'buttons_outline_border_colors', 'buttons_outline_border_radius', 'buttons_outline_padding', 'buttons_outline_typography', 'buttons_outline_shadow', 'buttons_outline_shadow_hover',
	];

	/** Kadence's default palette (used when the site has never saved one). */
	const DEFAULT_PALETTE = [ 'palette1' => '#2B6CB0', 'palette2' => '#215387', 'palette3' => '#1A202C', 'palette4' => '#2D3748', 'palette5' => '#4A5568', 'palette6' => '#718096', 'palette7' => '#EDF2F7', 'palette8' => '#F7FAFC', 'palette9' => '#FFFFFF' ];

	/** Minimum contrast: button against its surface (non-text, WCAG 1.4.11) and body text (WCAG 1.4.3). */
	const MIN_UI   = 3.0;
	const MIN_TEXT = 4.5;

	/** @var array|null per-request memo of the resolved tokens. */
	private static $tokens = null;

	/** @var array|null per-request memo of the palette (slug => hex). */
	private static $palette = null;

	/** @var array|null per-request memo of patterns/legacy-ids.json. */
	private static $legacy = null;

	/** @var array|null per-request memo of theme_buttons(). */
	private static $buttons = null;

	/* ================================================================== module hooks */

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ], 20 );
		add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_editor' ] );
		add_filter( 'kadence_blocks_variable_font_sizes', [ __CLASS__, 'font_sizes' ] );
		foreach ( array_keys( self::DNA_ATTRS ) as $block ) {
			if ( 0 === strpos( $block, 'kadence/' ) ) { // Both of Kadence's CSS passes (head and inline) run this filter.
				add_filter( 'kadence_blocks_' . substr( $block, 8 ) . '_render_block_attributes', [ __CLASS__, 'filter_attrs' ], 20, 2 );
			}
		}
		foreach ( [ 'rowlayout', 'column' ] as $slug ) { // A placeholder background image becomes a clean band (see placeholder_attrs()).
			add_filter( 'kadence_blocks_' . $slug . '_render_block_attributes', [ __CLASS__, 'placeholder_attrs' ], 21, 2 );
		}
		add_filter( 'render_block', [ __CLASS__, 'render_block' ], 9, 2 );
		add_action( 'update_option_blog_public', [ Cache::class, 'purge_later' ] ); // Images still holding the placeholder change look when the site goes public.
		add_filter( 'launch_builder_blueprint', [ __CLASS__, 'blueprint_filter' ], 10, 2 );
		add_action( 'update_option_' . self::OPT, [ __CLASS__, 'changed' ] );
		add_action( 'add_option_' . self::OPT, [ __CLASS__, 'changed' ] );
	}

	public static function define_tools(): void {
		$ro = [ 'readonly' => true ];
		Abilities::add( 'get-style', 'Read the style DNA', 'The site\'s style DNA: preset, the token overrides, the resolved tokens, the four presets (with the header/footer/hero designs each brings to the chrome map), the CSS variable and lbd-* class contracts, the compiled CSS size, who owns the theme button settings (ledger) and a contrast report (buttons against dark surfaces in every state, body text on every surface).',
			[ 'properties' => [ 'css' => [ 'type' => 'boolean', 'description' => 'Also return the compiled CSS.' ] ] ], [ __CLASS__, 'tool_get' ], 'edit_theme_options', $ro );

		Abilities::add( 'set-style', 'Set the style DNA', 'Choose a style preset and/or override tokens; every page restyles at once (no content is rewritten). preset: clean | bold | sharp | soft | none. tokens (partial, merged into the stored overrides): radius {card,tile,panel,media,button,input,pill} px; shadow {card,raised,button} none|subtle|soft|lift|hard|hard-lg; border {width px, color}; space {scale 0.75-1.5, section tight|normal|roomy, card xs|sm|md|lg|xl}; type {scale 0.8-1.3, heading_transform none|uppercase|capitalize, heading_weight 400-900, heading_tracking em -0.05..0.2, eyebrow plain|pill|line|bar}; surfaces {tint, dark, brand}; buttons {variant solid|outline|soft|pill|sharp, on_dark light|ghost, weight, uppercase, padding sm|md|lg|xl}; hero {overlay_color, overlay_strength 0-100}; star_color. Colours: palette1-9, #hex or rgb(a). replace_tokens drops the stored overrides first. apply_buttons (default true when a preset is given) writes the Kadence theme buttons from the button tokens; when someone changed them in the Customizer since Launch Builder last wrote them they are reported as kept_edits and left alone unless force. auto_fix (default true when apply_buttons writes the buttons of a preset) moves a theme button hover colour that vanishes on the dark surface token, or hover text that does not read, to the nearest colour that works on the page and on dark (the contrast report lists every issue either way). dry_run returns the plan without writing. Snapshots first.',
			[ 'properties' => [
				'preset'         => [ 'type' => 'string', 'enum' => array_merge( self::PRESETS, [ 'none' ] ) ],
				'tokens'         => [ 'type' => 'object' ],
				'replace_tokens' => [ 'type' => 'boolean' ],
				'apply_buttons'  => [ 'type' => 'boolean' ],
				'force'          => [ 'type' => 'boolean' ],
				'auto_fix'       => [ 'type' => 'boolean' ],
				'dry_run'        => [ 'type' => 'boolean' ],
			] ], [ __CLASS__, 'tool_set' ], 'edit_theme_options' );
	}

	/** Module hook: site-state. */
	public static function site_state( array &$state ): void {
		$s = self::get();
		$state['style'] = [ 'preset' => $s['preset'], 'overrides' => array_keys( $s['tokens'] ), 'css_bytes' => strlen( self::css() ), 'buttons' => self::buttons_owner()['status'] ];
	}

	/** Module hook: get-playbook. */
	public static function playbook( array &$pb ): void {
		$pb['style'] = 'STYLE DNA: one call (set-style preset clean|bold|sharp|soft, optional token overrides) restyles radius, shadows, borders, section and card spacing, heading treatment, eyebrows, surfaces, on-dark buttons and the hero overlay on every page at once. Kadence still owns palette, fonts, theme buttons and content width (set-brand; set-style apply_buttons writes the theme buttons from the preset). Patterns carry lbd-* classes instead of baked values: lbd-sec (+ --tight/--roomy/--tint/--dark/--brand), lbd-card (+ --flat/--raised/--outline), lbd-tile, lbd-panel, lbd-media, lbd-eyebrow, lbd-title, lbd-badge, lbd-coupon, lbd-hero, lbd-btn-light/lbd-btn-ghost for buttons on dark backgrounds, lbd-keep to opt a block out. Never set radius, border, shadow, section/card padding or heading transform/weight/tracking on a block that has an lbd-* class. get-style shows the tokens and a contrast report.';
		$pb['tools_by_job']['Style DNA'] = 'get-style, set-style (then set-brand for colours and fonts)';
	}

	/**
	 * Module hook: audit checks (site-wide, from the contrast report). button_hover_contrast: a theme button whose normal
	 * or hover colour vanishes on a dark surface (owner review G.2; warning where the DNA does not cover it, info where
	 * the on-dark states already do); surface_text_contrast: body text or eyebrows under 4.5:1 on a DNA surface.
	 */
	public static function audit_checks(): array {
		return [
			'button_hover_contrast' => [ 'style', [ __CLASS__, 'audit_buttons' ] ],
			'surface_text_contrast' => [ 'style', [ __CLASS__, 'audit_text' ] ],
		];
	}

	/** Audit check button_hover_contrast. */
	public static function audit_buttons( array $args = [] ): array {
		unset( $args );
		$out = [];
		foreach ( self::contrast_report()['issues'] as $i ) {
			if ( empty( $i['button'] ) && 0 !== strpos( (string) $i['what'], 'on-dark' ) && 0 !== strpos( (string) $i['what'], 'light button' ) ) {
				continue;
			}
			$out[] = [
				'severity'    => empty( $i['covered'] ) ? 'warning' : 'info',
				'object_type' => 'theme_mod',
				'id'          => 'primary' === ( $i['button'] ?? '' ) ? 'buttons_background' : ( 'secondary' === ( $i['button'] ?? '' ) ? 'buttons_secondary_background' : 'launch_builder_style' ),
				'url'         => '',
				'message'     => $i['message'],
				'fix'         => ! empty( $i['fixable'] ) ? 'set-buttons { auto_fix: true } (or set-style auto_fix) moves the hover to a colour that keeps 3:1 on the page and on dark.' : 'Choose button colours with 3:1 against the surface (set-buttons), or use lbd-btn-light / lbd-btn-ghost on dark backgrounds.',
			];
		}
		return $out;
	}

	/** Audit check surface_text_contrast. */
	public static function audit_text( array $args = [] ): array {
		unset( $args );
		$out = [];
		foreach ( self::contrast_report()['issues'] as $i ) {
			if ( in_array( $i['what'], [ 'body text', 'eyebrow' ], true ) ) {
				$out[] = [ 'severity' => 'warning', 'object_type' => 'option', 'id' => 'launch_builder_style', 'url' => '', 'message' => $i['message'], 'fix' => 'Pick a darker/lighter surface token (set-style tokens.surfaces) or adjust the palette (set-brand).' ];
			}
		}
		return $out;
	}

	/** Module hook: build-site steps. A blueprint's design.style (or brand.style) is applied right after the design phase. */
	public static function blueprint_steps(): array {
		return [ [ 'id' => 'style', 'at' => 'design', 'run' => [ __CLASS__, 'blueprint_style' ] ] ];
	}

	/** Module hook: the brief's brand.style section. */
	public static function brief_schema( array &$schema ): void {
		$schema['properties']['brand']['properties']['style'] = [
			'type'        => 'object',
			'description' => 'Style DNA: preset clean|bold|sharp|soft and token overrides (see set-style). The preset also picks the default header (main row only), footer and role heroes.',
			'properties'  => [ 'preset' => [ 'type' => 'string', 'enum' => self::PRESETS ], 'tokens' => [ 'type' => 'object' ] ],
		];
	}

	/**
	 * Filter launch_builder_blueprint: a blueprint that names a style keeps Kadence's block defaults out of the way
	 * (DNA-classed blocks must not start with baked values) unless it asks for them explicitly.
	 */
	public static function blueprint_filter( $bp, $input = [] ) {
		$bp = (array) $bp;
		$style = $bp['design']['style'] ?? ( $bp['brand']['style'] ?? null );
		if ( $style && ! array_key_exists( 'block_defaults', (array) ( $bp['design'] ?? [] ) ) ) {
			$bp['design'] = (array) ( $bp['design'] ?? [] );
			$bp['design']['block_defaults'] = false;
		}
		return $bp;
	}

	/** build-site step: set-style from design.style / brand.style ("preset" or {preset, tokens}). */
	public static function blueprint_style( array $bp, array $ctx ) {
		$style = $bp['design']['style'] ?? ( $bp['brand']['style'] ?? null );
		if ( ! $style ) {
			return [ 'skipped' => 'no design.style' ];
		}
		$style = is_string( $style ) ? [ 'preset' => $style ] : (array) $style;
		$args = [
			'preset'        => (string) ( $style['preset'] ?? '' ),
			'tokens'        => (array) ( $style['tokens'] ?? [] ),
			'apply_buttons' => empty( $bp['design']['buttons'] ), // Explicit design.buttons were already applied and win.
		];
		if ( '' === $args['preset'] ) {
			unset( $args['preset'] );
		}
		$r = self::tool_set( $args );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return [ 'preset' => $r['preset'], 'buttons' => $r['buttons']['status'] ?? '', 'contrast_issues' => count( (array) ( $r['contrast']['issues'] ?? [] ) ) ];
	}

	/* ================================================================== tokens */

	/** @return array{preset:string,tokens:array} the stored style (empty preset = none chosen yet; tokens = overrides). */
	public static function get(): array {
		$s = get_option( self::OPT, [] );
		$s = is_array( $s ) ? $s : [];
		return [ 'preset' => in_array( $s['preset'] ?? '', self::PRESETS, true ) ? $s['preset'] : '', 'tokens' => is_array( $s['tokens'] ?? null ) ? $s['tokens'] : [] ];
	}

	/** The resolved tokens: BASE < preset < stored overrides (or the given style). */
	public static function tokens( ?array $style = null ): array {
		if ( null === $style && null !== self::$tokens ) {
			return self::$tokens;
		}
		$s = $style ?? self::get();
		$t = self::BASE;
		if ( isset( self::PRESET_DEFS[ $s['preset'] ?? '' ] ) ) {
			$t = self::merge( $t, self::PRESET_DEFS[ $s['preset'] ]['tokens'] );
		}
		$errors = [];
		$t = self::merge( $t, self::sanitize_tokens( (array) ( $s['tokens'] ?? [] ), $errors ) );
		if ( null === $style ) {
			self::$tokens = $t;
		}
		return $t;
	}

	/**
	 * The chrome designs the chosen preset brings (a partial chrome map: header/footer per role, the role heroes):
	 * Chrome::get() lays it over Chrome::DEFAULT_MAP and under the stored map. [] while no preset is chosen.
	 */
	public static function chrome_defaults(): array {
		$p = self::get()['preset'];
		return '' !== $p ? self::PRESET_DEFS[ $p ]['chrome'] : [];
	}

	/** Recursive merge where $b wins; lists and scalars replace. */
	private static function merge( array $a, array $b ): array {
		foreach ( $b as $k => $v ) {
			$a[ $k ] = is_array( $v ) && isset( $a[ $k ] ) && is_array( $a[ $k ] ) && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ? self::merge( $a[ $k ], $v ) : $v;
		}
		return $a;
	}

	/**
	 * Validate token overrides (nested). Unknown groups/keys and invalid values are dropped and reported in $errors.
	 *
	 * @param array    $in     token overrides.
	 * @param string[] $errors problems found (by reference).
	 */
	public static function sanitize_tokens( array $in, array &$errors ): array {
		$out = [];
		$num = static function ( $v, float $min, float $max ) {
			return is_numeric( $v ) && (float) $v >= $min && (float) $v <= $max ? 0 + $v : null;
		};
		$enum = static function ( $v, array $ok ) {
			return is_string( $v ) && in_array( $v, $ok, true ) ? $v : null;
		};
		$color = static function ( $v ) {
			return is_string( $v ) ? Design::color( $v ) : null;
		};
		$rules = [
			'radius'   => array_fill_keys( [ 'card', 'tile', 'panel', 'media', 'button', 'input', 'pill' ], static function ( $v ) use ( $num ) { return 'pill' === $v ? 999 : ( null === $num( $v, 0, 999 ) ? null : (int) $v ); } ),
			'shadow'   => array_fill_keys( [ 'card', 'raised', 'button' ], static function ( $v ) use ( $enum ) { return $enum( $v, array_keys( self::SHADOWS ) ); } ),
			'border'   => [ 'width' => static function ( $v ) use ( $num ) { return null === $num( $v, 0, 8 ) ? null : (int) $v; }, 'color' => $color ],
			'space'    => [
				'scale'   => static function ( $v ) use ( $num ) { return $num( $v, 0.75, 1.5 ); },
				'section' => static function ( $v ) use ( $enum ) { return $enum( $v, array_keys( self::SECTION_STEP ) ); },
				'card'    => static function ( $v ) use ( $enum ) { return $enum( $v, self::PAD_STEPS ); },
			],
			'type'     => [
				'scale'             => static function ( $v ) use ( $num ) { return $num( $v, 0.8, 1.3 ); },
				'heading_transform' => static function ( $v ) use ( $enum ) { return $enum( $v, [ 'none', 'uppercase', 'capitalize' ] ); },
				'heading_weight'    => static function ( $v ) use ( $num ) { return null !== $num( $v, 100, 900 ) && 0 === (int) $v % 100 ? (int) $v : null; },
				'heading_tracking'  => static function ( $v ) use ( $num ) { return $num( $v, -0.05, 0.2 ); },
				'eyebrow'           => static function ( $v ) use ( $enum ) { return $enum( $v, [ 'plain', 'pill', 'line', 'bar' ] ); },
			],
			'surfaces' => array_fill_keys( [ 'tint', 'dark', 'brand' ], $color ),
			'buttons'  => [
				'variant'   => static function ( $v ) use ( $enum ) { return $enum( $v, [ '', 'solid', 'outline', 'soft', 'pill', 'sharp' ] ); },
				'on_dark'   => static function ( $v ) use ( $enum ) { return $enum( $v, [ 'light', 'ghost' ] ); },
				'weight'    => static function ( $v ) use ( $num ) { return null !== $num( $v, 100, 900 ) && 0 === (int) $v % 100 ? (int) $v : null; },
				'uppercase' => static function ( $v ) { return is_bool( $v ) ? $v : null; },
				'padding'   => static function ( $v ) use ( $enum ) { return $enum( $v, [ 'sm', 'md', 'lg', 'xl' ] ); },
			],
			'hero'     => [
				'overlay_color'    => $color,
				'overlay_strength' => static function ( $v ) use ( $num ) { return null === $num( $v, 0, 100 ) ? null : (int) $v; },
			],
		];
		foreach ( $in as $group => $vals ) {
			if ( 'star_color' === $group ) {
				$c = $color( $vals );
				if ( null === $c ) {
					$errors[] = 'star_color: not a colour (palette1-9, #hex, rgb()).';
				} else {
					$out['star_color'] = $c;
				}
				continue;
			}
			if ( ! isset( $rules[ $group ] ) ) {
				$errors[] = 'Unknown token group "' . sanitize_key( (string) $group ) . '".';
				continue;
			}
			if ( ! is_array( $vals ) ) {
				$errors[] = $group . ': must be an object.';
				continue;
			}
			foreach ( $vals as $k => $v ) {
				if ( ! isset( $rules[ $group ][ $k ] ) ) {
					$errors[] = 'Unknown token ' . $group . '.' . sanitize_key( (string) $k ) . '.';
					continue;
				}
				$clean = $rules[ $group ][ $k ]( $v );
				if ( null === $clean ) {
					$errors[] = 'Invalid value for ' . $group . '.' . $k . '.';
					continue;
				}
				$out[ $group ][ $k ] = $clean;
			}
		}
		return $out;
	}

	/* ================================================================== CSS: compile, cache, enqueue */

	/** The compiled CSS (variables + class rules), cached until anything it reads changes. */
	public static function css(): string {
		$key = self::cache_key();
		$c = get_option( self::OPT_CSS );
		// The cached copy is used only when its key matches and it cannot close the <style> it is printed in.
		if ( is_array( $c ) && ( $c['key'] ?? '' ) === $key && is_string( $c['css'] ?? null ) && false === strpos( $c['css'], '<' ) ) {
			return $c['css'];
		}
		$css = str_replace( '<', '', self::compile( self::tokens() ) ); // Token values are validated; this is the backstop.
		if ( ! is_customize_preview() ) { // A Customizer preview must not cache its unsaved values.
			update_option( self::OPT_CSS, [ 'key' => $key, 'css' => $css, 'time' => time() ], true );
		}
		return $css;
	}

	/** LB_VERSION + template mtime + hash of every input the compiled CSS depends on. */
	public static function cache_key(): string {
		$tpl = LB_DIR . self::TEMPLATE;
		$mods = [];
		foreach ( [ 'buttons_background', 'buttons_color', 'buttons_secondary_background', 'buttons_secondary_color', 'dropdown_navigation_border_radius' ] as $m ) {
			$mods[ $m ] = get_theme_mod( $m, null );
		}
		$in = [ get_option( self::OPT, [] ), get_option( 'kadence_global_palette', '' ), $mods, get_template() ];
		return LB_VERSION . '-' . ( is_readable( $tpl ) ? (int) filemtime( $tpl ) : 0 ) . '-' . substr( Ledger::hash_value( $in ), 0, 12 );
	}

	/** Option hook: the style changed (drop the request memo and purge page caches once). */
	public static function changed(): void {
		self::$tokens = null;
		Cache::purge_later();
	}

	/** Front end: print the CSS inline on our own handle, after the theme's dynamic CSS. */
	public static function enqueue(): void {
		self::add_style( wp_style_is( 'kadence-global', 'registered' ) ? [ 'kadence-global' ] : [] );
	}

	/** Block editor (iframe included): the same CSS, after Kadence Blocks' editor globals so our :root values win. */
	public static function enqueue_editor(): void {
		if ( ! is_admin() ) {
			return; // The front end uses enqueue() (enqueue_block_assets also fires there).
		}
		self::add_style( wp_style_is( 'kadence-blocks-global-editor-styles', 'registered' ) ? [ 'kadence-blocks-global-editor-styles' ] : [] );
	}

	private static function add_style( array $deps ): void {
		if ( wp_style_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$css = self::css();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( self::HANDLE, false, $deps, LB_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $css );
	}

	/** Kadence font-size presets (sm..xxxl) scaled by tokens.type.scale, in the editor and on the front end. */
	public static function font_sizes( $sizes ) {
		$k = (float) ( self::tokens()['type']['scale'] ?? 1 );
		if ( ! is_array( $sizes ) || abs( $k - 1 ) < 0.001 ) {
			return $sizes;
		}
		foreach ( $sizes as $slug => $v ) {
			if ( is_string( $v ) ) {
				$sizes[ $slug ] = (string) preg_replace_callback( '/(\d*\.?\d+)(rem|em|px|vw)\b/', static function ( $m ) use ( $k ) { return rtrim( rtrim( number_format( (float) $m[1] * $k, 4, '.', '' ), '0' ), '.' ) . $m[2]; }, $v );
			}
		}
		return $sizes;
	}

	/**
	 * Compile the CSS for a set of resolved tokens.
	 *
	 * @param array $t resolved tokens.
	 */
	public static function compile( array $t ): string {
		$ctx = self::surface_context( $t );
		$vars = self::vars( $t, $ctx );
		$decl = '';
		foreach ( $vars as $k => $v ) {
			$decl .= $k . ':' . $v . ';';
		}
		$tpl = is_readable( LB_DIR . self::TEMPLATE ) ? (string) file_get_contents( LB_DIR . self::TEMPLATE ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$tpl = (string) preg_replace( '#/\*.*?\*/#s', '', $tpl ); // Comments are documentation, not payload.
		$tpl = (string) preg_replace( [ '/\s+/', '/\s*([{};])\s*/' ], [ ' ', '$1' ], $tpl ); // Whitespace collapsed (descendant and calc() spaces kept).
		$tpl = str_replace( '}', "}\n", $tpl );
		$tpl = strtr( $tpl, [
			'__LBD_DARK__'  => implode( ', ', $ctx['dark'] ),
			'__LBD_SWAP__'  => implode( ', ', $ctx['swap'] ),
			'__LBD_SWAP2__' => implode( ', ', $ctx['swap2'] ),
			'__LBD_LIGHT__' => implode( ', ', $ctx['light'] ),
		] );
		$css = ':root,.editor-styles-wrapper{' . $decl . '}' . "\n" . trim( (string) preg_replace( '/\n\s*\n+/', "\n", $tpl ) );
		// The Customizer dropdown: Kadence rounds each item but not the panel, so the panel gets the same radius.
		$r = get_theme_mod( 'dropdown_navigation_border_radius' );
		$r = is_string( $r ) ? json_decode( $r, true ) : $r;
		if ( is_array( $r ) && isset( $r['size'] ) && is_array( $r['size'] ) && array_filter( $r['size'], 'is_numeric' ) ) {
			$unit = preg_match( '/^(px|em|rem|%)$/', (string) ( $r['unit'] ?? 'px' ) ) ? (string) ( $r['unit'] ?? 'px' ) : 'px';
			$sz = array_map( static function ( $v ) use ( $unit ) { return ( is_numeric( $v ) ? 0 + $v : 0 ) . $unit; }, array_slice( array_values( $r['size'] ) + [ 0, 0, 0, 0 ], 0, 4 ) );
			$css .= "\n" . '.header-navigation .header-menu-container ul ul.sub-menu,.header-navigation .header-menu-container ul ul.submenu{border-radius:' . implode( ' ', $sz ) . '}';
		}
		return (string) apply_filters( 'launch_builder_style_css', $css, $t );
	}

	/** A token colour as CSS (palette slugs become the Kadence variables, so palette edits flow through). */
	private static function css_color( string $c ): string {
		return preg_match( '/^palette(\d{1,2})$/', $c, $m ) ? 'var(--global-palette' . $m[1] . ')' : $c;
	}

	private static function rem( float $v ): string {
		return rtrim( rtrim( number_format( $v, 4, '.', '' ), '0' ), '.' ) . 'rem';
	}

	/** The :root variables. */
	private static function vars( array $t, array $ctx ): array {
		$v = [];
		foreach ( $t['radius'] as $k => $px ) {
			$v[ '--lb-radius-' . $k ] = (int) $px . 'px';
		}
		foreach ( self::LEGACY_VARS as $old => $new ) {
			$v[ $old ] = 'var(' . $new . ')';
		}
		$v['--lb-radius-inset'] = 'max(0px, calc(var(--lb-radius-card) - 4px))';
		foreach ( $t['shadow'] as $k => $s ) {
			$v[ '--lb-shadow-' . $k ] = self::SHADOWS[ $s ] ?? 'none';
		}
		$bw = (int) $t['border']['width'];
		$v['--lb-border-w'] = $bw . 'px';
		$v['--lb-border-c'] = self::css_color( (string) $t['border']['color'] );
		$v['--lb-card-border'] = $bw > 0 ? 'var(--lb-border-w) solid var(--lb-border-c)' : '0 solid transparent';
		$v['--lb-card-bg'] = 'var(--global-palette9)';
		$v['--lb-card-text'] = 'var(--global-palette4)';
		$v['--lb-coupon-line'] = 'var(--global-palette1)';

		// Spacing: the Kadence scale (undefined on the front end without us) scaled, section and box padding from it.
		$k = (float) $t['space']['scale'];
		foreach ( self::SPACE as $slug => $rem ) {
			$v[ '--global-kb-spacing-' . $slug ] = self::rem( $rem * $k );
		}
		foreach ( self::GAPS as $slug => $rem ) {
			$v[ '--global-kb-gap-' . $slug ] = self::rem( $rem * $k );
		}
		$step = self::SECTION_STEP[ $t['space']['section'] ] ?? 2;
		$v['--lb-section-y'] = 'var(--global-kb-spacing-' . self::SECTION_LADDER[ $step ] . ')';
		$v['--lb-section-y-tight'] = 'var(--global-kb-spacing-' . self::SECTION_LADDER[ max( 0, $step - 1 ) ] . ')';
		$v['--lb-section-y-roomy'] = 'var(--global-kb-spacing-' . self::SECTION_LADDER[ min( 4, $step + 1 ) ] . ')';
		$pi = (int) array_search( $t['space']['card'], self::PAD_STEPS, true );
		$v['--lb-card-pad'] = 'var(--global-kb-spacing-' . self::PAD_STEPS[ $pi ] . ')';
		$v['--lb-tile-pad'] = 'var(--global-kb-spacing-' . self::PAD_STEPS[ max( 0, $pi - 1 ) ] . ')';
		$v['--lb-panel-pad'] = 'var(--global-kb-spacing-' . self::PAD_STEPS[ min( 4, $pi + 1 ) ] . ')';

		// Headings and eyebrows.
		$v['--lb-heading-transform'] = $t['type']['heading_transform'];
		$v['--lb-heading-weight'] = (string) (int) $t['type']['heading_weight'];
		$v['--lb-heading-tracking'] = 0.0 === (float) $t['type']['heading_tracking'] ? 'normal' : ( 0 + $t['type']['heading_tracking'] ) . 'em';
		$eb = $t['type']['eyebrow'];
		$v['--lb-eyebrow-base-color'] = 'var(--global-palette1)';
		$v['--lb-eyebrow-color'] = 'var(--lb-eyebrow-base-color)';
		$v['--lb-eyebrow-size'] = '.8125rem';
		$v['--lb-eyebrow-weight'] = '700';
		$v['--lb-eyebrow-tracking'] = 'pill' === $eb ? '.08em' : '.14em';
		$v['--lb-eyebrow-transform'] = 'uppercase';
		$v['--lb-eyebrow-base-bg'] = 'pill' === $eb ? 'color-mix(in srgb, var(--global-palette1) 12%, transparent)' : 'transparent';
		$v['--lb-eyebrow-bg'] = 'var(--lb-eyebrow-base-bg)';
		$v['--lb-eyebrow-pad'] = 'pill' === $eb ? '.4em .9em' : ( 'bar' === $eb ? '0 0 0 .7em' : '0' );
		$v['--lb-eyebrow-radius'] = 'pill' === $eb ? 'var(--lb-radius-pill)' : '0';
		$v['--lb-eyebrow-width'] = 'pill' === $eb ? 'fit-content' : 'auto';
		$v['--lb-eyebrow-bar'] = 'bar' === $eb ? '3px' : '0px';
		$v['--lb-eyebrow-line'] = 'line' === $eb ? '28px' : '0px';
		$v['--lb-eyebrow-line-display'] = 'line' === $eb ? 'inline-block' : 'none';
		$v['--lb-badge-base-bg'] = 'color-mix(in srgb, var(--global-palette1) 12%, transparent)';
		$v['--lb-badge-base-fg'] = 'var(--global-palette3)';
		$v['--lb-badge-bg'] = 'var(--lb-badge-base-bg)';
		$v['--lb-badge-fg'] = 'var(--lb-badge-base-fg)';

		// Surfaces and the text that reads on them (chosen for contrast, see surface_context()).
		foreach ( [ 'tint', 'dark', 'brand' ] as $s ) {
			$v[ '--lb-surface-' . $s ] = self::css_color( (string) $t['surfaces'][ $s ] );
			$v[ '--lb-on-' . $s . '-text' ] = self::css_color( $ctx['fg'][ $s ]['text'] );
			$v[ '--lb-on-' . $s . '-heading' ] = self::css_color( $ctx['fg'][ $s ]['heading'] );
		}
		$v['--lb-on-dark-accent'] = self::css_color( $ctx['fg']['dark']['accent'] );
		$v['--lb-on-dark-tint'] = 'rgba(255,255,255,.12)';
		$v['--lb-on-brand-tint'] = $ctx['dark_surface']['brand'] ? 'rgba(255,255,255,.14)' : 'rgba(0,0,0,.06)';
		$v['--lb-on-hero-text'] = self::css_color( $ctx['fg']['hero']['text'] );
		$v['--lb-on-hero-heading'] = self::css_color( $ctx['fg']['hero']['heading'] );
		$v['--lb-hero-overlay-color'] = self::css_color( (string) $t['hero']['overlay_color'] );
		$v['--lb-hero-overlay-opacity'] = (string) round( (int) $t['hero']['overlay_strength'] / 100, 2 );
		$v['--lb-star-color'] = self::css_color( (string) $t['star_color'] );

		// Buttons on dark surfaces (see dna.css): light and ghost variants, the hover every theme button gets there.
		// The colours are worked out from the palette so every state reads on every dark surface (on_dark()); the token only
		// picks the look (a light button or a ghost outline), never whether the text can be read.
		$ghost = 'ghost' === $t['buttons']['on_dark'];
		$od = self::on_dark( $ctx );
		$v['--lb-on-dark-light-bg'] = self::css_color( $od['face'] );
		$v['--lb-on-dark-light-bg-hover'] = self::css_color( $od['face_hover'] );
		$v['--lb-on-dark-light-fg'] = self::css_color( $od['text'] );
		$v['--lb-on-dark-ghost-fg'] = self::css_color( $od['ghost'] );
		$v['--lb-on-dark-ghost-line'] = 'color-mix(in srgb, ' . self::css_color( $od['ghost'] ) . ' 78%, transparent)';
		$v['--lb-on-dark-hover-bg'] = self::css_color( $od['face'] );
		$v['--lb-on-dark-hover-fg'] = self::css_color( $od['text'] );
		$v['--lb-on-dark-ring'] = self::css_color( $od['ghost'] );
		$v['--lb-on-dark-bg'] = $ghost ? 'transparent' : self::css_color( $od['face'] );
		$v['--lb-on-dark-fg'] = $ghost ? self::css_color( $od['ghost'] ) : self::css_color( $od['text'] );
		$v['--lb-on-dark-swap-hover-bg'] = $ghost ? self::css_color( $od['face'] ) : self::css_color( $od['face_hover'] );
		$v['--lb-on-dark-swap-hover-fg'] = self::css_color( $od['text'] );
		$v['--lb-on-dark-swap-edge'] = $ghost ? '2px' : '0px';
		// The theme's own button colours, kept for light boxes nested in dark sections (resolved here, at :root).
		foreach ( [ 'bg' => 'btn-bg', 'fg' => 'btn', 'bg-hover' => 'btn-bg-hover', 'fg-hover' => 'btn-hover', 'sec-bg' => 'btn-sec-bg', 'sec-fg' => 'btn-sec', 'sec-bg-hover' => 'btn-sec-bg-hover', 'sec-fg-hover' => 'btn-sec-hover' ] as $mine => $theirs ) {
			$v[ '--lb-btn-root-' . $mine ] = 'var(--global-palette-' . $theirs . ')';
		}
		return (array) apply_filters( 'launch_builder_style_vars', $v, $t );
	}

	/* ================================================================== colour */

	/** The active Kadence palette, slug => #hex (Kadence's defaults where the site has none). */
	public static function palette(): array {
		if ( null !== self::$palette ) {
			return self::$palette;
		}
		$pal = self::DEFAULT_PALETTE;
		$raw = get_option( 'kadence_global_palette', '' );
		$opt = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( is_array( $opt ) ) {
			$active = in_array( $opt['active'] ?? '', [ 'palette', 'second-palette', 'third-palette' ], true ) ? $opt['active'] : 'palette';
			$list = ! empty( $opt[ $active ] ) && is_array( $opt[ $active ] ) ? $opt[ $active ] : (array) ( $opt['palette'] ?? [] );
			foreach ( $list as $e ) {
				if ( is_array( $e ) && isset( $e['slug'], $e['color'] ) && preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $e['color'] ) ) {
					$pal[ (string) $e['slug'] ] = (string) $e['color'];
				}
			}
		}
		return self::$palette = $pal;
	}

	/** Forget the per-request memos (palette, theme buttons, tokens) after a tool changed them in this request. */
	public static function forget(): void {
		self::$palette = null;
		self::$buttons = null;
		self::$tokens = null;
	}

	/**
	 * A colour as #rrggbb: palette slugs, var(--global-paletteN...), #hex and rgb(a) (alpha blended over $over).
	 * Null for transparent, empty and unknown values.
	 */
	public static function hex( $c, string $over = '#FFFFFF' ): ?string {
		$c = strtolower( trim( (string) $c ) );
		if ( '' === $c || 'transparent' === $c ) {
			return null;
		}
		if ( preg_match( '/^(?:var\(\s*--global-)?(palette\d{1,2})\b/', $c, $m ) ) {
			$p = self::palette();
			return isset( $p[ $m[1] ] ) ? self::hex( $p[ $m[1] ], $over ) : null;
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/', $c, $m ) ) {
			$c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $c, $m ) ) {
			return isset( $m[2] ) ? self::blend( '#' . $m[1], $over, hexdec( $m[2] ) / 255 ) : '#' . strtoupper( $m[1] );
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+%?)\s*)?\)$/', $c, $m ) ) {
			$hex = sprintf( '#%02X%02X%02X', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
			$a = isset( $m[4] ) ? ( '%' === substr( $m[4], -1 ) ? (float) $m[4] / 100 : (float) $m[4] ) : 1.0;
			return $a >= 1 ? $hex : self::blend( $hex, $over, $a );
		}
		return null;
	}

	/** $a over $b at alpha $alpha. */
	public static function blend( string $a, string $b, float $alpha ): string {
		$ra = self::rgb( $a );
		$rb = self::rgb( $b );
		$o = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$o[] = (int) round( $ra[ $i ] * $alpha + $rb[ $i ] * ( 1 - $alpha ) );
		}
		return vsprintf( '#%02X%02X%02X', $o );
	}

	private static function rgb( string $hex ): array {
		$h = ltrim( (string) self::hex( $hex ), '#' );
		return 6 === strlen( $h ) ? [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) ] : [ 0, 0, 0 ];
	}

	/** WCAG relative luminance of a #hex colour. */
	public static function luminance( string $hex ): float {
		$l = 0.0;
		foreach ( self::rgb( $hex ) as $i => $c ) {
			$c /= 255;
			$c = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			$l += [ 0.2126, 0.7152, 0.0722 ][ $i ] * $c;
		}
		return $l;
	}

	/** WCAG contrast ratio of two colours (anything hex() reads); 1.0 when either is unknown. */
	public static function contrast( $a, $b ): float {
		$ha = self::hex( $a );
		$hb = self::hex( $b );
		if ( ! $ha || ! $hb ) {
			return 1.0;
		}
		$la = self::luminance( $ha );
		$lb = self::luminance( $hb );
		return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
	}

	/** Is a colour dark (light text reads on it)? */
	public static function is_dark( $c ): bool {
		$h = self::hex( $c );
		return $h && self::luminance( $h ) < 0.18;
	}

	/** The candidate with the best contrast on $surface ($prefer wins when it already passes $min). */
	private static function best_on( $surface, array $candidates, float $min = self::MIN_TEXT ): string {
		$best = $candidates[0];
		$bestr = 0.0;
		foreach ( $candidates as $c ) {
			$r = self::contrast( $c, $surface );
			if ( $r >= $min ) {
				return $c; // Candidates are in order of preference.
			}
			if ( $r > $bestr ) {
				$best = $c;
				$bestr = $r;
			}
		}
		return $best;
	}

	/**
	 * Per surface: which text/heading/accent colours read on it, whether it is dark, and the selector lists dna.css
	 * needs (dark contexts, contexts where the theme buttons swap to the on-dark variant, light resets).
	 */
	private static function surface_context( array $t ): array {
		$hero_hex = self::blend( (string) ( self::hex( $t['hero']['overlay_color'] ) ?? '#000000' ), '#808080', max( 0, min( 100, (int) $t['hero']['overlay_strength'] ) ) / 100 ); // A mid-tone photo under the overlay.
		$surf = [ 'tint' => $t['surfaces']['tint'], 'dark' => $t['surfaces']['dark'], 'brand' => $t['surfaces']['brand'], 'hero' => $hero_hex ];
		$fg = [];
		$dark = [];
		foreach ( $surf as $s => $c ) {
			$dark[ $s ] = self::is_dark( $c );
			$fg[ $s ] = [
				'text'    => $dark[ $s ] ? self::best_on( $c, [ 'palette7', 'palette9' ] ) : self::best_on( $c, [ 'palette4', 'palette3' ] ),
				'heading' => $dark[ $s ] ? self::best_on( $c, [ 'palette9' ] ) : self::best_on( $c, [ 'palette3' ] ),
				'accent'  => $dark[ $s ] ? self::best_on( $c, [ 'palette1', 'palette2', 'palette7', 'palette9' ] ) : self::best_on( $c, [ 'palette1', 'palette2', 'palette3' ] ),
			];
		}
		$btn = self::theme_buttons();
		$ctx = [ 'fg' => $fg, 'dark_surface' => $dark, 'dark' => [ '.lbd-on-dark' ], 'swap' => [ '.lbd-on-dark--swap' ], 'swap2' => [ '.lbd-on-dark--swap' ], 'light' => [ '.lbd-on-light', '.lbd-card', '.lbd-tile', '.lbd-panel', '.lbd-coupon', '.lbd-sec--tint' ] ];
		$selectors = [ 'dark' => [ '.lbd-sec--dark', '.lbd-sticky-bar' ], 'brand' => [ '.lbd-sec--brand' ], 'hero' => [ '.lbd-hero' ] ];
		foreach ( $selectors as $s => $sels ) {
			if ( ! $dark[ $s ] ) {
				$ctx['light'] = array_merge( $ctx['light'], $sels );
				continue;
			}
			$ctx['dark'] = array_merge( $ctx['dark'], $sels );
			if ( self::contrast( $btn['primary']['bg'], $surf[ $s ] ) < self::MIN_UI ) {
				$ctx['swap'] = array_merge( $ctx['swap'], $sels );
			}
			if ( self::contrast( $btn['secondary']['bg'], $surf[ $s ] ) < self::MIN_UI ) {
				$ctx['swap2'] = array_merge( $ctx['swap2'], $sels );
			}
		}
		$ctx['surfaces'] = $surf;
		return $ctx;
	}

	/**
	 * The colours buttons wear on dark surfaces, chosen from the palette so every state (normal, hover, focus) reads on
	 * EVERY dark surface at once: the dark band, the brand band, the hero under its overlay and the sticky bar.
	 *   face       the light button's fill: the lightest palette colour that stands out (3:1, WCAG 1.4.11) on every dark surface
	 *   text       its text: the first of palette3, palette4, black, white that reaches 4.5:1 on the face
	 *   face_hover the hover fill: palette7 when it still stands out on the surfaces and keeps the text at 4.5:1, else the face
	 *   ghost      the outline variant's text and edge colour (>= 4.5:1 on every dark surface)
	 *
	 * @param array $ctx surface_context().
	 * @return array{face:string,text:string,face_hover:string,ghost:string,surfaces:array}
	 */
	public static function on_dark( array $ctx ): array {
		$surfaces = [];
		foreach ( [ 'dark', 'brand', 'hero' ] as $s ) {
			if ( ! empty( $ctx['dark_surface'][ $s ] ) ) {
				$surfaces[ $s ] = (string) $ctx['surfaces'][ $s ];
			}
		}
		if ( ! $surfaces ) { // No dark surface token: blocks marked on-dark by their own colour still need the variables.
			$surfaces['dark'] = (string) $ctx['surfaces']['dark'];
		}
		$worst = static function ( string $c ) use ( $surfaces ): float {
			$m = 99.0;
			foreach ( $surfaces as $sc ) {
				$m = min( $m, self::contrast( $c, $sc ) );
			}
			return $m;
		};
		$face = 'palette9';
		foreach ( [ 'palette9', 'palette8', 'palette7', '#FFFFFF' ] as $c ) {
			if ( $worst( $c ) >= self::MIN_UI ) {
				$face = $c;
				break;
			}
		}
		$text = self::best_on( $face, [ 'palette3', 'palette4', '#000000', '#FFFFFF' ] );
		$hover = $face;
		foreach ( [ 'palette7', 'palette8' ] as $c ) {
			if ( $worst( $c ) >= self::MIN_UI && self::contrast( $text, $c ) >= self::MIN_TEXT ) {
				$hover = $c;
				break;
			}
		}
		$ghost = 'palette9';
		foreach ( [ 'palette9', '#FFFFFF' ] as $c ) {
			if ( $worst( $c ) >= self::MIN_TEXT ) {
				$ghost = $c;
				break;
			}
		}
		return [ 'face' => $face, 'text' => $text, 'face_hover' => $hover, 'ghost' => $ghost, 'surfaces' => $surfaces ];
	}

	/** The theme buttons' colours as stored (palette slugs or colours; '' = Kadence default), normal and hover. */
	public static function theme_buttons(): array {
		if ( null !== self::$buttons ) {
			return self::$buttons;
		}
		// Kadence's defaults apply while a mod was never saved; a saved empty hover means "no change on hover".
		$m = static function ( string $key, string $sub, string $default, string $empty ) {
			$v = get_theme_mod( $key );
			$v = is_string( $v ) ? json_decode( $v, true ) : $v;
			if ( ! is_array( $v ) ) {
				return $default;
			}
			$x = (string) ( $v[ $sub ] ?? '' );
			return '' !== $x ? $x : $empty;
		};
		$kd = [ 'primary' => [ 'palette1', 'palette2', 'palette9', 'palette9' ], 'secondary' => [ 'palette7', 'palette2', 'palette3', 'palette9' ] ]; // bg, bg hover, text, text hover.
		$out = [];
		foreach ( [ 'primary' => 'buttons', 'secondary' => 'buttons_secondary' ] as $kind => $p ) {
			$d = $kd[ $kind ];
			$bg = $m( $p . '_background', 'color', $d[0], $d[0] );
			$fg = $m( $p . '_color', 'color', $d[2], $d[2] );
			$out[ $kind ] = [ 'bg' => $bg, 'bg_hover' => $m( $p . '_background', 'hover', $d[1], $bg ), 'fg' => $fg, 'fg_hover' => $m( $p . '_color', 'hover', $d[3], $fg ) ];
		}
		$ol = $m( 'buttons_outline_color', 'color', 'palette1', 'palette1' );
		$out['outline'] = [ 'fg' => $ol, 'fg_hover' => $m( 'buttons_outline_color', 'hover', $ol, $ol ) ];
		return self::$buttons = $out;
	}

	/* ================================================================== contrast guard */

	/**
	 * Contrast report: theme buttons against the light page and the dark surface tokens in normal and hover state, the
	 * on-dark variants, and body text on every surface. Each issue says where it matters and whether the DNA already
	 * covers it (inside lbd-sec--dark, lbd-sec--brand, lbd-hero and every Kadence row/column with a dark background the
	 * theme buttons use the on-dark states, so a theme colour that fails there only matters on unmarked dark blocks).
	 *
	 * @param array|null $t      resolved tokens (current when null).
	 * @param array|null $btn    theme button colours (current when null), as theme_buttons().
	 */
	public static function contrast_report( ?array $t = null, ?array $btn = null ): array {
		$t = $t ?? self::tokens();
		$btn = $btn ?? self::theme_buttons();
		$ctx = self::surface_context( $t );
		$issues = [];
		$checks = 0;
		$add = static function ( array $i ) use ( &$issues, &$checks ) {
			$checks++;
			if ( $i['ratio'] < $i['min'] ) {
				$i['message'] = sprintf( '%s %s (%s) on %s (%s): %.2f:1, needs %.1f:1%s', $i['what'], $i['state'], $i['color'], $i['surface'], $i['surface_color'], $i['ratio'], $i['min'], empty( $i['covered'] ) ? '' : ' (' . $i['covered'] . ')' );
				$issues[] = $i;
			}
		};
		$base = 'palette9';
		foreach ( [ 'primary', 'secondary' ] as $kind ) {
			$b = $btn[ $kind ];
			foreach ( [ 'normal' => [ 'bg', 'fg' ], 'hover' => [ 'bg_hover', 'fg_hover' ] ] as $state => $keys ) {
				if ( ! self::hex( $b[ $keys[0] ] ) ) { // A transparent (outline-style) button: its text sits on the page.
					$add( [ 'what' => $kind . ' button text', 'button' => $kind, 'state' => $state, 'color' => $b[ $keys[1] ], 'surface' => 'page', 'surface_color' => $base, 'ratio' => self::contrast( $b[ $keys[1] ], $base ), 'min' => self::MIN_TEXT ] );
					continue;
				}
				$add( [ 'what' => $kind . ' button text', 'button' => $kind, 'state' => $state, 'color' => $b[ $keys[1] ], 'surface' => 'button', 'surface_color' => $b[ $keys[0] ], 'ratio' => self::contrast( $b[ $keys[1] ], $b[ $keys[0] ] ), 'min' => self::MIN_TEXT, 'fixable' => 'hover' === $state ] );
				$add( [ 'what' => $kind . ' button', 'button' => $kind, 'state' => $state, 'color' => $b[ $keys[0] ], 'surface' => 'page', 'surface_color' => $base, 'ratio' => self::contrast( $b[ $keys[0] ], $base ), 'min' => self::MIN_UI ] );
				// Heroes and brand bands are always under the DNA (lbd-hero, lbd-sec--brand, on-dark marks), so the theme
				// colours are measured against the page and the dark surface token, where a hand-made dark block may sit.
				if ( $ctx['dark_surface']['dark'] ) {
					$add( [ 'what' => $kind . ' button', 'button' => $kind, 'state' => $state, 'color' => $b[ $keys[0] ], 'surface' => 'dark', 'surface_color' => (string) $ctx['surfaces']['dark'], 'ratio' => self::contrast( $b[ $keys[0] ], $ctx['surfaces']['dark'] ), 'min' => self::MIN_UI, 'covered' => 'covered by the DNA on-dark states in lbd-sec--dark, lbd-hero and dark Kadence rows/columns; matters for buttons on other dark blocks', 'fixable' => 'hover' === $state ] );
				}
			}
		}
		// The on-dark variants (the colours on_dark() works out) against every dark surface, in every state: the face or the
		// ghost edge against the surface (normal), the hover face and the focus ring, and the text on each face.
		$ghost = 'ghost' === $t['buttons']['on_dark'];
		$od = self::on_dark( $ctx );
		foreach ( [ 'dark', 'brand', 'hero' ] as $s ) {
			if ( ! $ctx['dark_surface'][ $s ] ) {
				continue;
			}
			$sc = (string) $ctx['surfaces'][ $s ];
			$add( [ 'what' => 'on-dark button', 'state' => 'hover/focus', 'color' => $od['face'], 'surface' => $s, 'surface_color' => $sc, 'ratio' => self::contrast( $od['face'], $sc ), 'min' => self::MIN_UI ] );
			$add( [ 'what' => 'on-dark button', 'state' => 'focus ring', 'color' => $od['ghost'], 'surface' => $s, 'surface_color' => $sc, 'ratio' => self::contrast( $od['ghost'], $sc ), 'min' => self::MIN_UI ] );
			$add( [ 'what' => 'on-dark button', 'state' => 'normal', 'color' => $ghost ? $od['ghost'] . ' (edge)' : $od['face'], 'surface' => $s, 'surface_color' => $sc, 'ratio' => self::contrast( $ghost ? $od['ghost'] : $od['face'], $sc ), 'min' => self::MIN_UI ] );
			if ( $ghost ) {
				$add( [ 'what' => 'on-dark button text', 'state' => 'normal', 'color' => $od['ghost'], 'surface' => $s, 'surface_color' => $sc, 'ratio' => self::contrast( $od['ghost'], $sc ), 'min' => self::MIN_TEXT ] );
			}
			$add( [ 'what' => 'light button', 'state' => 'hover', 'color' => $od['face_hover'], 'surface' => $s, 'surface_color' => $sc, 'ratio' => self::contrast( $od['face_hover'], $sc ), 'min' => self::MIN_UI ] );
		}
		if ( ! $ghost ) {
			$add( [ 'what' => 'on-dark button text', 'state' => 'normal', 'color' => $od['text'], 'surface' => 'button', 'surface_color' => $od['face'], 'ratio' => self::contrast( $od['text'], $od['face'] ), 'min' => self::MIN_TEXT ] );
		}
		$add( [ 'what' => 'on-dark button text', 'state' => 'hover', 'color' => $od['text'], 'surface' => 'button', 'surface_color' => $od['face'], 'ratio' => self::contrast( $od['text'], $od['face'] ), 'min' => self::MIN_TEXT ] );
		$add( [ 'what' => 'light button text', 'state' => 'hover', 'color' => $od['text'], 'surface' => 'button', 'surface_color' => $od['face_hover'], 'ratio' => self::contrast( $od['text'], $od['face_hover'] ), 'min' => self::MIN_TEXT ] );
		// The light buttons the patterns carry (palette9 fill, palette3 text, palette7 hover) are what visitors see.
		$add( [ 'what' => 'light button text', 'state' => 'normal (pattern colours)', 'color' => 'palette3', 'surface' => 'button', 'surface_color' => 'palette9', 'ratio' => self::contrast( 'palette3', 'palette9' ), 'min' => self::MIN_TEXT ] );
		$add( [ 'what' => 'light button text', 'state' => 'hover (pattern colours)', 'color' => 'palette3', 'surface' => 'button', 'surface_color' => 'palette7', 'ratio' => self::contrast( 'palette3', 'palette7' ), 'min' => self::MIN_TEXT ] );
		// Body text and headings on every surface.
		foreach ( [ 'tint', 'dark', 'brand', 'hero' ] as $s ) {
			$sc = (string) $ctx['surfaces'][ $s ];
			$add( [ 'what' => 'body text', 'state' => 'normal', 'color' => $ctx['fg'][ $s ]['text'], 'surface' => $s, 'surface_color' => $sc, 'ratio' => self::contrast( $ctx['fg'][ $s ]['text'], $sc ), 'min' => self::MIN_TEXT ] );
			$add( [ 'what' => 'eyebrow', 'state' => 'normal', 'color' => 'tint' === $s ? 'palette1' : $ctx['fg'][ $s ]['accent'], 'surface' => $s, 'surface_color' => $sc, 'ratio' => self::contrast( 'tint' === $s ? 'palette1' : $ctx['fg'][ $s ]['accent'], $sc ), 'min' => self::MIN_TEXT ] );
		}
		$uncovered = count( array_filter( $issues, static function ( $i ) { return empty( $i['covered'] ); } ) );
		return [ 'ok' => ! $issues, 'uncovered' => $uncovered, 'checks' => $checks, 'min_ui' => self::MIN_UI, 'min_text' => self::MIN_TEXT, 'issues' => $issues, 'hero_assumption' => 'hero overlay over a mid-tone photo' ];
	}

	/**
	 * auto_fix: for each fixable issue (a theme button hover too close to a dark surface, or hover text that does not
	 * read on its hover background), the nearest colour to the button's own colour that keeps >= 3:1 against the light
	 * page and every dark surface token and >= 4.5:1 with its text (the text colour switches to palette9/palette3 only
	 * when it must). Returns theme mod changes: [ mod => [ sub => value ] ] plus a list of what changed.
	 */
	public static function hover_fixes( array $report, ?array $t = null, ?array $btn = null ): array {
		$t = $t ?? self::tokens();
		$btn = $btn ?? self::theme_buttons();
		$ctx = self::surface_context( $t );
		$darks = $ctx['dark_surface']['dark'] ? [ $ctx['surfaces']['dark'] ] : [];
		$kinds = [];
		foreach ( $report['issues'] as $i ) {
			if ( ! empty( $i['fixable'] ) && ! empty( $i['button'] ) ) {
				$kinds[ $i['button'] ] = true;
			}
		}
		$mods = [];
		$done = [];
		foreach ( array_keys( $kinds ) as $kind ) {
			$b = $btn[ $kind ];
			$base = (string) self::hex( $b['bg'] );
			if ( '' === $base ) {
				continue;
			}
			$cands = [];
			foreach ( self::palette() as $slug => $hex ) {
				$cands[ $slug ] = $hex;
			}
			foreach ( [ 0.08, 0.16, 0.24, 0.32, 0.4 ] as $k ) {
				$cands[ 'mix-w' . $k ] = self::blend( '#FFFFFF', $base, $k );
				$cands[ 'mix-b' . $k ] = self::blend( '#000000', $base, $k );
			}
			$best = null;
			foreach ( $cands as $name => $hex ) {
				if ( self::contrast( $hex, 'palette9' ) < self::MIN_UI ) {
					continue;
				}
				$ok = true;
				foreach ( $darks as $d ) {
					$ok = $ok && self::contrast( $hex, $d ) >= self::MIN_UI;
				}
				if ( ! $ok ) {
					continue;
				}
				$text = null;
				foreach ( array_unique( [ $b['fg_hover'], $b['fg'], 'palette9', 'palette3' ] ) as $i => $fgc ) {
					if ( self::contrast( $fgc, $hex ) >= self::MIN_TEXT ) {
						$text = [ $fgc, $i > 1 ];
						break;
					}
				}
				if ( ! $text ) {
					continue;
				}
				$d = self::distance( $hex, $base );
				// A hover must look different from the normal state: identical scores worst, barely different next.
				$score = ( $text[1] ? 1000 : 0 ) + ( $d < 1 ? 500 : ( $d < 40 ? 300 : 0 ) ) + $d;
				if ( null === $best || $score < $best['score'] ) {
					$best = [ 'score' => $score, 'color' => 0 === strpos( (string) $name, 'palette' ) ? $name : $hex, 'text' => $text[0] ];
				}
			}
			$p = 'primary' === $kind ? 'buttons' : 'buttons_secondary';
			if ( ! $best ) {
				$done[] = [ 'button' => $kind, 'fixed' => false, 'why' => 'no colour keeps 3:1 against both the light page and the dark surfaces; use the on-dark classes' ];
				continue;
			}
			$mods[ $p . '_background' ] = [ 'hover' => $best['color'] ];
			$mods[ $p . '_color' ] = [ 'hover' => $best['text'] ];
			$done[] = [ 'button' => $kind, 'fixed' => true, 'hover_background' => $best['color'], 'hover_color' => $best['text'], 'was' => [ $b['bg_hover'], $b['fg_hover'] ] ];
		}
		return [ 'mods' => $mods, 'changes' => $done ];
	}

	/** Simple RGB distance (enough to rank candidates by closeness). */
	private static function distance( string $a, string $b ): float {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		return sqrt( ( $x[0] - $y[0] ) ** 2 + ( $x[1] - $y[1] ) ** 2 + ( $x[2] - $y[2] ) ** 2 );
	}

	/* ================================================================== theme buttons through the Ledger */

	/** Who owns the theme button mods: 'lb' (as Launch Builder left them), 'edited' (changed since), 'foreign' (never written by LB). */
	public static function buttons_owner(): array {
		$row = Ledger::get( self::LEDGER_BUTTONS );
		if ( null === $row ) {
			return [ 'status' => 'foreign' ];
		}
		return [ 'status' => Ledger::is_edited( self::LEDGER_BUTTONS, Ledger::hash_mods( self::BUTTON_MODS ) ) ? 'edited' : 'lb', 'last_write' => $row['last_write'] ?? 0 ];
	}

	/** Record the current button mods as Launch Builder's (after LB wrote them). */
	public static function record_buttons(): void {
		Ledger::record( self::LEDGER_BUTTONS, 'theme_mod', 'buttons', Ledger::hash_mods( self::BUTTON_MODS ), 'set' );
	}

	/**
	 * Write theme button mods through the Ledger: refuses (kept_edits) when someone changed them since Launch Builder
	 * last wrote them, unless $force. Values merge into the stored arrays.
	 *
	 * @param array $mods mod => value (arrays merge one level into the current value).
	 */
	public static function write_button_mods( array $mods, bool $force ): array {
		$owner = self::buttons_owner();
		if ( 'edited' === $owner['status'] && ! $force ) {
			return [ 'status' => 'kept_edits', 'note' => 'The theme buttons were changed (Customizer or another tool) after Launch Builder set them; left alone. Pass force:true to overwrite.' ];
		}
		$written = [];
		foreach ( $mods as $k => $v ) {
			if ( ! in_array( $k, self::BUTTON_MODS, true ) ) {
				continue;
			}
			$cur = get_theme_mod( $k );
			$cur = is_string( $cur ) ? json_decode( $cur, true ) : $cur;
			set_theme_mod( $k, is_array( $v ) && is_array( $cur ) && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ? array_replace( $cur, $v ) : $v );
			$written[] = $k;
		}
		self::record_buttons();
		self::forget();
		return [ 'status' => 'foreign' === $owner['status'] ? 'applied_over_foreign' : ( 'edited' === $owner['status'] ? 'forced' : 'applied' ), 'changed' => $written ];
	}

	/** The set-buttons input that realises the button tokens (variant, radius, shadow, weight, case, padding). */
	public static function button_plan( array $t ): array {
		$b = $t['buttons'];
		$variant = (string) $b['variant'];
		if ( '' === $variant ) {
			return [];
		}
		$radius = 'pill' === $variant ? 'pill' : ( 'sharp' === $variant ? 0 : (int) $t['radius']['button'] );
		$shadow = [ 'none' => 'none', 'subtle' => 'soft', 'soft' => 'soft', 'lift' => 'lift', 'hard' => 'hard', 'hard-lg' => 'hard' ][ $t['shadow']['button'] ] ?? 'none';
		$common = [ 'radius' => $radius, 'padding' => $b['padding'], 'weight' => (int) $b['weight'], 'uppercase' => (bool) $b['uppercase'] || 'sharp' === $variant, 'shadow' => 'sharp' === $variant ? 'none' : $shadow ];
		// Text on a button is the palette colour that reads on its fill (4.5:1), so a light brand colour (a bright yellow)
		// never gets white text; the outline colour is the brand colour that reads on the page.
		$on = static function ( string $bg ): string {
			return self::best_on( $bg, [ 'palette9', 'palette3' ] );
		};
		$line = self::best_on( 'palette9', [ 'palette1', 'palette2', 'palette3' ] );
		$primary = [ 'background' => 'palette1', 'hover_background' => 'palette3', 'color' => $on( 'palette1' ), 'hover_color' => $on( 'palette3' ), 'border_width' => 0 ];
		if ( 'outline' === $variant ) {
			$primary = [ 'background' => 'transparent', 'hover_background' => $line, 'color' => $line, 'hover_color' => $on( $line ), 'border_width' => 2, 'border_color' => $line ];
		} elseif ( 'soft' === $variant ) {
			$primary = [ 'background' => 'palette7', 'hover_background' => 'palette1', 'color' => 'palette3', 'hover_color' => $on( 'palette1' ), 'border_width' => 0 ];
		}
		return [
			'primary'   => $common + $primary,
			'secondary' => $common + [ 'background' => 'palette3', 'hover_background' => 'palette1', 'color' => $on( 'palette3' ), 'hover_color' => $on( 'palette1' ), 'border_width' => 0 ],
			'outline'   => $common + [ 'color' => $line, 'hover_color' => $on( $line ), 'border_color' => $line, 'border_width' => 2, 'background' => 'transparent', 'hover_background' => $line ],
		];
	}

	/** The theme button colours a button plan would leave (as theme_buttons(); kinds the plan does not touch stay as they are). */
	private static function planned_buttons( array $plan ): array {
		$btn = self::theme_buttons();
		foreach ( [ 'primary', 'secondary' ] as $kind ) {
			if ( empty( $plan[ $kind ] ) ) {
				continue;
			}
			$p = $plan[ $kind ];
			$bg = (string) ( $p['background'] ?? $btn[ $kind ]['bg'] );
			$fg = (string) ( $p['color'] ?? $btn[ $kind ]['fg'] );
			$btn[ $kind ] = [ 'bg' => $bg, 'bg_hover' => (string) ( $p['hover_background'] ?? $bg ), 'fg' => $fg, 'fg_hover' => (string) ( $p['hover_color'] ?? $fg ) ];
		}
		return $btn;
	}

	/* ================================================================== render: Kadence attributes, markup */

	/** patterns/legacy-ids.json (uniqueID => pattern slug of the shipped patterns); [] when the file is absent. */
	public static function legacy_ids(): array {
		if ( null === self::$legacy ) {
			$file = LB_DIR . 'patterns/legacy-ids.json';
			$ids = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$legacy = is_array( $ids ) ? $ids : [];
		}
		return self::$legacy;
	}

	/** Does a className string carry $class? */
	private static function has_class( string $classes, string $class ): bool {
		return '' !== $classes && in_array( $class, preg_split( '/\s+/', trim( $classes ) ), true );
	}

	/** Any lbd-* class other than lbd-keep (the DNA owns this block's styling). */
	public static function is_dna( string $classes ): bool {
		foreach ( preg_split( '/\s+/', trim( $classes ) ) as $c ) {
			if ( 0 === strpos( $c, 'lbd-' ) && 'lbd-keep' !== $c ) {
				return true;
			}
		}
		return false;
	}

	/** The DNA class a legacy block's numeric radius maps to ('' = none: unknown value or uneven corners). */
	public static function legacy_role( string $block, array $attrs ): string {
		$map = self::LEGACY_ROLE[ $block ] ?? [];
		if ( ! $map ) {
			return '';
		}
		$r = $attrs['borderRadius'] ?? ( $attrs['containerBorderRadius'] ?? null );
		$r = is_array( $r ) ? array_values( $r ) : [ $r, $r, $r, $r ];
		if ( ! is_numeric( $r[0] ?? null ) || count( array_unique( array_map( 'strval', array_slice( $r, 0, 4 ) ) ) ) > 1 ) {
			return '';
		}
		return $map[ (int) $r[0] ] ?? '';
	}

	/** Is this a block the legacy pass handles (uniqueID in legacy-ids.json and not opted out or DNA-classed)? */
	private static function is_legacy( array $attrs ): bool {
		$uid = (string) ( $attrs['uniqueID'] ?? '' );
		return '' !== $uid && isset( self::legacy_ids()[ $uid ] ) && ! self::is_dna( (string) ( $attrs['className'] ?? '' ) );
	}

	/**
	 * Kadence's kadence_blocks_{block}_render_block_attributes (head CSS and inline CSS): on DNA-classed blocks every
	 * DNA-owned attribute is dropped; on legacy blocks only the radius, and only when a DNA class takes it over.
	 *
	 * @param mixed      $attrs attributes.
	 * @param mixed|null $block WP_Block (inline pass only).
	 * @return mixed
	 */
	public static function filter_attrs( $attrs, $block = null ) {
		unset( $block );
		if ( ! is_array( $attrs ) || ! preg_match( '/^kadence_blocks_([a-z]+)_render_block_attributes$/', (string) current_filter(), $m ) ) {
			return $attrs;
		}
		$name = 'kadence/' . $m[1];
		$cls = (string) ( $attrs['className'] ?? '' );
		if ( self::has_class( $cls, 'lbd-keep' ) || ! isset( self::DNA_ATTRS[ $name ] ) ) {
			return $attrs;
		}
		if ( self::is_dna( $cls ) ) {
			$groups = self::DNA_ATTRS[ $name ];
		} elseif ( self::is_legacy( $attrs ) && '' !== self::legacy_role( $name, $attrs ) ) {
			$groups = [ 'radius' => self::DNA_ATTRS[ $name ]['radius'] ];
		} else {
			return $attrs;
		}
		foreach ( $groups as $group => $names ) {
			foreach ( $names as $k ) {
				if ( ! array_key_exists( $k, $attrs ) ) {
					continue;
				}
				if ( 'kadence/rowlayout' === $name && 'padding' === $group && in_array( $k, [ 'padding', 'tabletPadding', 'mobilePadding' ], true ) ) {
					unset( $attrs[ $k ] ); // Absent padding is what makes Kadence use --global-kb-row-default-*.
				} else {
					$attrs[ $k ] = self::blank( $attrs[ $k ] );
				}
			}
		}
		return $attrs;
	}

	/** The "not set" value of an attribute of the same shape ('' leaves, false flags, units kept). */
	private static function blank( $v ) {
		if ( is_bool( $v ) ) {
			return false;
		}
		if ( is_array( $v ) ) {
			$out = [];
			foreach ( $v as $k => $x ) {
				$out[ $k ] = 'unit' === $k && is_string( $x ) ? $x : self::blank( $x );
			}
			return $out;
		}
		return '';
	}

	/**
	 * Markup pass (render_block, WP_HTML_Tag_Processor):
	 * - legacy Kadence blocks get the DNA class their radius maps to (their radius attribute is dropped by filter_attrs),
	 *   and images inside legacy columns lose the inline 12/16px radius and get lbd-media;
	 * - DNA-classed core/image blocks lose the inline radius (core prints it as a style attribute);
	 * - Kadence rows and columns with a solid background get lbd-on-dark (+ --swap) or lbd-on-light, so theme buttons
	 *   on any dark background keep visible hover/focus states;
	 * - lbd-eyebrow headings get lbd-eyebrow--center/--right from their alignment.
	 * Blocks with lbd-keep are left as authored.
	 *
	 * @param string $html  block HTML.
	 * @param array  $block parsed block.
	 */
	public static function render_block( $html, $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( ! is_string( $html ) || '' === $html || ( 'core/image' !== $name && ! isset( self::WRAPPER_CLASS[ $name ] ) ) ) {
			return $html;
		}
		$attrs = (array) ( $block['attrs'] ?? [] );
		$cls = (string) ( $attrs['className'] ?? '' );
		if ( self::has_class( $cls, 'lbd-keep' ) ) {
			return $html;
		}
		if ( 'core/image' === $name ) {
			$html = self::neutral_images( $html );
			return self::is_dna( $cls ) ? self::strip_img_radius( $html, [] ) : $html;
		}
		$add = [];
		$legacy = self::is_legacy( $attrs );
		if ( $legacy && '' !== ( $role = self::legacy_role( $name, $attrs ) ) ) {
			$add[] = $role;
		}
		if ( 'kadence/rowlayout' === $name || 'kadence/column' === $name ) {
			$add = array_merge( $add, self::surface_marks( $name, $attrs ) );
			if ( self::placeholder_bg( $name, $attrs ) && ! self::site_hidden() ) { // Like the images: a site still hidden from search engines keeps its placeholder.
				$add[] = 'lbd-ph';
			}
		}
		if ( 'kadence/testimonials' === $name ) {
			$add = array_merge( $add, self::ink_marks( $attrs ) );
		}
		if ( 'kadence/advancedheading' === $name && self::has_class( $cls, 'lbd-eyebrow' ) && in_array( $attrs['align'] ?? '', [ 'center', 'right' ], true ) ) {
			$add[] = 'lbd-eyebrow--' . $attrs['align'];
		}
		$images = $legacy && 'kadence/column' === $name && false !== strpos( $html, 'wp-block-image' );
		if ( ! $add && ! $images ) {
			return $html;
		}
		if ( $add ) {
			$p = new \WP_HTML_Tag_Processor( $html );
			$uid = (string) ( $attrs['uniqueID'] ?? '' );
			$found = '' !== $uid && $p->next_tag( [ 'class_name' => self::WRAPPER_CLASS[ $name ] . $uid ] );
			if ( ! $found ) {
				$p = new \WP_HTML_Tag_Processor( $html );
				while ( ( $found = $p->next_tag() ) && in_array( $p->get_tag(), [ 'STYLE', 'SCRIPT', 'LINK' ], true ) ) {
					continue; // Kadence may print the block's inline <style> first.
				}
			}
			if ( $found ) {
				foreach ( array_unique( $add ) as $c ) {
					$p->add_class( $c );
				}
				$html = $p->get_updated_html();
			}
		}
		return $images ? self::strip_img_radius( $html, self::LEGACY_IMG_RADII ) : $html;
	}

	/**
	 * Testimonial cards read against their own surface, not the band they sit in: a light card in a dark band inherits the
	 * band's white text and shows white on white. When nobody chose the text colours, a light card (palette9 and the like)
	 * gets lbd-ink-dark and a dark card lbd-ink-light (dna.css sets the colours).
	 */
	private static function ink_marks( array $attrs ): array {
		foreach ( [ 'titleFont', 'contentFont', 'nameFont', 'occupationFont' ] as $k ) {
			if ( ! empty( $attrs[ $k ][0]['color'] ) ) {
				return [];
			}
		}
		$bg = self::hex( (string) ( $attrs['containerBackground'] ?? '#f2f2f2' ) ); // Kadence's own default card colour.
		if ( ! $bg ) {
			return [];
		}
		$l = self::luminance( $bg );
		return $l > 0.5 ? [ 'lbd-ink-dark' ] : ( $l < 0.18 ? [ 'lbd-ink-light' ] : [] );
	}

	/* ================================================================== placeholder images */

	/** Is this URL one of the bundled placeholder pictures (assets/placeholders/*.svg)? */
	public static function is_placeholder( $url ): bool {
		return is_string( $url ) && false !== strpos( $url, 'assets/placeholders/' ) && '.svg' === substr( strtok( $url, '?' ), -4 );
	}

	/** Does a row or column still hold the placeholder as its background image? */
	private static function placeholder_bg( string $name, array $attrs ): bool {
		return self::is_placeholder( 'kadence/rowlayout' === $name ? ( $attrs['bgImg'] ?? '' ) : ( $attrs['backgroundImg'][0]['bgImg'] ?? '' ) );
	}

	/** Is the site hidden from search engines (still being built, so the placeholder pictures stay as they are)? */
	private static function site_hidden(): bool {
		return '0' === (string) get_option( 'blog_public', '1' );
	}

	/**
	 * Kadence's attribute filter for rows and columns (both CSS passes): on a public site a background that is still the
	 * placeholder picture is dropped, so the band shows its colour and the .lbd-ph tint (a hero never shows the picture's icon,
	 * even through its overlay). While the site is hidden from search engines the placeholder stays, as it does for images.
	 * Blocks with lbd-keep are left alone.
	 *
	 * @param array $attrs block attributes.
	 * @return array
	 */
	public static function placeholder_attrs( $attrs ) {
		if ( ! is_array( $attrs ) || self::site_hidden() || self::has_class( (string) ( $attrs['className'] ?? '' ), 'lbd-keep' ) ) {
			return $attrs;
		}
		if ( isset( $attrs['bgImg'] ) && self::is_placeholder( $attrs['bgImg'] ) ) {
			$attrs['bgImg'] = '';
			$attrs['bgImgID'] = '';
		}
		if ( isset( $attrs['backgroundImg'][0]['bgImg'] ) && self::is_placeholder( $attrs['backgroundImg'][0]['bgImg'] ) ) {
			$attrs['backgroundImg'][0]['bgImg'] = '';
			$attrs['backgroundImg'][0]['bgImgID'] = '';
		}
		return $attrs;
	}

	/**
	 * core/image blocks that still hold the placeholder picture: on a public site the picture (a grey box with an icon) is
	 * swapped for an empty one and the figure is marked lbd-ph, which paints a soft brand tint (dna.css). While the site is
	 * hidden from search engines the placeholder stays, so editors can see where a photo goes.
	 */
	private static function neutral_images( string $html ): string {
		if ( false === strpos( $html, 'assets/placeholders/' ) || self::site_hidden() ) {
			return $html;
		}
		$p = new \WP_HTML_Tag_Processor( $html );
		$p->next_tag( 'figure' );
		$p->set_bookmark( 'lb-fig' );
		$hit = false;
		while ( $p->next_tag( 'img' ) ) {
			if ( self::is_placeholder( (string) $p->get_attribute( 'src' ) ) ) {
				$p->set_attribute( 'src', LB_URL . 'assets/placeholders/neutral.svg' );
				$p->remove_attribute( 'srcset' );
				$p->remove_attribute( 'sizes' );
				$hit = true;
			}
		}
		if ( ! $hit ) {
			return $html;
		}
		$p->seek( 'lb-fig' );
		$p->add_class( 'lbd-ph' );
		return $p->get_updated_html();
	}

	/**
	 * Remove the inline border-radius core/image prints on its <img> (only these values when $only is given) and mark
	 * the figure lbd-media. Figures with lbd-keep are skipped.
	 */
	private static function strip_img_radius( string $html, array $only ): string {
		$p = new \WP_HTML_Tag_Processor( $html );
		$n = 0;
		while ( $p->next_tag( 'figure' ) ) {
			$fc = (string) $p->get_attribute( 'class' );
			if ( false === strpos( $fc, 'wp-block-image' ) || false !== strpos( $fc, 'lbd-keep' ) ) {
				continue;
			}
			$p->set_bookmark( 'lb-fig' );
			if ( ! $p->next_tag( 'img' ) ) {
				break;
			}
			$style = (string) $p->get_attribute( 'style' );
			if ( ! preg_match( '/(?:^|;)\s*border-radius\s*:\s*([^;]+);?/i', $style, $m ) || ( $only && ! in_array( strtolower( trim( $m[1] ) ), $only, true ) ) ) {
				continue;
			}
			$new = trim( (string) preg_replace( '/(?:^|;)\s*border-radius\s*:\s*[^;]+;?/i', ';', $style ), "; \t" );
			'' === $new ? $p->remove_attribute( 'style' ) : $p->set_attribute( 'style', $new );
			$p->seek( 'lb-fig' );
			$p->add_class( 'lbd-media' );
			$p->next_tag( 'img' ); // Move past this figure's image again.
			$n++;
		}
		$p->release_bookmark( 'lb-fig' );
		return $n ? $p->get_updated_html() : $html;
	}

	/**
	 * Classes for a Kadence row/column by its own solid background: lbd-on-dark (+ lbd-on-dark--swap when the theme
	 * primary button would vanish on it) or lbd-on-light. Image backgrounds count as dark only under a dark overlay of
	 * at least 50%.
	 */
	private static function surface_marks( string $name, array $attrs ): array {
		$bg = '';
		if ( 'kadence/rowlayout' === $name ) {
			$bg = (string) ( $attrs['bgColor'] ?? '' );
			$img = ( ! empty( $attrs['bgImg'] ) && ! self::is_placeholder( $attrs['bgImg'] ) ) || ! empty( $attrs['overlayBgImg'] );
			$ov = (string) ( $attrs['overlay'] ?? '' );
			$op = isset( $attrs['overlayOpacity'] ) && is_numeric( $attrs['overlayOpacity'] ) ? (float) $attrs['overlayOpacity'] : 30;
		} else {
			$bg = (string) ( $attrs['background'] ?? '' );
			$img = ! empty( $attrs['backgroundImg'][0]['bgImg'] ) && ! self::is_placeholder( $attrs['backgroundImg'][0]['bgImg'] );
			$ov = (string) ( $attrs['overlay'] ?? '' );
			$op = isset( $attrs['overlayOpacity'] ) && is_numeric( $attrs['overlayOpacity'] ) ? (float) $attrs['overlayOpacity'] : 0.3;
		}
		$op = $op > 1 ? $op / 100 : $op;
		if ( $img ) {
			if ( '' === $ov || $op < 0.5 || ! self::is_dark( $ov ) ) {
				return [];
			}
			$bg = (string) self::blend( (string) self::hex( $ov ), '#808080', $op );
		}
		$hex = self::hex( $bg );
		if ( ! $hex ) {
			return [];
		}
		if ( self::luminance( $hex ) < 0.18 ) {
			return self::contrast( self::theme_buttons()['primary']['bg'], $hex ) < self::MIN_UI ? [ 'lbd-on-dark', 'lbd-on-dark--swap' ] : [ 'lbd-on-dark' ];
		}
		return self::luminance( $hex ) > 0.5 ? [ 'lbd-on-light' ] : [];
	}

	/* ================================================================== tools */

	/** get-style */
	public static function tool_get( $input = [] ): array {
		$s = self::get();
		$t = self::tokens();
		$presets = [];
		foreach ( self::PRESET_DEFS as $k => $p ) {
			$presets[ $k ] = [ 'label' => $p['label'], 'about' => $p['about'], 'buttons' => $p['tokens']['buttons']['variant'], 'header' => $p['chrome']['header']['default'], 'footer' => $p['chrome']['footer']['default'], 'hero_overlay' => $p['tokens']['hero'] ];
		}
		$css = self::css();
		$out = [
			'preset'          => $s['preset'],
			'overrides'       => $s['tokens'],
			'tokens'          => $t,
			'presets'         => $presets,
			'chrome_defaults' => self::chrome_defaults(),
			'vars'            => array_keys( self::VARS ),
			'classes'         => self::CLASSES,
			'css_bytes'       => strlen( $css ),
			'cache_key'       => self::cache_key(),
			'buttons'         => self::buttons_owner() + [ 'theme' => self::theme_buttons() ],
			'contrast'        => self::contrast_report( $t ),
			'legacy_ids'      => count( self::legacy_ids() ),
		];
		if ( ! empty( $input['css'] ) ) {
			$out['css'] = $css;
		}
		return $out;
	}

	/** set-style */
	public static function tool_set( $input ) {
		$input = (array) $input;
		$cur = self::get();
		$errors = [];
		$new = $cur;
		if ( array_key_exists( 'preset', $input ) ) {
			$p = (string) $input['preset'];
			if ( 'none' === $p || '' === $p ) {
				$new['preset'] = '';
			} elseif ( in_array( $p, self::PRESETS, true ) ) {
				$new['preset'] = $p;
			} else {
				$errors[] = 'preset must be one of ' . implode( ', ', self::PRESETS ) . ' or none.';
			}
		}
		if ( isset( $input['tokens'] ) && ! is_array( $input['tokens'] ) ) {
			$errors[] = 'tokens must be an object.';
		}
		$over = self::sanitize_tokens( (array) ( $input['tokens'] ?? [] ), $errors );
		if ( $errors ) {
			return new \WP_Error( 'lb_style', 'Invalid style: ' . implode( ' ', $errors ), [ 'status' => 400, 'errors' => $errors ] );
		}
		$new['tokens'] = self::merge( ! empty( $input['replace_tokens'] ) ? [] : self::sanitize_tokens( $cur['tokens'], $errors ), $over );
		if ( ! array_key_exists( 'preset', $input ) && ! isset( $input['tokens'] ) && empty( $input['replace_tokens'] ) && empty( $input['auto_fix'] ) && empty( $input['apply_buttons'] ) ) {
			return new \WP_Error( 'lb_empty', 'Nothing to set. Give preset, tokens, replace_tokens, apply_buttons or auto_fix.', [ 'status' => 400 ] );
		}
		$t = self::tokens( $new );
		$apply = array_key_exists( 'apply_buttons', $input ) ? (bool) $input['apply_buttons'] : ( array_key_exists( 'preset', $input ) && '' !== $new['preset'] );
		$plan = $apply ? self::button_plan( $t ) : [];
		$button_mods = $plan ? Design::button_mods( $plan ) : [];
		$force = ! empty( $input['force'] );
		// The contrast guard: a preset's own button colours are made guard-clean before they are written (default on
		// when buttons are written; explicit auto_fix decides otherwise), so the theme mods are written once.
		$auto = array_key_exists( 'auto_fix', $input ) ? (bool) $input['auto_fix'] : (bool) $plan;
		$preflight = null;
		$planned = $plan ? self::planned_buttons( $plan ) : null;
		if ( $plan && $auto ) {
			$pre = self::contrast_report( $t, $planned );
			if ( ! $pre['ok'] ) {
				$fix = self::hover_fixes( $pre, $t, $planned );
				foreach ( $fix['mods'] as $mod => $sub ) {
					$button_mods[ $mod ] = array_replace( (array) ( $button_mods[ $mod ] ?? [] ), $sub );
				}
				foreach ( $fix['changes'] as $c ) {
					if ( ! empty( $c['fixed'] ) ) {
						$planned[ $c['button'] ]['bg_hover'] = $c['hover_background'];
						$planned[ $c['button'] ]['fg_hover'] = $c['hover_color'];
					}
				}
				$preflight = $fix['changes'];
			}
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return [ 'dry_run' => true, 'preset' => $new['preset'], 'overrides' => $new['tokens'], 'tokens' => $t, 'chrome_defaults' => '' !== $new['preset'] ? self::PRESET_DEFS[ $new['preset'] ]['chrome'] : [], 'buttons' => [ 'would_write' => array_keys( $button_mods ), 'owner' => self::buttons_owner()['status'], 'auto_fix' => $preflight ], 'contrast' => self::contrast_report( $t, $planned ) ];
		}

		$snap = Abilities::snapshot( 'set-style', ( $plan || ! empty( $input['auto_fix'] ) ) ? self::BUTTON_MODS : [], [ self::OPT ] );
		update_option( self::OPT, [ 'preset' => $new['preset'], 'tokens' => $new['tokens'] ], true );
		self::forget();

		$buttons = [ 'status' => 'not_requested' ];
		if ( $plan ) {
			$buttons = $button_mods ? self::write_button_mods( $button_mods, $force ) : [ 'status' => 'nothing_to_write' ];
		} elseif ( $apply ) {
			$buttons = [ 'status' => 'no_variant', 'note' => 'tokens.buttons.variant is empty: the theme buttons are left as they are.' ];
		}
		$report = self::contrast_report( $t );
		$fixed = null;
		if ( null !== $preflight ) {
			$fixed = [ 'status' => $buttons['status'] ?? '', 'changes' => $preflight ];
		} elseif ( $auto && ! $plan && ! $report['ok'] ) { // auto_fix on its own: repair the theme buttons as they are.
			$fix = self::hover_fixes( $report, $t );
			if ( $fix['mods'] ) {
				$w = self::write_button_mods( $fix['mods'], $force );
				$fixed = [ 'status' => $w['status'], 'changes' => $fix['changes'] ];
				$report = self::contrast_report( $t );
			} else {
				$fixed = [ 'status' => 'nothing_fixable', 'changes' => $fix['changes'] ];
			}
		}
		Cache::purge_later();
		return [
			'preset'          => $new['preset'],
			'overrides'       => $new['tokens'],
			'tokens'          => $t,
			'chrome_defaults' => self::chrome_defaults(),
			'buttons'         => $buttons,
			'auto_fix'        => $fixed,
			'contrast'        => $report,
			'css_bytes'       => strlen( self::css() ),
			'snapshot'        => $snap,
		];
	}
}
