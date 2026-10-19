<?php
/**
 * Title: Map: map + contact details
 * Slug: launch-builder/map-contact
 * Description: Map on one side; address, phone, hours and email on the other.
 * Categories: launch-builder-maps, launch-builder
 * Keywords: map, contact, directions, location
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"48bfd9_0","tabletLayout":"row","colLayout":"equal","bgColor":"palette9","align":"full","verticalAlignment":"middle","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-sec"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"48bfd9_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column48bfd9_0-0"><div class="kt-inside-inner-col"><!-- wp:kadence/googlemaps {"uniqueID":"48bfd9_0-0-0","heightDesktop":440,"heightMobile":300,"zoom":"13","location":"{{lb.address}}","kbVersion":2} /--></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":2,"borderWidth":["","","",""],"uniqueID":"48bfd9_0-1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column48bfd9_0-1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"48bfd9_0-1-0","color":"palette1","margin":["","","8",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[14,"",""],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["18","",""],"className":"lbd-eyebrow"} -->
<p class="kt-adv-heading48bfd9_0-1-0 lbd-eyebrow wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading48bfd9_0-1-0">Visit us</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"48bfd9_0-1-1","color":"palette3","lineHeight":"","lineType":"em","tabLineHeight":"","mobileLineHeight":"","margin":["","","20",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"fontSize":[36,"",28],"fontHeight":[1.15,null,null],"fontHeightType":"em","className":"lbd-title"} -->
<h2 class="kt-adv-heading48bfd9_0-1-1 lbd-title wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading48bfd9_0-1-1">Come see us in person</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/iconlist {"listStyles":[{"size":[18,"",""],"sizeType":"px","lineHeight":["","",""],"lineType":"px","letterSpacing":"","family":"","google":false,"style":"","weight":"","variant":"","subset":"","loadGoogle":true,"color":"palette3","textTransform":""}],"listGap":16,"uniqueID":"48bfd9_0-1-2"} -->
<div class="wp-block-kadence-iconlist kt-svg-icon-list-items kt-svg-icon-list-items48bfd9_0-1-2 kt-svg-icon-list-columns-1 alignnone"><ul class="kt-svg-icon-list"><!-- wp:kadence/listitem {"uniqueID":"48bfd9_0-1-2-0","icon":"fe_mapPin","link":"#","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-48bfd9_0-1-2-0"><a href="#" class="kt-svg-icon-link"><span data-name="fe_mapPin" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.address}}</span></a></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"48bfd9_0-1-2-1","icon":"fe_phone","link":"#lb-phone_link","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-48bfd9_0-1-2-1"><a href="#lb-phone_link" class="kt-svg-icon-link"><span data-name="fe_phone" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.phone}}</span></a></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"48bfd9_0-1-2-2","icon":"fe_clock","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-48bfd9_0-1-2-2"><span data-name="fe_clock" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.hours}}</span></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"48bfd9_0-1-2-3","icon":"fe_mail","link":"mailto:{{lb.email}}","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-48bfd9_0-1-2-3"><a href="mailto:{{lb.email}}" class="kt-svg-icon-link"><span data-name="fe_mail" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.email}}</span></a></li>
<!-- /wp:kadence/listitem --></ul></div>
<!-- /wp:kadence/iconlist -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","btnCount":2,"uniqueID":"48bfd9_0-1-3","margin":[{"desk":["28","","",""],"tablet":["","","",""],"mobile":["","","",""]}]} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns48bfd9_0-1-3"><!-- wp:kadence/singlebtn {"uniqueID":"48bfd9_0-1-3-0","text":"Get Directions","link":"#lb-maps_url","icon":"fe_navigation","iconSide":"left","inheritStyles":"inherit"} /-->

<!-- wp:kadence/singlebtn {"uniqueID":"48bfd9_0-1-3-1","text":"Call Now","link":"#lb-phone_link","icon":"fe_phone","iconSide":"left","inheritStyles":"outline"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
