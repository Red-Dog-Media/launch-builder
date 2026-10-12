<?php
/**
 * Title: Hero: shared page hero (title + tagline + buttons)
 * Slug: launch-builder/hero-element
 * Description: The one hero every inner page and entry shares: the page title as the H1, your tagline from Business Info, the two buttons and the rating. Place it once with a Kadence Pro element (hook replace_hero_header) instead of repeating it on each page.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, shared, element, title, tagline, cta, inner page
 * Roles: page, legal, services_hub, areas_hub, category, service, area
 * Slot: hero
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"295_4c7a48-df","columns":1,"colLayout":"equal","bgColor":"palette3","align":"full","verticalAlignment":"middle","textColor":"palette9","linkColor":"palette7","linkHoverColor":"palette9","inheritMaxWidth":true,"padding":["xxl","","xxl",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"295_d07c31-23","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column295_d07c31-23"><div class="kt-inside-inner-col"><!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9, #ffffff)"},"typography":{"fontWeight":"800","lineHeight":"1.1","textTransform":"uppercase","fontSize":"clamp(2rem, 5vw, 3.4rem)"},"spacing":{"margin":{"bottom":"0.3em"}}}} /-->

<!-- wp:kadence/advancedheading {"uniqueID":"295_a66a08-1a","color":"palette7","fontWeight":"600","margin":["","","22",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[20,"",""]} -->
<p class="kt-adv-heading295_a66a08-1a wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading295_a66a08-1a">{{lb.tagline}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","btnCount":2,"uniqueID":"295_c87224-58"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns295_c87224-58"><!-- wp:kadence/singlebtn {"uniqueID":"295_acaaff-ba","text":"{{lb.cta_label}}","link":"#lb-cta_url","icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"295_2e8ea8-7c","text":"{{lb.cta2_label}}","link":"#lb-cta2_url","color":"palette3","background":"palette9","colorHover":"palette3","backgroundHover":"palette7","icon":"fe_phone","iconSide":"left","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn -->

<!-- wp:launch/rating {"type":"pill","text":"Rated by 887+ happy customers","starEmpty":"rgba(255,255,255,0.25)","starSize":20,"ratingSize":17,"ratingColor":"var(\u002d\u002dglobal-palette9, #ffffff)","ratingWeight":"700","textSize":15,"textColor":"var(\u002d\u002dglobal-palette7, #e5e7eb)","style":{"spacing":{"margin":{"top":"28px"}}}} /--></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
