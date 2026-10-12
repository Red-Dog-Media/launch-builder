<?php
/**
 * Title: Hero: conversion page (compact, phone first)
 * Slug: launch-builder/hero-conversion
 * Description: Compact centered hero for booking, quote and landing pages: the page title as the H1, your tagline, the phone number as the main button and the rating row. No menu competes with the form below it.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, conversion, landing, booking, quote, phone, compact
 * Roles: conversion
 * Slot: hero
 * Variant-Of: hero-page
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"264fb4_0","columns":1,"tabletLayout":"row","colLayout":"equal","minHeight":340,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"264fb4_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column264fb4_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"820px","justifyContent":"center"}} -->
<div class="wp-block-group"><!-- wp:launch/rating {"type":"pill","useGlobal":true,"platforms":["google","yelp","bbb"],"size":"md","contentAlign":"center","style":{"spacing":{"margin":{"bottom":"20px"}}}} /-->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.08","textTransform":"uppercase","fontSize":"clamp(2rem, 4.6vw, 3.4rem)","textAlign":"center"},"spacing":{"margin":{"top":"0","bottom":"0.28em"}}}} /-->

<!-- wp:kadence/advancedheading {"uniqueID":"264fb4_0-0-0-2","align":"center","color":"palette9","fontWeight":"700","fontStyle":"italic","margin":["","","28",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[22,"",18]} -->
<p class="kt-adv-heading264fb4_0-0-0-2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading264fb4_0-0-0-2">{{lb.tagline}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"uniqueID":"264fb4_0-0-0-3"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns264fb4_0-0-0-3"><!-- wp:kadence/singlebtn {"uniqueID":"264fb4_0-0-0-3-0","text":"{{lb.phone}}","link":"#lb-phone_link","color":"palette3","background":"palette9","colorHover":"palette3","backgroundHover":"palette7","icon":"fe_phone","iconSide":"left","inheritStyles":"","className":"lbd-btn-light"} /--></div>
<!-- /wp:kadence/advancedbtn --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
