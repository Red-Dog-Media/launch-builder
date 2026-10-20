<?php
/**
 * Title: Header (Auto shop)
 * Slug: launch-builder/header-auto-shop
 * Description: Charcoal top bar with the shop address and email, then a white bar with the logo, a heavy uppercase menu, a bold click-to-call number and a square red button, finished with a red stripe. White slide-out menu with the phone and button on tablet and mobile.
 * Categories: launch-builder-headers, launch-builder
 * Keywords: header, navigation, menu, phone, button, dark, auto
 * Inserter: no
 * Piece: header
 * Look: auto-shop
 * Header-Meta: {"_kad_header_background":{"color":"palette9","image":"","imageID":"","position":"center center","size":"cover","repeat":"no-repeat","attachment":"scroll","type":"normal","gradient":""},"_kad_header_typography":{"color":"palette3","size":["15","",""],"sizeType":"px","lineHeight":["","",""],"lineType":"","letterSpacing":["0.5","",""],"letterType":"px","textTransform":"uppercase","family":"","google":false,"style":"","weight":"800","variant":"","subset":"","loadGoogle":true},"_kad_header_border":[{"top":["","solid",""],"right":["","solid",""],"bottom":["palette1","solid",4],"left":["","solid",""],"unit":"px"}],"_kad_header_isSticky":"0"}
 */
?>
<!-- wp:kadence/header {"uniqueID":"hda_0"} -->
<!-- wp:kadence/header-container-desktop {"uniqueID":"hda_1"} -->
<!-- wp:kadence/header-row {"uniqueID":"hda_2","location":"top","background":{"color":"palette3","image":"","imageID":"","position":"center center","size":"cover","repeat":"no-repeat","attachment":"scroll","type":"normal","gradient":""},"padding":[7,"",7,""],"itemGap":"md"} -->
<!-- wp:kadence/header-section {"uniqueID":"hda_3","location":"left","metadata":{"name":"Left Section"}} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_4","location":"left","metadata":{"name":"Left"}} -->
<!-- wp:kadence/advancedheading {"uniqueID":"hda_5","color":"palette9","letterSpacing":1,"fontWeight":"700","textTransform":"uppercase","htmlTag":"p","link":"#lb-maps_url","linkColor":"palette9","linkHoverColor":"palette2","linkStyle":"none","fontSize":[13,"",""],"icon":"fe_mapPin","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["15","",""]} -->
<p class="kt-adv-headinghda_5 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghda_5">{{lb.address}}</p>
<!-- /wp:kadence/advancedheading -->
<!-- /wp:kadence/header-column -->

<!-- wp:kadence/header-column {"uniqueID":"hda_6","location":"center-left","metadata":{"name":"Center Left"}} /-->
<!-- /wp:kadence/header-section -->

<!-- wp:kadence/header-column {"uniqueID":"hda_7","location":"center","metadata":{"name":"Center"}} /-->

<!-- wp:kadence/header-section {"uniqueID":"hda_8","location":"right","metadata":{"name":"Right Section"}} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_9","location":"center-right","metadata":{"name":"Center Right"}} /-->

<!-- wp:kadence/header-column {"uniqueID":"hda_10","location":"right","metadata":{"name":"Right"}} -->
<!-- wp:kadence/icon {"uniqueID":"hda_11"} -->
<div class="wp-block-kadence-icon kt-svg-icons kt-svg-iconshda_11 alignnone"><!-- wp:kadence/single-icon {"uniqueID":"hda_12","icon":"fe_mail","size":15,"color":"palette1"} -->
<div class="wp-block-kadence-single-icon kt-svg-style-default kt-svg-icon-wrap kt-svg-item-hda_12"><span data-name="fe_mail" data-stroke="2" class="kadence-dynamic-icon"></span></div>
<!-- /wp:kadence/single-icon --></div>
<!-- /wp:kadence/icon -->

<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"var(\u002d\u002dglobal-palette9)"},"typography":{"textDecoration":"none"}}},"color":{"text":"var(\u002d\u002dglobal-palette9)"},"spacing":{"margin":{"top":"0","bottom":"0"}},"typography":{"fontSize":"13px","fontWeight":"700","letterSpacing":"1px","textTransform":"none"}}} -->
<p class="has-text-color has-link-color" style="color:var(--global-palette9);margin-top:0;margin-bottom:0;font-size:13px;font-weight:700;letter-spacing:1px;text-transform:none"><a href="mailto:{{lb.email}}">{{lb.email}}</a></p>
<!-- /wp:paragraph -->
<!-- /wp:kadence/header-column -->
<!-- /wp:kadence/header-section -->
<!-- /wp:kadence/header-row -->

