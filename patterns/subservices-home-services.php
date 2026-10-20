<?php
/**
 * Title: Sub-service list (Home services)
 * Slug: launch-builder/subservices-home-services
 * Description: "Our {service} Services" heading, a one-line invitation to call, then a three-column grid of light rounded cards, each with a round blue check and the sub-service name as a link.
 * Categories: launch-builder-lists, launch-builder
 * Keywords: sub-services, services, list, grid, checklist, cards, home services
 * Piece: subservices
 * Requires: kadence-blocks-pro
 * Look: home-services
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"ssh_0","columns":1,"colLayout":"equal","padding":["md","0","md","0"],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"ssh_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnssh_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"ssh_2","color":"palette3","fontWeight":"800","margin":["","","8",""],"fontSize":[32,28,26],"fontHeight":[1.2,null,null],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingssh_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingssh_2">Our {{page.title}} Services</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"ssh_3","color":"palette4","margin":["","","24",""],"htmlTag":"p","fontSize":[17,"",16]} -->
<p class="kt-adv-headingssh_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingssh_3">Choose a service to learn more, or call <a href="#lb-phone_link">{{lb.phone}}</a> and we will help you pick the right fix.</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"lbList":{"mode":"children","postType":"lb_service","perPage":24,"columns":["3","2","1"],"columnGap":["20","",""],"rowGap":["16","",""]}} -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"16px","right":"18px","bottom":"16px","left":"18px"},"blockGap":"14px"},"border":{"radius":"10px","width":"1px"},"dimensions":{"minHeight":"100%"}},"backgroundColor":"theme-palette8","borderColor":"theme-palette7","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group has-border-color has-theme-palette-7-border-color has-theme-palette-8-background-color has-background" style="border-width:1px;border-radius:10px;min-height:100%;padding-top:16px;padding-right:18px;padding-bottom:16px;padding-left:18px"><!-- wp:kadence/icon {"uniqueID":"ssh_4"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconsssh_4 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"ssh_5","icon":"fe_check","size":14,"width":3,"color":"palette9","background":"palette1","border":"palette1","borderRadius":50,"borderWidth":0,"padding":[7,7,7,7],"style":"stacked"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-stacked kt-svg-icon-wrap kt-svg-item-ssh_5"><span data-name="fe_check" data-stroke="3" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":3,"isLink":true,"style":{"typography":{"fontSize":"1.0625rem","fontWeight":"700","lineHeight":"1.35"},"spacing":{"margin":{"top":"0","bottom":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|theme-palette3"},":hover":{"color":{"text":"var:preset|color|theme-palette1"}}}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
