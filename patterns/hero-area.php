<?php
/**
 * Title: Hero: service area (summary and nearby areas)
 * Slug: launch-builder/hero-area
 * Description: Service area hero: the area name above the H1, the area summary and the buttons on the left; a card of the other areas you serve (live list) on the right.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, area, city, nearby, summary, live
 * Roles: area
 * Slot: hero
 * Variant-Of: hero-page
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"d23301_0","tabletLayout":"row","colLayout":"left-golden","minHeight":460,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"d23301_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnd23301_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"760px","justifyContent":"left"}} -->
<div class="wp-block-group"><!-- wp:kadence/advancedheading {"uniqueID":"d23301_0-0-0-0","color":"palette7","letterSpacing":1.5,"fontWeight":"700","margin":["","","10",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"textTransform":"uppercase","htmlTag":"p","fontSize":[14,"",""],"icon":"fe_mapPin","iconColor":"palette7","iconPadding":["","8","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headingd23301_0-0-0-0 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingd23301_0-0-0-0">Serving {{area.name}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.08","textTransform":"uppercase","fontSize":"clamp(2.1rem, 5.4vw, 4rem)"},"spacing":{"margin":{"top":"0","bottom":"0.28em"}}}} /-->

<!-- wp:kadence/advancedheading {"uniqueID":"d23301_0-0-0-2","color":"palette9","fontWeight":"700","fontStyle":"italic","margin":["","","28",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[22,"",18]} -->
<p class="kt-adv-headingd23301_0-0-0-2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingd23301_0-0-0-2">{{page.summary}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","btnCount":2,"uniqueID":"d23301_0-0-0-3"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnsd23301_0-0-0-3"><!-- wp:kadence/singlebtn {"uniqueID":"d23301_0-0-0-3-0","text":"{{lb.cta_label}}","link":"#lb-cta_url","icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"d23301_0-0-0-3-1","text":"{{lb.phone}}","link":"#lb-phone_link","icon":"fe_phone","iconSide":"left","inheritStyles":"outline"} /--></div>
<!-- /wp:kadence/advancedbtn --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":2,"background":"palette9","borderWidth":["","","",""],"uniqueID":"d23301_0-1","kbVersion":2,"className":"lbd-card lbd-card\u002d\u002draised"} -->
<div class="wp-block-kadence-column kadence-columnd23301_0-1 lbd-card lbd-card--raised"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"d23301_0-1-0","color":"palette1","margin":["","","8",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[14,"",""],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["18","",""],"className":"lbd-eyebrow"} -->
<p class="kt-adv-headingd23301_0-1-0 lbd-eyebrow wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingd23301_0-1-0">Also serving nearby</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:launch/entries {"type":"area","layout":"chips","limit":8,"excludeCurrent":true} /--></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