<!-- wp:kadence/header-row {"uniqueID":"hda_13","location":"center","minHeight":88,"vAlign":"center","itemGap":"md"} -->
<!-- wp:kadence/header-section {"uniqueID":"hda_14","location":"left","metadata":{"name":"Left Section"}} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_15","location":"left","metadata":{"name":"Left"}} -->
<!-- wp:kadence/identity {"uniqueID":"hda_16"} -->
<!-- wp:site-logo {"width":130,"isLink":false,"style":{"spacing":{"margin":{"right":"var:preset|spacing|40"}}}} /-->

<!-- wp:site-title {"level":0,"isLink":false,"style":{"color":{"text":"var(\u002d\u002dglobal-palette3)"},"typography":{"fontSize":"22px","fontWeight":"900","textTransform":"uppercase","letterSpacing":"1px","lineHeight":"1.1"}}} /-->
<!-- /wp:kadence/identity -->
<!-- /wp:kadence/header-column -->

<!-- wp:kadence/header-column {"uniqueID":"hda_17","location":"center-left","metadata":{"name":"Center Left"}} /-->
<!-- /wp:kadence/header-section -->

<!-- wp:kadence/header-column {"uniqueID":"hda_18","location":"center","metadata":{"name":"Center"}} /-->

<!-- wp:kadence/header-section {"uniqueID":"hda_20","location":"right","metadata":{"name":"Right Section"}} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_21","location":"center-right","metadata":{"name":"Center Right"}} -->
<!-- wp:kadence/navigation {"uniqueID":"hda_19","templateKey":"short"} /-->
<!-- /wp:kadence/header-column -->

<!-- wp:kadence/header-column {"uniqueID":"hda_22","location":"right","metadata":{"name":"Right"}} -->
<!-- wp:kadence/advancedheading {"uniqueID":"hda_23","color":"palette3","letterSpacing":0.5,"fontWeight":"900","htmlTag":"p","link":"#lb-phone_link","linkColor":"palette3","linkHoverColor":"palette1","linkStyle":"none","fontSize":[19,"",""],"icon":"fe_phone","iconColor":"palette1","iconPadding":["","8","",""],"iconSize":["18","",""]} -->
<p class="kt-adv-headinghda_23 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghda_23">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"uniqueID":"hda_24"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnshda_24"><!-- wp:kadence/singlebtn {"uniqueID":"hda_25","text":"{{lb.cta_label}}","link":"#lb-cta_url","sizePreset":"small","borderRadius":[2,2,2,2],"icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn -->
<!-- /wp:kadence/header-column -->
<!-- /wp:kadence/header-section -->
<!-- /wp:kadence/header-row -->

<!-- wp:kadence/header-row {"uniqueID":"hda_26","location":"bottom"} -->
<!-- wp:kadence/header-section {"uniqueID":"hda_27","location":"left","metadata":{"name":"Left Section"}} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_28","location":"left","metadata":{"name":"Left"}} /-->

<!-- wp:kadence/header-column {"uniqueID":"hda_29","location":"center-left","metadata":{"name":"Center Left"}} /-->
<!-- /wp:kadence/header-section -->

<!-- wp:kadence/header-column {"uniqueID":"hda_30","location":"center","metadata":{"name":"Center"}} /-->

<!-- wp:kadence/header-section {"uniqueID":"hda_31","location":"right","metadata":{"name":"Right Section"}} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_32","location":"center-right","metadata":{"name":"Center Right"}} /-->

<!-- wp:kadence/header-column {"uniqueID":"hda_33","location":"right","metadata":{"name":"Right"}} /-->
<!-- /wp:kadence/header-section -->
<!-- /wp:kadence/header-row -->
<!-- /wp:kadence/header-container-desktop -->

<!-- wp:kadence/header-container-tablet {"uniqueID":"hda_34"} -->
<!-- wp:kadence/header-row {"uniqueID":"hda_35","location":"top"} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_36","location":"tablet-left","metadata":{"name":"Left"}} /-->

<!-- wp:kadence/header-column {"uniqueID":"hda_37","location":"tablet-center","metadata":{"name":"Center"}} /-->

<!-- wp:kadence/header-column {"uniqueID":"hda_38","location":"tablet-right","metadata":{"name":"Right"}} /-->
<!-- /wp:kadence/header-row -->

