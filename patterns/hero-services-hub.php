<?php
/**
 * Title: Hero: services hub (centered, service chips)
 * Slug: launch-builder/hero-services-hub
 * Description: Centered hero for the main Services page: rating row, the page title as the H1, your tagline, the buttons and a row of service chips from your Services list (live).
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, services, hub, centered, chips, live
 * Roles: services_hub
 * Slot: hero
 * Variant-Of: hero-page
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"7b45b5_0","columns":1,"tabletLayout":"row","colLayout":"equal","minHeight":440,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"7b45b5_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column7b45b5_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"860px","justifyContent":"center"}} -->
<div class="wp-block-group"><!-- wp:launch/rating {"type":"pill","useGlobal":true,"platforms":["google","yelp","bbb"],"size":"md","contentAlign":"center","style":{"spacing":{"margin":{"bottom":"20px"}}}} /-->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.08","textTransform":"uppercase","fontSize":"clamp(2.1rem, 5.4vw, 4rem)","textAlign":"center"},"spacing":{"margin":{"top":"0","bottom":"0.28em"}}}} /-->

<!-- wp:kadence/advancedheading {"uniqueID":"7b45b5_0-0-0-2","align":"center","color":"palette9","fontWeight":"700","fontStyle":"italic","margin":["","","28",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[22,"",18]} -->
<p class="kt-adv-heading7b45b5_0-0-0-2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading7b45b5_0-0-0-2">{{lb.tagline}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"btnCount":2,"uniqueID":"7b45b5_0-0-0-3"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns7b45b5_0-0-0-3"><!-- wp:kadence/singlebtn {"uniqueID":"7b45b5_0-0-0-3-0","text":"{{lb.cta_label}}","link":"#lb-cta_url","icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"7b45b5_0-0-0-3-1","text":"{{lb.phone}}","link":"#lb-phone_link","icon":"fe_phone","iconSide":"left","inheritStyles":"outline"} /--></div>
<!-- /wp:kadence/advancedbtn -->

<!-- wp:kadence/advancedheading {"uniqueID":"7b45b5_0-0-0-4","align":"center","color":"palette7","letterSpacing":1,"fontWeight":"700","margin":["26","","10",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"textTransform":"uppercase","htmlTag":"p","fontSize":[15,"",""]} -->
<p class="kt-adv-heading7b45b5_0-0-0-4 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading7b45b5_0-0-0-4">Popular services</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:group {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-group"><!-- wp:launch/entries {"layout":"chips","limit":10,"variant":"outline"} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
