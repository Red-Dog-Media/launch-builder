<?php
/**
 * Title: Hero: page (rating, title, tagline, buttons)
 * Slug: launch-builder/hero-page
 * Description: Full-width brand hero for standard pages: platform rating row, the page title as an uppercase H1, your tagline, the main button and the phone number. Reads Business Info; breadcrumbs sit in the row below.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, page, title, tagline, rating, buttons, brand
 * Roles: page, legal
 * Slot: hero
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"319b7f_0","columns":1,"tabletLayout":"row","colLayout":"equal","minHeight":460,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"319b7f_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column319b7f_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"800px","justifyContent":"left"}} -->
<div class="wp-block-group"><!-- wp:launch/rating {"type":"pill","useGlobal":true,"platforms":["google","yelp","bbb"],"size":"md","style":{"spacing":{"margin":{"bottom":"20px"}}}} /-->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.08","textTransform":"uppercase","fontSize":"clamp(2.1rem, 5.4vw, 4rem)"},"spacing":{"margin":{"top":"0","bottom":"0.28em"}}}} /-->

<!-- wp:kadence/advancedheading {"uniqueID":"319b7f_0-0-0-2","color":"palette9","fontWeight":"700","fontStyle":"italic","margin":["","","28",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[22,"",18]} -->
<p class="kt-adv-heading319b7f_0-0-0-2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading319b7f_0-0-0-2">{{lb.tagline}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","btnCount":2,"uniqueID":"319b7f_0-0-0-3"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns319b7f_0-0-0-3"><!-- wp:kadence/singlebtn {"uniqueID":"319b7f_0-0-0-3-0","text":"{{lb.cta_label}}","link":"#lb-cta_url","icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"319b7f_0-0-0-3-1","text":"{{lb.phone}}","link":"#lb-phone_link","icon":"fe_phone","iconSide":"left","inheritStyles":"outline"} /--></div>
<!-- /wp:kadence/advancedbtn --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