<!-- wp:kadence/header-row {"uniqueID":"hda_39","location":"center","minHeight":72,"vAlign":"center"} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_40","location":"tablet-left","metadata":{"name":"Left"}} -->
<!-- wp:kadence/off-canvas-trigger {"uniqueID":"hda_41","iconSizeTablet":26,"iconColorTablet":"palette3","iconColorHoverTablet":"palette1","iconBackgroundColorTablet":"palette9","iconBackgroundColorHoverTablet":"palette9","label":"Menu"} /-->
<!-- /wp:kadence/header-column -->

<!-- wp:kadence/header-column {"uniqueID":"hda_42","location":"tablet-center","metadata":{"name":"Center"}} -->
<!-- wp:kadence/identity {"uniqueID":"hda_43"} -->
<!-- wp:site-logo {"width":110,"isLink":false,"style":{"spacing":{"margin":{"right":"var:preset|spacing|40"}}}} /-->

<!-- wp:site-title {"level":0,"isLink":false,"style":{"color":{"text":"var(\u002d\u002dglobal-palette3)"},"typography":{"fontSize":"19px","fontWeight":"900","textTransform":"uppercase","letterSpacing":"1px","lineHeight":"1.1"}}} /-->
<!-- /wp:kadence/identity -->
<!-- /wp:kadence/header-column -->

<!-- wp:kadence/header-column {"uniqueID":"hda_44","location":"tablet-right","metadata":{"name":"Right"}} -->
<!-- wp:kadence/advancedbtn {"uniqueID":"hda_45"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnshda_45"><!-- wp:kadence/singlebtn {"uniqueID":"hda_46","text":"Call","link":"#lb-phone_link","sizePreset":"small","borderRadius":[2,2,2,2],"icon":"fe_phone","iconSide":"left","onlyIcon":[true,"",""],"label":"Call {{lb.phone}}","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn -->
<!-- /wp:kadence/header-column -->
<!-- /wp:kadence/header-row -->

<!-- wp:kadence/header-row {"uniqueID":"hda_47","location":"bottom"} -->
<!-- wp:kadence/header-column {"uniqueID":"hda_48","location":"tablet-left","metadata":{"name":"Left"}} /-->

<!-- wp:kadence/header-column {"uniqueID":"hda_49","location":"tablet-center","metadata":{"name":"Center"}} /-->

<!-- wp:kadence/header-column {"uniqueID":"hda_50","location":"tablet-right","metadata":{"name":"Right"}} /-->
<!-- /wp:kadence/header-row -->
<!-- /wp:kadence/header-container-tablet -->

<!-- wp:kadence/off-canvas {"uniqueID":"hda_51","maxWidthTablet":700,"containerMaxWidthTablet":350,"backgroundColorTablet":"palette9","paddingTablet":[24,24,24,24],"hAlignTablet":"left","vAlignTablet":"center","closeIconSizeTablet":26,"closeIconColorTablet":"palette3","closeIconColorHoverTablet":"palette1","border":[{"top":["palette1","solid",4],"right":["","",""],"bottom":["","",""],"left":["","",""],"unit":"px"}]} -->
<!-- wp:group {"style":{"color":{"text":"var(\u002d\u002dglobal-palette3)"},"typography":{"fontSize":"18px","fontWeight":"700","letterSpacing":"1px","textTransform":"uppercase"}},"layout":{"type":"default"}} -->
<div class="wp-block-group has-text-color" style="color:var(--global-palette3);font-size:18px;font-weight:700;letter-spacing:1px;text-transform:uppercase"><!-- wp:kadence/navigation {"uniqueID":"hda_52","templateKey":"long-vertical"} /--></div>
<!-- /wp:group -->

<!-- wp:kadence/advancedheading {"uniqueID":"hda_53","color":"palette3","fontWeight":"900","margin":["24","","12",""],"htmlTag":"p","link":"#lb-phone_link","linkColor":"palette3","linkHoverColor":"palette1","linkStyle":"none","fontSize":[22,"",""],"icon":"fe_phone","iconColor":"palette1","iconPadding":["","10","",""],"iconSize":["20","",""]} -->
<p class="kt-adv-headinghda_53 wp-block-kadence-advancedheading" data-kb-block="kb-adv-headinghda_53">{{lb.phone}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"hAlign":"left","uniqueID":"hda_54"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btnshda_54"><!-- wp:kadence/singlebtn {"uniqueID":"hda_55","text":"{{lb.cta_label}}","link":"#lb-cta_url","borderRadius":[2,2,2,2],"icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn -->
<!-- /wp:kadence/off-canvas -->
<!-- /wp:kadence/header -->
