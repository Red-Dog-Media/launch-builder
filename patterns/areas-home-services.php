<?php
/**
 * Title: Service area list (Home services)
 * Slug: launch-builder/areas-home-services
 * Description: "Areas We Serve" heading and a short note to call if a town is missing, then a three-column grid of light rounded cards, each with a round blue map pin and the area name as a link.
 * Categories: launch-builder-lists, launch-builder
 * Keywords: service areas, areas, towns, cities, locations, list, grid, map pin, home services
 * Piece: areas
 * Look: home-services
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"arh_0","columns":1,"colLayout":"equal","padding":["md","0","md","0"],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"arh_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnarh_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"arh_2","color":"palette3","fontWeight":"800","margin":["","","8",""],"fontSize":[32,28,26],"fontHeight":[1.2,null,null],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingarh_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingarh_2">Areas We Serve</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"arh_3","color":"palette4","margin":["","","24",""],"htmlTag":"p","fontSize":[17,"",16]} -->
<p class="kt-adv-headingarh_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingarh_3">Find your town below. Don't see it? Call <a href="#lb-phone_link">{{lb.phone}}</a>, we may still be able to help.</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"lbList":{"mode":"top","postType":"lb_area","perPage":48,"columns":["3","2","1"],"columnGap":["20","",""],"rowGap":["16","",""]}} -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"16px","right":"18px","bottom":"16px","left":"18px"},"blockGap":"14px"},"border":{"radius":"10px","width":"1px"},"dimensions":{"minHeight":"100%"}},"backgroundColor":"theme-palette8","borderColor":"theme-palette7","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group has-border-color has-theme-palette-7-border-color has-theme-palette-8-background-color has-background" style="border-width:1px;border-radius:10px;min-height:100%;padding-top:16px;padding-right:18px;padding-bottom:16px;padding-left:18px"><!-- wp:kadence/icon {"uniqueID":"arh_4"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconsarh_4 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"arh_5","icon":"fe_mapPin","size":14,"width":2.5,"color":"palette9","background":"palette1","border":"palette1","borderRadius":50,"borderWidth":0,"padding":[7,7,7,7],"style":"stacked"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-stacked kt-svg-icon-wrap kt-svg-item-arh_5"><span data-name="fe_mapPin" data-stroke="2.5" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":3,"isLink":true,"style":{"typography":{"fontSize":"1.0625rem","fontWeight":"700","lineHeight":"1.35"},"spacing":{"margin":{"top":"0","bottom":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|theme-palette3"},":hover":{"color":{"text":"var:preset|color|theme-palette1"}}}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
