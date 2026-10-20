<?php
/**
 * Title: Hero: area (Auto shop)
 * Slug: launch-builder/hero-area-auto-shop
 * Description: Charcoal area hero: a "Serving {area}" tag with a map pin, the area title as a heavy uppercase H1, its summary and two buttons on the left; a white "visit the shop" card with the address, a directions link and the phone on the right. Ends on a red stripe.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, area, location, city, auto, dark, directions
 * Inserter: no
 * Piece: hero-area
 * Look: auto-shop
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"haa_0","tabletLayout":"row","columnGutter":"wider","colLayout":"left-golden","minHeight":480,"minHeightTablet":0,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","overlay":"palette3","overlayOpacity":80,"align":"full","verticalAlignment":"middle","textColor":"palette9","linkColor":"palette9","linkHoverColor":"palette2","inheritMaxWidth":true,"padding":["xxl","","xxl",""],"mobilePadding":["xl","","xl",""],"borderStyle":[{"top":["","",""],"right":["","",""],"bottom":["palette1","solid",6],"left":["","",""],"unit":"px"}],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"haa_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnhaa_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"haa_2","color":"palette2","letterSpacing":3,"fontWeight":"800","textTransform":"uppercase","htmlTag":"p","margin":["","","18",""],"fontSize":[14,"",13],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["20","",""]} -->
<p class="kt-adv-headinghaa_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghaa_2">Serving drivers in {{area.name}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:post-title {"level":1,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9)"},"typography":{"fontSize":"clamp(2.25rem, 5vw, 3.75rem)","fontWeight":"900","lineHeight":"1.04","textTransform":"uppercase"},"spacing":{"margin":{"top":"0","bottom":"18px"}}}} /-->

<!-- wp:post-excerpt {"moreText":"","excerptLength":30,"style":{"color":{"text":"var(\u002d\u002dglobal-palette7)"},"typography":{"fontSize":"clamp(1.0625rem, 1.6vw, 1.25rem)","lineHeight":"1.6"},"spacing":{"margin":{"top":"0","bottom":"30px"}}}} /-->

<!-- wp:kadence/advancedbtn {"hAlign":"left","btnCount":2,"uniqueID":"haa_3","gap":["sm","",""]} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnshaa_3"><!-- wp:kadence/singlebtn {"uniqueID":"haa_4","text":"{{lb.cta_label}}","link":"#lb-cta_url","sizePreset":"large","borderRadius":[2,2,2,2],"icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"haa_5","text":"Call {{lb.phone}}","link":"#lb-phone_link","sizePreset":"large","borderRadius":[2,2,2,2],"color":"palette9","colorHover":"palette9","icon":"fe_phone","iconSide":"left","inheritStyles":"outline"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":2,"background":"palette9","borderWidth":["","","",""],"borderRadius":[2,2,2,2],"uniqueID":"haa_6","padding":["lg","lg","lg","lg"],"borderStyle":[{"top":["palette1","solid",6],"right":["","",""],"bottom":["","",""],"left":["","",""],"unit":"px"}],"textColor":"palette4","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnhaa_6"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"haa_7","color":"palette1","letterSpacing":2,"fontWeight":"800","textTransform":"uppercase","htmlTag":"p","margin":["","","8",""],"fontSize":[13,"",""]} -->
<p class="kt-adv-headinghaa_7 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghaa_7">Your local shop</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"haa_8","color":"palette3","fontWeight":"900","textTransform":"uppercase","htmlTag":"p","margin":["","","16",""],"fontSize":[24,"",22],"fontHeight":[1.15,"",""],"fontHeightType":"em"} -->
<p class="kt-adv-headinghaa_8 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghaa_8">Minutes from {{area.name}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"haa_9","color":"palette4","htmlTag":"p","margin":["","","10",""],"fontSize":[16,"",""],"fontHeight":[1.5,"",""],"fontHeightType":"em","icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["18","",""],"iconVerticalAlign":"top"} -->
<p class="kt-adv-headinghaa_9 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghaa_9">{{lb.address}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"haa_10","color":"palette1","letterSpacing":1.5,"fontWeight":"800","textTransform":"uppercase","htmlTag":"p","margin":["","","20",""],"link":"#lb-maps_url","linkColor":"palette1","linkHoverColor":"palette3","linkStyle":"none","fontSize":[13,"",""],"icon":"fe_arrowRight","iconSide":"right","iconColor":"palette1","iconPadding":["","","","6"],"iconSize":["16","",""]} -->
<p class="kt-adv-headinghaa_10 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghaa_10">Get directions</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"haa_11","color":"palette3","fontWeight":"900","htmlTag":"p","padding":["18","","","0"],"link":"#lb-phone_link","linkColor":"palette3","linkHoverColor":"palette1","linkStyle":"none","fontSize":[26,"",24],"icon":"fe_phone","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["22","",""],"borderStyle":[{"top":["palette7","solid",1],"right":["","",""],"bottom":["","",""],"left":["","",""],"unit":"px"}]} -->
<p class="kt-adv-headinghaa_11 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghaa_11">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
