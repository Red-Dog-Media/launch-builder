<?php
/**
 * Title: Hero: page, under a transparent header
 * Slug: launch-builder/hero-page-overlay
 * Description: The page hero with extra room at the top for a transparent header laid over it: rating row, the page title as an uppercase H1, your tagline, the main button and the phone number.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, page, transparent header, overlay, title, tagline
 * Roles: page, legal
 * Slot: hero
 * Variant-Of: hero-page
 * Transparent: yes
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"c19a97_0","columns":1,"tabletLayout":"row","colLayout":"equal","minHeight":540,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"c19a97_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnc19a97_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"800px","justifyContent":"left"}} -->
<div class="wp-block-group"><!-- wp:spacer {"height":"72px"} -->
<div style="height:72px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:launch/rating {"type":"pill","useGlobal":true,"platforms":["google","yelp","bbb"],"size":"md","style":{"spacing":{"margin":{"bottom":"20px"}}}} /-->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.08","textTransform":"uppercase","fontSize":"clamp(2.1rem, 5.4vw, 4rem)"},"spacing":{"margin":{"top":"0","bottom":"0.28em"}}}} /-->

<!-- wp:kadence/advancedheading {"uniqueID":"c19a97_0-0-0-3","color":"palette9","fontWeight":"700","fontStyle":"italic","margin":["","","28",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[22,"",18]} -->
<p class="kt-adv-headingc19a97_0-0-0-3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingc19a97_0-0-0-3">{{lb.tagline}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","btnCount":2,"uniqueID":"c19a97_0-0-0-4"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnsc19a97_0-0-0-4"><!-- wp:kadence/singlebtn {"uniqueID":"c19a97_0-0-0-4-0","text":"{{lb.cta_label}}","link":"#lb-cta_url","icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"c19a97_0-0-0-4-1","text":"{{lb.phone}}","link":"#lb-phone_link","icon":"fe_phone","iconSide":"left","inheritStyles":"outline"} /--></div>
<!-- /wp:kadence/advancedbtn --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
