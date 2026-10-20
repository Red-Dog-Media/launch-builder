<?php
/**
 * Title: Footer (Home services)
 * Slug: launch-builder/footer-home-services
 * Description: Navy footer in three columns: logo, a one-line promise and the main button; your services as compact links; phone, email, address and a directions link. A slim bar underneath carries the copyright line.
 * Categories: launch-builder-footers, launch-builder
 * Keywords: footer, navy, services, contact, phone, email, address, directions, home services
 * Inserter: no
 * Piece: footer
 * Look: home-services
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"fth_0","columns":3,"colLayout":"equal","firstColumnWidth":40,"secondColumnWidth":30,"thirdColumnWidth":30,"tabletLayout":"first-row","mobileLayout":"row","bgColor":"palette3","align":"full","textColor":"palette7","linkColor":"palette7","linkHoverColor":"palette9","inheritMaxWidth":true,"padding":["xxl","","xl",""],"mobilePadding":["xl","","lg",""],"columnGutter":"wider","kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"fth_1","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnfth_1"><div class="kt-inside-inner-col"><!-- wp:kadence/identity {"uniqueID":"fth_2"} -->
<!-- wp:site-logo {"width":150,"isLink":false,"style":{"spacing":{"margin":{"right":"var:preset|spacing|40"}}}} /-->

<!-- wp:site-title {"level":0,"isLink":false,"style":{"typography":{"fontSize":"1.375rem","fontWeight":"800"}},"textColor":"theme-palette9"} /-->
<!-- /wp:kadence/identity -->

<!-- wp:kadence/advancedheading {"uniqueID":"fth_3","color":"palette7","margin":["14","","22",""],"htmlTag":"p","fontSize":[16,"",""],"fontHeight":[1.65,null,null],"fontHeightType":"em","maxWidth":["420","",""]} -->
<p class="kt-adv-headingfth_3 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_3">Friendly, licensed technicians for heating, cooling, plumbing and electrical work. Upfront pricing and guaranteed service from {{lb.name}}.</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","uniqueID":"fth_4"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnsfth_4"><!-- wp:kadence/singlebtn {"uniqueID":"fth_5","text":"{{lb.cta_label}}","link":"#lb-cta_url","borderRadius":[8,8,8,8],"icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":2,"borderWidth":["","","",""],"uniqueID":"fth_6","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnfth_6"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"fth_7","color":"palette9","fontWeight":"700","margin":["","","16",""],"fontSize":[18,"",""]} -->
<h2 class="kt-adv-headingfth_7 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_7">Our Services</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"lbList":{"mode":"top","postType":"lb_service","perPage":8,"columns":["1","1","1"],"columnGap":["0","",""],"rowGap":["10","",""]}} -->
<!-- wp:group {"style":{"spacing":{"blockGap":"8px"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:kadence/icon {"uniqueID":"fth_8"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconsfth_8 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"fth_9","icon":"fe_chevronRight","size":14,"width":2.5,"color":"palette7"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-default kt-svg-icon-wrap kt-svg-item-fth_9"><span data-name="fe_chevronRight" data-stroke="2.5" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":0,"isLink":true,"style":{"typography":{"fontSize":"1rem","lineHeight":"1.5"},"spacing":{"margin":{"top":"0","bottom":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|theme-palette7"},":hover":{"color":{"text":"var:preset|color|theme-palette9"}}}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->

<!-- wp:kadence/column {"id":3,"borderWidth":["","","",""],"uniqueID":"fth_10","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnfth_10"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"fth_11","color":"palette9","fontWeight":"700","margin":["","","16",""],"fontSize":[18,"",""]} -->
<h2 class="kt-adv-headingfth_11 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_11">Contact Us</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"fth_12","linkStyle":"none","color":"palette9","fontWeight":"700","margin":["","","12",""],"htmlTag":"p","link":"#lb-phone_link","linkColor":"palette9","linkHoverColor":"palette7","fontSize":[20,"",""],"icon":"fe_phoneCall","iconColor":"palette7","iconPadding":["","10","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headingfth_12 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_12">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"fth_13","linkStyle":"none","color":"palette7","margin":["","","12",""],"htmlTag":"p","linkColor":"palette7","linkHoverColor":"palette9","fontSize":[16,"",""],"icon":"fe_mail","iconColor":"palette7","iconPadding":["","10","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headingfth_13 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_13"><a href="mailto:{{lb.email}}">{{lb.email}}</a></p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"fth_14","color":"palette7","margin":["","","12",""],"htmlTag":"p","fontSize":[16,"",""],"icon":"fe_mapPin","iconColor":"palette7","iconPadding":["","10","",""],"iconSize":["18","",""],"iconVerticalAlign":"flex-start"} -->
<p class="kt-adv-headingfth_14 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_14">{{lb.address}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"fth_15","color":"palette9","fontWeight":"600","htmlTag":"p","link":"#lb-maps_url","linkColor":"palette9","linkHoverColor":"palette7","linkStyle":"underline","fontSize":[15,"",""],"icon":"fe_navigation","iconColor":"palette7","iconPadding":["","10","",""],"iconSize":["16","",""]} -->
<p class="kt-adv-headingfth_15 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_15">Get directions</p>
<!-- /wp:kadence/advancedheading --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->

<!-- wp:kadence/rowlayout {"uniqueID":"fth_16","columns":1,"colLayout":"equal","bgColor":"palette3","align":"full","textColor":"palette7","linkColor":"palette7","linkHoverColor":"palette9","inheritMaxWidth":true,"padding":[20,"",20,""],"borderStyle":[{"top":["palette5","solid",1],"right":["","",""],"bottom":["","",""],"left":["","",""],"unit":"px"}],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"fth_17","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnfth_17"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"fth_18","color":"palette7","htmlTag":"p","fontSize":[14,"",""]} -->
<p class="kt-adv-headingfth_18 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingfth_18">© {{site.year}} {{lb.name}}. All rights reserved.</p>
<!-- /wp:kadence/advancedheading --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
