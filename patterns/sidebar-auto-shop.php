<?php
/**
 * Title: Sidebar (Auto shop)
 * Slug: launch-builder/sidebar-auto-shop
 * Description: For the theme sidebar: a charcoal contact card with a red top edge (phone, email and the main button), then a steel-grey "Our services" card listing the top-level services as compact links with red chevrons.
 * Categories: launch-builder-sidebars, launch-builder
 * Keywords: sidebar, contact, phone, services, links, widget, auto
 * Inserter: no
 * Piece: sidebar
 * Requires: kadence-blocks-pro
 * Look: auto-shop
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"sba_0","columns":1,"colLayout":"equal","bgColor":"palette3","textColor":"palette9","linkColor":"palette9","linkHoverColor":"palette2","inheritMaxWidth":true,"padding":["0","0","0","0"],"margin":["","","24",""],"borderRadius":[2,2,2,2],"borderStyle":[{"top":["palette1","solid",6],"right":["","",""],"bottom":["","",""],"left":["","",""],"unit":"px"}],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"sba_1","padding":["lg","lg","lg","lg"],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnsba_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"sba_2","color":"palette2","letterSpacing":2.5,"fontWeight":"800","textTransform":"uppercase","htmlTag":"p","margin":["","","8",""],"fontSize":[13,"",""],"icon":"fas_wrench","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["14","",""]} -->
<p class="kt-adv-headingsba_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsba_2">Need service?</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sba_3","color":"palette9","fontWeight":"900","textTransform":"uppercase","margin":["","","14",""],"fontSize":[22,"",""],"fontHeight":[1.15,"",""],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingsba_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsba_3">Talk to a technician</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sba_4","color":"palette9","fontWeight":"900","htmlTag":"p","margin":["","","10",""],"link":"#lb-phone_link","linkColor":"palette9","linkHoverColor":"palette2","linkStyle":"none","fontSize":[24,"",""],"icon":"fe_phone","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["20","",""]} -->
<p class="kt-adv-headingsba_4 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsba_4">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:group {"style":{"spacing":{"blockGap":"10px","margin":{"top":"0","bottom":"20px"}}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group" style="margin-top:0;margin-bottom:20px"><!-- wp:kadence/icon {"uniqueID":"sba_5"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconssba_5 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"sba_6","icon":"fe_mail","size":18,"color":"palette1"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-default kt-svg-icon-wrap kt-svg-item-sba_6"><span data-name="fe_mail" data-stroke="2" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"var(\u002d\u002dglobal-palette7)"},":hover":{"color":{"text":"var(\u002d\u002dglobal-palette2)"}}}},"color":{"text":"var(\u002d\u002dglobal-palette7)"},"spacing":{"margin":{"top":"0","bottom":"0"}},"typography":{"fontSize":"15px"}}} -->
<p class="has-text-color has-link-color" style="color:var(--global-palette7);margin-top:0;margin-bottom:0;font-size:15px"><a href="mailto:{{lb.email}}">{{lb.email}}</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","uniqueID":"sba_7","forceFullwidth":true} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnssba_7"><!-- wp:kadence/singlebtn {"uniqueID":"sba_8","text":"{{lb.cta_label}}","link":"#lb-cta_url","borderRadius":[2,2,2,2],"icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->

<!-- wp:kadence/rowlayout {"uniqueID":"sba_9","columns":1,"colLayout":"equal","bgColor":"palette8","textColor":"palette4","linkColor":"palette3","linkHoverColor":"palette1","inheritMaxWidth":true,"padding":["0","0","0","0"],"borderRadius":[2,2,2,2],"borderStyle":[{"top":["palette3","solid",6],"right":["","",""],"bottom":["","",""],"left":["","",""],"unit":"px"}],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"sba_10","padding":["lg","lg","md","lg"],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnsba_10"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"sba_11","color":"palette3","fontWeight":"900","textTransform":"uppercase","margin":["","","10",""],"fontSize":[20,"",""],"fontHeight":[1.15,"",""],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingsba_11 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsba_11">Our services</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"uniqueID":"sba_12","lbList":{"mode":"top","postType":"lb_service","perPage":8,"columns":["1","1","1"],"columnGap":["0","",""],"rowGap":["0","",""]}} -->
<!-- wp:group {"style":{"border":{"bottom":{"color":"var(\u002d\u002dglobal-palette7)","style":"solid","width":"1px"}},"spacing":{"padding":{"top":"11px","bottom":"11px"},"blockGap":"10px"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group" style="border-bottom-color:var(--global-palette7);border-bottom-style:solid;border-bottom-width:1px;padding-top:11px;padding-bottom:11px"><!-- wp:kadence/icon {"uniqueID":"sba_13"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconssba_13 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"sba_14","icon":"fe_chevronRight","size":15,"width":3,"color":"palette1"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-default kt-svg-icon-wrap kt-svg-item-sba_14"><span data-name="fe_chevronRight" data-stroke="3" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":0,"isLink":true,"style":{"color":{"text":"var(\u002d\u002dglobal-palette3)"},"elements":{"link":{"color":{"text":"var(\u002d\u002dglobal-palette3)"},":hover":{"color":{"text":"var(\u002d\u002dglobal-palette1)"}}}},"typography":{"fontSize":"14px","fontWeight":"800","letterSpacing":"0.5px","lineHeight":"1.35","textTransform":"uppercase"},"spacing":{"margin":{"top":"0","bottom":"0"}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
