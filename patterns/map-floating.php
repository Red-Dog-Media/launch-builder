<?php
/**
 * Title: Map: full-width with floating card
 * Slug: launch-builder/map-floating
 * Description: Wide map with an info card overlapping its bottom edge.
 * Categories: launch-builder-maps, launch-builder
 * Keywords: map, contact, hours, floating card
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"a07ef1_0","columns":1,"colLayout":"equal","align":"full","padding":["","","",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"a07ef1_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columna07ef1_0-0"><div class="kt-inside-inner-col"><!-- wp:kadence/googlemaps {"uniqueID":"a07ef1_0-0-0","heightDesktop":520,"heightMobile":340,"zoom":"14","location":"{{lb.address}}","kbVersion":2} /--></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->

<!-- wp:kadence/rowlayout {"uniqueID":"a07ef1_1","columns":1,"colLayout":"equal","maxWidth":560,"padding":["","","xl",""],"margin":[-140,"","",""],"kbVersion":2} -->
<!-- wp:kadence/column {"background":"palette9","borderWidth":["","","",""],"uniqueID":"a07ef1_1-0","kbVersion":2,"className":"lbd-card lbd-card\u002d\u002doutline lbd-card\u002d\u002draised"} -->
<div class="wp-block-kadence-column kadence-columna07ef1_1-0 lbd-card lbd-card--outline lbd-card--raised"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"a07ef1_1-0-0","color":"palette1","margin":["","","8",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[14,"",""],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["18","",""],"className":"lbd-eyebrow"} -->
<p class="kt-adv-headinga07ef1_1-0-0 lbd-eyebrow wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinga07ef1_1-0-0">Visit us</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"a07ef1_1-0-1","color":"palette3","margin":["","","16",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"fontSize":[30,"",""],"className":"lbd-title"} -->
<h2 class="kt-adv-headinga07ef1_1-0-1 lbd-title wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinga07ef1_1-0-1">Our location</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/iconlist {"listStyles":[{"size":[18,"",""],"sizeType":"px","lineHeight":["","",""],"lineType":"px","letterSpacing":"","family":"","google":false,"style":"","weight":"","variant":"","subset":"","loadGoogle":true,"color":"palette3","textTransform":""}],"listGap":16,"uniqueID":"a07ef1_1-0-2"} -->
<div class="wp-block-kadence-iconlist kt-svg-icon-list-items kt-svg-icon-list-itemsa07ef1_1-0-2 kt-svg-icon-list-columns-1 alignnone"><ul class="kt-svg-icon-list"><!-- wp:kadence/listitem {"uniqueID":"a07ef1_1-0-2-0","icon":"fe_mapPin","link":"#","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-a07ef1_1-0-2-0"><a href="#" class="kt-svg-icon-link"><span data-name="fe_mapPin" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.address}}</span></a></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"a07ef1_1-0-2-1","icon":"fe_phone","link":"#lb-phone_link","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-a07ef1_1-0-2-1"><a href="#lb-phone_link" class="kt-svg-icon-link"><span data-name="fe_phone" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.phone}}</span></a></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"a07ef1_1-0-2-2","icon":"fe_clock","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-a07ef1_1-0-2-2"><span data-name="fe_clock" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.hours}}</span></li>
<!-- /wp:kadence/listitem -->

<!-- wp:kadence/listitem {"uniqueID":"a07ef1_1-0-2-3","icon":"fe_mail","link":"mailto:{{lb.email}}","color":"palette1"} -->
<li class="wp-block-kadence-listitem kt-svg-icon-list-item-wrap kt-svg-icon-list-item-a07ef1_1-0-2-3"><a href="mailto:{{lb.email}}" class="kt-svg-icon-link"><span data-name="fe_mail" data-stroke="USE_PARENT_DEFAULT_WIDTH" data-class="kt-svg-icon-list-single" class="kadence-dynamic-icon"></span><span class="kt-svg-icon-list-text">{{lb.email}}</span></a></li>
<!-- /wp:kadence/listitem --></ul></div>
<!-- /wp:kadence/iconlist -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","uniqueID":"a07ef1_1-0-3","margin":[{"desk":["24","","",""],"tablet":["","","",""],"mobile":["","","",""]}]} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnsa07ef1_1-0-3"><!-- wp:kadence/singlebtn {"uniqueID":"a07ef1_1-0-3-0","text":"Get Directions","link":"#lb-maps_url","icon":"fe_navigation","iconSide":"left","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
