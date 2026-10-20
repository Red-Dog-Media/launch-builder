<?php
/**
 * Title: Sidebar (Home services)
 * Slug: launch-builder/sidebar-home-services
 * Description: Two rounded sidebar cards: a light-tint contact card with a round blue phone icon, the phone number, email and a full-width main button; then a white "Our Services" card listing your services as check-mark links.
 * Categories: launch-builder-sidebars, launch-builder
 * Keywords: sidebar, widget, contact, phone, email, button, services, links, home services
 * Inserter: no
 * Piece: sidebar
 * Look: home-services
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"sbh_0","columns":1,"colLayout":"equal","bgColor":"palette8","padding":["0","0","0","0"],"margin":["","","md",""],"borderRadius":[12,12,12,12],"borderStyle":[{"top":["palette7","solid",1],"right":["palette7","solid",1],"bottom":["palette7","solid",1],"left":["palette7","solid",1],"unit":"px"}],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"sbh_1","padding":["md","md","md","md"],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnsbh_1"><div class="kt-inside-inner-col"><!-- wp:kadence/icon {"uniqueID":"sbh_2","textAlignment":"left"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconssbh_2 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"sbh_3","icon":"fe_phoneCall","size":20,"width":2,"color":"palette9","background":"palette1","border":"palette1","borderRadius":50,"borderWidth":0,"padding":[12,12,12,12],"style":"stacked"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-stacked kt-svg-icon-wrap kt-svg-item-sbh_3"><span data-name="fe_phoneCall" data-stroke="2" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:kadence/advancedheading {"uniqueID":"sbh_4","color":"palette3","fontWeight":"800","margin":["14","","6",""],"htmlTag":"p","fontSize":[21,"",""]} -->
<p class="kt-adv-headingsbh_4 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbh_4">Need Help Today?</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sbh_5","color":"palette4","margin":["","","14",""],"htmlTag":"p","fontSize":[15,"",""]} -->
<p class="kt-adv-headingsbh_5 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbh_5">Talk to a friendly expert. Free estimates and no pressure.</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sbh_6","linkStyle":"none","color":"palette3","fontWeight":"800","margin":["","","6",""],"htmlTag":"p","link":"#lb-phone_link","linkColor":"palette3","linkHoverColor":"palette1","fontSize":[24,"",""]} -->
<a href="#lb-phone_link" class="kb-advanced-heading-link kt-adv-heading-linksbh_6 hls-none"><p class="kt-adv-headingsbh_6 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbh_6">{{lb.phone}}</p></a>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"sbh_7","linkStyle":"none","color":"palette4","margin":["","","18",""],"htmlTag":"p","linkColor":"palette4","linkHoverColor":"palette1","fontSize":[15,"",""],"icon":"fe_mail","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["16","",""]} -->
<p class="kt-adv-headingsbh_7 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbh_7"><a href="mailto:{{lb.email}}">{{lb.email}}</a></p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","forceFullwidth":true,"uniqueID":"sbh_8"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnssbh_8"><!-- wp:kadence/singlebtn {"uniqueID":"sbh_9","text":"{{lb.cta_label}}","link":"#lb-cta_url","borderRadius":[8,8,8,8],"icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->

<!-- wp:kadence/rowlayout {"uniqueID":"sbh_10","columns":1,"colLayout":"equal","bgColor":"palette9","padding":["0","0","0","0"],"borderRadius":[12,12,12,12],"borderStyle":[{"top":["palette7","solid",1],"right":["palette7","solid",1],"bottom":["palette7","solid",1],"left":["palette7","solid",1],"unit":"px"}],"kbVersion":2} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"sbh_11","padding":["md","md","md","md"],"kbVersion":2} -->
<div class="wp-block-kadence-column kadence-columnsbh_11"><div class="kt-inside-inner-col"><!-- wp:kadence/advancedheading {"uniqueID":"sbh_12","color":"palette3","fontWeight":"800","margin":["","","14",""],"fontSize":[20,"",""]} -->
<h2 class="kt-adv-headingsbh_12 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headingsbh_12">Our Services</h2>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/query {"lbList":{"mode":"top","postType":"lb_service","perPage":8,"columns":["1","1","1"],"columnGap":["0","",""],"rowGap":["10","",""]}} -->
<!-- wp:group {"style":{"spacing":{"blockGap":"10px"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:kadence/icon {"uniqueID":"sbh_13"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconssbh_13 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"sbh_14","icon":"fe_checkCircle","size":18,"width":2,"color":"palette1"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-default kt-svg-icon-wrap kt-svg-item-sbh_14"><span data-name="fe_checkCircle" data-stroke="2" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:post-title {"level":0,"isLink":true,"style":{"typography":{"fontSize":"1rem","fontWeight":"600","lineHeight":"1.45"},"spacing":{"margin":{"top":"0","bottom":"0"}},"elements":{"link":{"color":{"text":"var:preset|color|theme-palette4"},":hover":{"color":{"text":"var:preset|color|theme-palette1"}}}}}} /--></div>
<!-- /wp:group -->
<!-- /wp:kadence/query --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
