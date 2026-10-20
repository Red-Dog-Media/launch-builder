<?php
/**
 * Title: Footer (Landscaping)
 * Slug: launch-builder/footer-landscaping
 * Description: A light green footer with softly rounded top corners: your logo, a one-line about and a pill button; your main services as two columns of leaf-marked links; phone, email, address and a directions link; a quiet copyright line.
 * Categories: launch-builder-footers, launch-builder
 * Keywords: footer, services, contact, address, copyright, landscaping
 * Inserter: no
 * Piece: footer
 * Requires: kadence-blocks-pro
 * Look: landscaping
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"ftl_0","columns":3,"tabletLayout":"first-row","mobileLayout":"row","colLayout":"equal","columnGutter":"wider","bgColor":"palette7","align":"full","textColor":"palette4","inheritMaxWidth":true,"borderRadius":[32,32,0,0],"mobileBorderRadius":[20,20,0,0],"padding":["xxl","","lg",""],"tabletPadding":["xl","","md",""],"mobilePadding":["lg","","md",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"ftl_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnftl_1"><div class="kt-inside-inner-col"><!-- wp:kadence/identity {"uniqueID":"ftl_2"} -->
<!-- wp:site-logo {"width":120,"isLink":false,"style":{"spacing":{"margin":{"right":"var:preset|spacing|40"}}}} /-->

<!-- wp:site-title {"level":0,"isLink":false,"textColor":"theme-palette3","style":{"typography":{"fontWeight":"700"}}} /-->
<!-- /wp:kadence/identity -->

<!-- wp:kadence/advancedheading {"uniqueID":"ftl_3","color":"palette4","margin":["16","","24",""],"htmlTag":"p","fontSize":[16,"",15],"fontHeight":[1.65,null,null],"fontHeightType":"em","maxWidth":[400,"",""]} -->
<p class="kt-adv-headingftl_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_3">{{lb.name}} designs, builds and looks after healthy lawns, gardens and outdoor spaces all year round.</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","uniqueID":"ftl_4"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnsftl_4"><!-- wp:kadence/singlebtn {"uniqueID":"ftl_5","text":"{{lb.cta_label}}","link":"#lb-cta_url","sizePreset":"small","borderRadius":[100,100,100,100],"icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":2,"borderWidth":["","","",""],"uniqueID":"ftl_6","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnftl_6"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"ftl_7","color":"palette3","fontWeight":"700","margin":["","","16",""],"fontSize":[18,"",""]} -->
<h2 class="kt-adv-headingftl_7 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_7">Services</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"lbList":{"mode":"top","postType":"lb_service","perPage":8,"columns":["2","2","1"],"columnGap":["16","16",""],"rowGap":["10","10","10"]}} -->
<!-- wp:group {"style":{"spacing":{"blockGap":"8px"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:kadence/icon {"uniqueID":"ftl_8"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconsftl_8 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"ftl_9","icon":"fas_leaf","size":12,"color":"palette1"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-default kt-svg-icon-wrap kt-svg-item-ftl_9"><span data-name="fas_leaf" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":0,"isLink":true,"style":{"typography":{"fontSize":"16px","lineHeight":"1.45"},"spacing":{"margin":{"top":"0","bottom":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|theme-palette4"},":hover":{"color":{"text":"var:preset|color|theme-palette1"}}}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":3,"borderWidth":["","","",""],"uniqueID":"ftl_10","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnftl_10"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"ftl_11","color":"palette3","fontWeight":"700","margin":["","","16",""],"fontSize":[18,"",""]} -->
<h2 class="kt-adv-headingftl_11 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_11">Contact</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"ftl_12","color":"palette3","fontWeight":"700","margin":["","","12",""],"htmlTag":"p","link":"#lb-phone_link","linkStyle":"none","fontSize":[20,"",""],"icon":"fe_phone","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headingftl_12 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_12">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"ftl_13","color":"palette4","margin":["","","12",""],"htmlTag":"p","fontSize":[16,"",""],"icon":"fe_mail","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headingftl_13 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_13">{{lb.email}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"ftl_14","color":"palette4","margin":["","","12",""],"htmlTag":"p","fontSize":[16,"",""],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headingftl_14 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_14">{{lb.address}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"ftl_15","color":"palette3","fontWeight":"600","htmlTag":"p","link":"#lb-maps_url","linkTarget":true,"fontSize":[15,"",""],"icon":"fe_navigation","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["16","",""]} -->
<p class="kt-adv-headingftl_15 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_15">Get directions</p>
<!-- /wp:kadence/advancedheading --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->

<!-- wp:kadence/rowlayout {"uniqueID":"ftl_16","columns":1,"colLayout":"equal","bgColor":"palette7","align":"full","textColor":"palette5","inheritMaxWidth":true,"padding":["0","","0",""],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"ftl_17","padding":["sm","","md",""],"borderStyle":[{"top":["palette9","",1],"right":["","",""],"bottom":["","",""],"left":["","",""],"unit":"px"}],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnftl_17"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"ftl_18","align":"center","color":"palette5","htmlTag":"p","fontSize":[14,"",""]} -->
<p class="kt-adv-headingftl_18 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingftl_18">© {{site.year}} {{lb.name}}. All rights reserved.</p>
<!-- /wp:kadence/advancedheading --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
