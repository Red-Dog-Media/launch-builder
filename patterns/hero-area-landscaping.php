<?php
/**
 * Title: Hero: service area (Landscaping)
 * Slug: launch-builder/hero-area-landscaping
 * Description: A rounded photo panel with a deep green tint and everything centred: a gold map pin and "Serving" line with the area name, the page title as the headline, its summary and pill buttons.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, area, city, location, photo, landscaping
 * Inserter: no
 * Piece: hero-area
 * Look: landscaping
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"hal_0","columns":1,"colLayout":"equal","align":"full","inheritMaxWidth":true,"padding":["sm","","sm",""],"mobilePadding":["xs","","xs",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"hal_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnhal_1"><div class="kt-inside-inner-col"><!-- wp:kadence/rowlayout {"uniqueID":"hal_2","columns":1,"colLayout":"equal","minHeight":460,"minHeightTablet":400,"minHeightMobile":0,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","overlay":"palette3","overlayOpacity":70,"verticalAlignment":"middle","textColor":"palette9","borderRadius":[32,32,32,32],"mobileBorderRadius":[20,20,20,20],"padding":["xxl","xxl","xxl","xxl"],"tabletPadding":["xl","lg","xl","lg"],"mobilePadding":["lg","sm","lg","sm"],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"hal_3","textAlign":["center","",""],"maxWidth":[760,"",""],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnhal_3"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"hal_4","align":"center","color":"palette9","fontWeight":"600","margin":["","","12",""],"htmlTag":"p","fontSize":[16,"",15],"icon":"fe_mapPin","iconColor":"palette2","iconPadding":["","8","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headinghal_4 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghal_4">Proudly serving {{area.name}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:post-title {"textAlign":"center","level":1,"textColor":"theme-palette9","style":{"typography":{"fontSize":"clamp(2.1rem, 4.6vw, 3.4rem)","fontWeight":"700","lineHeight":"1.12"},"spacing":{"margin":{"top":"0","bottom":"16px"}}}} /-->

<!-- wp:post-excerpt {"textAlign":"center","moreText":"","excerptLength":30,"textColor":"theme-palette8","style":{"typography":{"fontSize":"1.15rem","lineHeight":"1.6"},"spacing":{"margin":{"top":"0","bottom":"28px"}}}} /-->

<!-- wp:kadence/advancedbtn {"hAlign":"center","btnCount":2,"uniqueID":"hal_5","gap":["sm","",""]} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnshal_5"><!-- wp:kadence/singlebtn {"uniqueID":"hal_6","text":"{{lb.cta_label}}","link":"#lb-cta_url","borderRadius":[100,100,100,100],"icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"hal_7","text":"{{lb.phone}}","link":"#lb-phone_link","color":"palette3","background":"palette9","colorHover":"palette3","backgroundHover":"palette8","borderRadius":[100,100,100,100],"icon":"fe_phone","iconSide":"left","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
