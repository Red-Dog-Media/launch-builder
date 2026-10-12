<?php
/**
 * Title: Hero: blog post (category, title, date, author)
 * Slug: launch-builder/hero-post
 * Description: Compact dark hero for blog posts: the post category, the title as the H1 in sentence case, the date and the author.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, post, blog, article, title, date, author
 * Roles: post
 * Slot: hero
 * Variant-Of: hero-page
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"2812b2_0","columns":1,"tabletLayout":"row","colLayout":"equal","minHeight":300,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"2812b2_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column2812b2_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"860px","justifyContent":"left"}} -->
<div class="wp-block-group"><!-- wp:post-terms {"term":"category","style":{"typography":{"fontSize":"0.9rem","fontWeight":"700","textTransform":"uppercase","letterSpacing":"0.08em"},"spacing":{"margin":{"bottom":"0.9em"}},"elements":{"link":{"color":{"text":"var(\u002d\u002dglobal-palette7, #e5e7eb)"}}}}} /-->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.08","textTransform":"none","fontSize":"clamp(1.9rem, 4.6vw, 3.1rem)"},"spacing":{"margin":{"top":"0","bottom":"0.5em"}}}} /-->

<!-- wp:group {"style":{"spacing":{"blockGap":"1.4rem"}},"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:post-author-name {"style":{"color":{"text":"var(\u002d\u002dglobal-palette7, #e5e7eb)"},"typography":{"fontSize":"1rem"}}} /-->

<!-- wp:post-date {"datetime":"2026-10-08T21:57:58.780Z","style":{"color":{"text":"var(\u002d\u002dglobal-palette7, #e5e7eb)"},"typography":{"fontSize":"1rem"}}} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
