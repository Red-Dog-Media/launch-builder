<?php
/**
 * Title: Area services list (Landscaping)
 * Slug: launch-builder/area-services-landscaping
 * Description: "Services in" heading with the area name, then that area's service pages in two columns of white pill-shaped rows: a leaf in a soft green circle, the linked page title and a small arrow.
 * Categories: launch-builder-lists, launch-builder
 * Keywords: area services, service area, services, list, grid, landscaping
 * Piece: area-services
 * Look: landscaping
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"asl_0","columns":1,"colLayout":"equal","padding":["lg","","lg",""],"mobilePadding":["md","","md",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"asl_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnasl_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"asl_2","color":"palette1","fontWeight":"600","margin":["","","8",""],"htmlTag":"p","fontSize":[15,"",""],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["16","",""]} -->
<p class="kt-adv-headingasl_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingasl_2">Local services</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"asl_3","color":"palette3","fontWeight":"700","margin":["","","10",""],"fontSize":[34,30,27],"fontHeight":[1.2,null,null],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingasl_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingasl_3">Services in {{area.name}}</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"asl_4","color":"palette4","margin":["","","24",""],"htmlTag":"p","fontSize":[17,"",16],"fontHeight":[1.6,null,null],"fontHeightType":"em"} -->
<p class="kt-adv-headingasl_4 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingasl_4">The same friendly crew and careful work, right here in {{area.name}}.</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"lbList":{"mode":"area-services","postType":"lb_area","perPage":24,"columns":["2","2","1"],"columnGap":["16","14",""],"rowGap":["14","12","10"]}} -->
<!-- wp:group {"style":{"border":{"radius":"100px","width":"1px"},"spacing":{"padding":{"top":"8px","right":"20px","bottom":"8px","left":"8px"},"blockGap":"14px"},"dimensions":{"minHeight":"100%"}},"backgroundColor":"theme-palette9","borderColor":"theme-palette7","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group has-border-color has-theme-palette-7-border-color has-theme-palette-9-background-color has-background" style="border-width:1px;border-radius:100px;min-height:100%;padding-top:8px;padding-right:20px;padding-bottom:8px;padding-left:8px"><!-- wp:kadence/icon {"uniqueID":"asl_5"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconsasl_5 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"asl_6","icon":"fas_leaf","size":17,"color":"palette1","background":"palette8","borderRadius":50,"borderWidth":0,"padding":[11,11,11,11],"style":"stacked"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-stacked kt-svg-icon-wrap kt-svg-item-asl_6"><span data-name="fas_leaf" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":3,"isLink":true,"style":{"layout":{"selfStretch":"fill","flexSize":null},"typography":{"fontSize":"17px","fontWeight":"600","lineHeight":"1.35"},"spacing":{"margin":{"top":"0","bottom":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|theme-palette3"},":hover":{"color":{"text":"var:preset|color|theme-palette1"}}}}}} /-->

<!-- wp:kadence/icon {"uniqueID":"asl_7"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconsasl_7 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"asl_8","icon":"fe_arrowRight","size":18,"color":"palette1"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-default kt-svg-icon-wrap kt-svg-item-asl_8"><span data-name="fe_arrowRight" data-stroke="2" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon --></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
