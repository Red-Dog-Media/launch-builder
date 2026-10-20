<?php
/**
 * Title: Sub-service list (Auto shop)
 * Slug: launch-builder/subservices-auto-shop
 * Description: "Service menu" tag and an uppercase "Our {service} services" heading over a grid of charcoal tiles, one per sub-service: a red square check and the linked name, with a red edge.
 * Categories: launch-builder-lists, launch-builder
 * Keywords: services, sub-services, list, checklist, grid, auto
 * Piece: subservices
 * Requires: kadence-blocks-pro
 * Look: auto-shop
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"ssa_0","columns":1,"colLayout":"equal","inheritMaxWidth":true,"padding":["lg","0","lg","0"],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"ssa_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnssa_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"ssa_2","color":"palette1","letterSpacing":3,"fontWeight":"800","textTransform":"uppercase","htmlTag":"p","margin":["","","12",""],"padding":["2","","2","12"],"fontSize":[13,"",""],"fontHeight":[1.3,"",""],"fontHeightType":"em","borderStyle":[{"top":["","",""],"right":["","",""],"bottom":["","",""],"left":["palette1","solid",4],"unit":"px"}]} -->
<p class="kt-adv-headingssa_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingssa_2">Service menu</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"ssa_3","color":"palette3","fontWeight":"900","textTransform":"uppercase","margin":["0","","26",""],"fontSize":[36,32,28],"fontHeight":[1.1,"",""],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingssa_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingssa_3">Our {{page.title}} services</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"uniqueID":"ssa_4","lbList":{"mode":"children","postType":"lb_service","perPage":24,"columns":["3","2","1"],"columnGap":["14","14",""],"rowGap":["14","14","10"]}} -->
<!-- wp:group {"style":{"border":{"radius":"2px","left":{"color":"var(\u002d\u002dglobal-palette1)","style":"solid","width":"4px"}},"color":{"background":"var(\u002d\u002dglobal-palette3)"},"dimensions":{"minHeight":"100%"},"spacing":{"padding":{"top":"16px","right":"18px","bottom":"16px","left":"16px"},"blockGap":"14px"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group has-background" style="border-radius:2px;border-left-color:var(--global-palette1);border-left-style:solid;border-left-width:4px;background-color:var(--global-palette3);min-height:100%;padding-top:16px;padding-right:18px;padding-bottom:16px;padding-left:16px"><!-- wp:kadence/icon {"uniqueID":"ssa_5"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconsssa_5 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"ssa_6","icon":"fe_check","size":14,"width":3,"color":"palette9","background":"palette1","border":"palette1","borderRadius":0,"borderWidth":0,"padding":[6,6,6,6],"style":"stacked"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-stacked kt-svg-icon-wrap kt-svg-item-ssa_6"><span data-name="fe_check" data-stroke="3" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":3,"isLink":true,"style":{"color":{"text":"var(\u002d\u002dglobal-palette9)"},"elements":{"link":{"color":{"text":"var(\u002d\u002dglobal-palette9)"},":hover":{"color":{"text":"var(\u002d\u002dglobal-palette2)"}}}},"typography":{"fontSize":"16px","fontWeight":"800","letterSpacing":"0.5px","lineHeight":"1.3","textTransform":"uppercase"},"spacing":{"margin":{"top":"0","bottom":"0"}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
