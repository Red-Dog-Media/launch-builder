<?php
/**
 * Title: Hero: service area (Home services)
 * Slug: launch-builder/hero-area-home-services
 * Description: Light-tint area hero: a "Serving {area}" line with a map pin, the page title as the H1, its summary and two buttons on the left; a white rounded card with local reasons to call and the phone number on the right.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, area, city, location, local, checklist, phone, home services
 * Inserter: no
 * Piece: hero-area
 * Look: home-services
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"hah_0","colLayout":"left-golden","tabletLayout":"row","mobileLayout":"row","bgColor":"palette8","align":"full","verticalAlignment":"middle","inheritMaxWidth":true,"padding":["xl","","xl",""],"mobilePadding":["lg","","lg",""],"borderStyle":[{"top":["","",""],"right":["","",""],"bottom":["palette7","solid",1],"left":["","",""],"unit":"px"}],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"hah_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnhah_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"hah_2","color":"palette1","fontWeight":"700","margin":["","","10",""],"htmlTag":"p","fontSize":[15,"",""],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headinghah_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghah_2">Proudly serving {{area.name}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:post-title {"level":1,"style":{"typography":{"fontSize":"clamp(2rem, 4.2vw, 3.1rem)","fontWeight":"800","lineHeight":"1.12"},"spacing":{"margin":{"top":"0","bottom":"14px"}}},"textColor":"theme-palette3"} /-->

<!-- wp:post-excerpt {"moreText":"","excerptLength":30,"style":{"typography":{"fontSize":"1.15rem","lineHeight":"1.6"},"spacing":{"margin":{"top":"0","bottom":"24px"}}},"textColor":"theme-palette4"} /-->

<!-- wp:kadence/advancedbtn {"hAlign":"left","uniqueID":"hah_3"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnshah_3"><!-- wp:kadence/singlebtn {"uniqueID":"hah_4","text":"{{lb.cta_label}}","link":"#lb-cta_url","borderRadius":[8,8,8,8],"icon":"fe_arrowRight","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"hah_5","text":"Call {{lb.phone}}","link":"#lb-phone_link","borderRadius":[8,8,8,8],"icon":"fe_phone","iconSide":"left","inheritStyles":"outline"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":2,"background":"palette9","borderWidth":["","","",""],"borderRadius":[12,12,12,12],"uniqueID":"hah_6","padding":["md","md","md","md"],"borderStyle":[{"top":["palette7","",1],"right":["palette7","",1],"bottom":["palette7","",1],"left":["palette7","",1],"unit":"px"}],"displayShadow":true,"shadow":[{"color":"","opacity":0.1,"spread":0,"blur":30,"hOffset":0,"vOffset":12,"inset":false}],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnhah_6"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"hah_7","color":"palette3","fontWeight":"800","margin":["","","14",""],"htmlTag":"p","fontSize":[20,"",""]} -->
<p class="kt-adv-headinghah_7 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghah_7">Your local team in {{area.name}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/iconlist {"listStyles":[{"size":[16,"",""],"sizeType":"px","lineHeight":["","",""],"lineType":"px","letterSpacing":"","family":"","google":false,"style":"","weight":"","variant":"","subset":"","loadGoogle":true,"color":"palette4","textTransform":""}],"listGap":10,"listMargin":["0","0","20","0"],"uniqueID":"hah_8"} -->
<div class="wp-block-kadence-iconlist kt-svg-icon-list-items kt-svg-icon-list-itemshah_8 kt-svg-icon-list-columns-1 alignnone"><ul class="kt-svg-icon-list"><!-- wp:kadence/listitem {"uniqueID":"hah_9","icon":"fe_checkCircle","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-hah_9"><span data-name="fe_checkCircle" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">Technicians who live and work nearby</span></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"hah_10","icon":"fe_checkCircle","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-hah_10"><span data-name="fe_checkCircle" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">Same-day appointments available</span></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"hah_11","icon":"fe_checkCircle","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-hah_11"><span data-name="fe_checkCircle" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">Free, no-obligation estimates</span></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"hah_12","icon":"fe_checkCircle","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-hah_12"><span data-name="fe_checkCircle" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">Licensed and insured</span></li>
<!-- /wp:kadence/listitem --></ul></div>
<!-- /wp:kadence/iconlist -->

<!-- wp:kadence/advancedheading {"uniqueID":"hah_13","color":"palette5","margin":["","","2",""],"htmlTag":"p","fontSize":[14,"",""]} -->
<p class="kt-adv-headinghah_13 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghah_13">Call your neighborhood experts</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"hah_14","linkStyle":"none","color":"palette3","fontWeight":"800","htmlTag":"p","link":"#lb-phone_link","linkColor":"palette3","linkHoverColor":"palette1","fontSize":[24,"",22],"icon":"fe_phoneCall","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["22","",""]} -->
<p class="kt-adv-headinghah_14 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghah_14">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
