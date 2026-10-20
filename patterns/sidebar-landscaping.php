<?php
/**
 * Title: Sidebar (Landscaping)
 * Slug: launch-builder/sidebar-landscaping
 * Description: A rounded contact card on a deep green photo (phone, email and a full-width pill button), then "Our services" as a stack of soft green pills, each with a leaf and the linked service name.
 * Categories: launch-builder-sidebars, launch-builder
 * Keywords: sidebar, contact, phone, services, widget, landscaping
 * Inserter: no
 * Piece: sidebar
 * Requires: kadence-blocks-pro
 * Look: landscaping
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"sbl_0","columns":1,"colLayout":"equal","bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","overlay":"palette3","overlayOpacity":82,"textColor":"palette9","borderRadius":[24,24,24,24],"padding":["0","","0",""],"margin":["","","md",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"sbl_1","padding":["lg","md","lg","md"],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnsbl_1"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"sbl_2","color":"palette7","fontWeight":"600","margin":["","","6",""],"htmlTag":"p","fontSize":[14,"",""],"icon":"fas_leaf","iconColor":"palette2","iconPadding":["","8","",""],"iconSize":["14","",""]} -->
<p class="kt-adv-headingsbl_2 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbl_2">Free estimate</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sbl_3","color":"palette9","fontWeight":"700","margin":["","","14",""],"fontSize":[22,"",20],"fontHeight":[1.25,null,null],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingsbl_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbl_3">Talk to a local expert</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sbl_4","color":"palette9","fontWeight":"700","margin":["","","8",""],"htmlTag":"p","link":"#lb-phone_link","linkStyle":"none","fontSize":[21,"",""],"icon":"fe_phone","iconColor":"palette2","iconPadding":["","8","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headingsbl_4 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbl_4">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sbl_5","color":"palette8","margin":["","","20",""],"htmlTag":"p","fontSize":[15,"",""],"icon":"fe_mail","iconColor":"palette2","iconPadding":["","8","",""],"iconSize":["16","",""]} -->
<p class="kt-adv-headingsbl_5 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbl_5">{{lb.email}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","uniqueID":"sbl_6"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnssbl_6"><!-- wp:kadence/singlebtn {"uniqueID":"sbl_7","text":"{{lb.cta_label}}","link":"#lb-cta_url","widthType":"full","borderRadius":[100,100,100,100],"icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->

<!-- wp:kadence/rowlayout {"uniqueID":"sbl_8","columns":1,"colLayout":"equal","padding":["0","","0",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"sbl_9","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnsbl_9"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"sbl_10","color":"palette3","fontWeight":"700","margin":["","","14",""],"fontSize":[20,"",""],"fontHeight":[1.25,null,null],"fontHeightType":"em"} -->
<h2 class="kt-adv-headingsbl_10 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbl_10">Our services</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"lbList":{"mode":"top","postType":"lb_service","perPage":8,"columns":["1","1","1"],"columnGap":["","",""],"rowGap":["10","10","8"]}} -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"6px","right":"18px","bottom":"6px","left":"6px"},"blockGap":"10px"},"border":{"radius":"100px"}},"backgroundColor":"theme-palette8","layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group has-theme-palette-8-background-color has-background" style="border-radius:100px;padding-top:6px;padding-right:18px;padding-bottom:6px;padding-left:6px"><!-- wp:kadence/icon {"uniqueID":"sbl_11"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconssbl_11 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"sbl_12","icon":"fas_leaf","size":12,"color":"palette1","background":"palette9","borderRadius":50,"borderWidth":0,"padding":[9,9,9,9],"style":"stacked"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-stacked kt-svg-icon-wrap kt-svg-item-sbl_12"><span data-name="fas_leaf" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":0,"isLink":true,"style":{"typography":{"fontSize":"16px","fontWeight":"600","lineHeight":"1.4"},"spacing":{"margin":{"top":"0","bottom":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|theme-palette3"},":hover":{"color":{"text":"var:preset|color|theme-palette1"}}}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
