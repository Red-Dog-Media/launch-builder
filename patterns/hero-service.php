<?php
/**
 * Title: Hero: service (summary and quote card)
 * Slug: launch-builder/hero-service
 * Description: Service page hero: rating row, the service title as the H1 and its summary on the left; a card with the phone number, hours and the main button on the right.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, service, summary, quote, phone, card
 * Roles: service
 * Slot: hero
 * Variant-Of: hero-page
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"8d9e8e_0","tabletLayout":"row","colLayout":"left-golden","minHeight":460,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"8d9e8e_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column8d9e8e_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"760px","justifyContent":"left"}} -->
<div class="wp-block-group"><!-- wp:launch/rating {"type":"pill","useGlobal":true,"platforms":["google","yelp","bbb"],"size":"md","style":{"spacing":{"margin":{"bottom":"20px"}}}} /-->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.08","textTransform":"uppercase","fontSize":"clamp(2.1rem, 5.4vw, 4rem)"},"spacing":{"margin":{"top":"0","bottom":"0.28em"}}}} /-->

<!-- wp:kadence/advancedheading {"uniqueID":"8d9e8e_0-0-0-2","color":"palette9","fontWeight":"700","fontStyle":"italic","margin":["","","8",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[22,"",18]} -->
<p class="kt-adv-heading8d9e8e_0-0-0-2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading8d9e8e_0-0-0-2">{{page.summary}}</p>
<!-- /wp:kadence/advancedheading --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":2,"background":"palette9","borderWidth":["","","",""],"uniqueID":"8d9e8e_0-1","kbVersion":2,"className":"lbd-card lbd-card\u002d\u002draised"} -->
<div class="wp-block-kadence-column kadence-column8d9e8e_0-1 lbd-card lbd-card--raised"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"8d9e8e_0-1-0","color":"palette1","margin":["","","8",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[14,"",""],"icon":"fe_phone","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["18","",""],"className":"lbd-eyebrow"} -->
<p class="kt-adv-heading8d9e8e_0-1-0 lbd-eyebrow wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading8d9e8e_0-1-0">Free estimate</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"8d9e8e_0-1-1","color":"palette3","fontWeight":"800","margin":["","","6",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","link":"#lb-phone_link","fontSize":[30,"",26]} -->
<a href="#lb-phone_link" class="kb-advanced-heading-link kt-adv-heading-link8d9e8e_0-1-1"><p class="kt-adv-heading8d9e8e_0-1-1 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading8d9e8e_0-1-1">{{lb.phone}}</p></a>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"8d9e8e_0-1-2","color":"palette4","margin":["","","18",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[16,"",""]} -->
<p class="kt-adv-heading8d9e8e_0-1-2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading8d9e8e_0-1-2">{{lb.hours}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"uniqueID":"8d9e8e_0-1-3","forceFullwidth":true} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns8d9e8e_0-1-3"><!-- wp:kadence/singlebtn {"uniqueID":"8d9e8e_0-1-3-0","text":"{{lb.cta_label}}","link":"#lb-cta_url","icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
