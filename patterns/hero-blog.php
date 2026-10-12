<?php
/**
 * Title: Hero: blog (centered title and summary)
 * Slug: launch-builder/hero-blog
 * Description: Centered hero for the blog page and its category, tag and date archives: the page or archive title as the H1, your tagline and the main button.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, blog, archive, news, centered
 * Roles: blog
 * Slot: hero
 * Variant-Of: hero-page
 */
?>
<!-- wp:kadence/rowlayout {"uniqueID":"2bf1b3_0","columns":1,"tabletLayout":"row","colLayout":"equal","minHeight":340,"bgColor":"palette3","bgImg":"<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>","align":"full","verticalAlignment":"middle","textColor":"palette9","inheritMaxWidth":true,"kbVersion":2,"className":"lbd-hero lbd-sec lbd-sec\u002d\u002dtight lbd-sec\u002d\u002ddark"} -->
<!-- wp:kadence/column {"borderWidth":["","","",""],"uniqueID":"2bf1b3_0-0","kbVersion":2} -->
<div class="wp-block-kadence-column kadence-column2bf1b3_0-0"><div class="kt-inside-inner-col"><!-- wp:group {"layout":{"type":"constrained","contentSize":"820px","justifyContent":"center"}} -->
<div class="wp-block-group"><!-- wp:kadence/advancedheading {"level":1,"uniqueID":"2bf1b3_0-0-0-0","align":"center","color":"palette9","lineHeight":"","lineType":"em","tabLineHeight":"","mobileLineHeight":"","fontWeight":"800","margin":["0","","14",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"textTransform":"uppercase","fontSize":[56,"",34],"fontHeight":[1.08,null,null],"fontHeightType":"em"} -->
<h1 class="kt-adv-heading2bf1b3_0-0-0-0 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading2bf1b3_0-0-0-0">{{page.title|Latest articles}}</h1>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedheading {"uniqueID":"2bf1b3_0-0-0-1","align":"center","color":"palette9","fontWeight":"700","fontStyle":"italic","margin":["","","28",""],"markBorder":"","markBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"tabletMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"mobileMarkBorderStyles":[{"top":[null,"",""],"right":[null,"",""],"bottom":[null,"",""],"left":[null,"",""],"unit":"px"}],"htmlTag":"p","fontSize":[22,"",18]} -->
<p class="kt-adv-heading2bf1b3_0-0-0-1 wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading2bf1b3_0-0-0-1">{{lb.tagline}}</p>
<!-- /wp:kadence/advancedheading -->

<!-- wp:kadence/advancedbtn {"uniqueID":"2bf1b3_0-0-0-2"} -->
<div class="wp-block-kadence-advancedbtn kb-buttons-wrap kb-btns2bf1b3_0-0-0-2"><!-- wp:kadence/singlebtn {"uniqueID":"2bf1b3_0-0-0-2-0","text":"{{lb.cta_label}}","link":"#lb-cta_url","icon":"fe_arrowRight","inheritStyles":"inherit"} /--></div>
<!-- /wp:kadence/advancedbtn --></div>
<!-- /wp:group --></div></div>
<!-- /wp:kadence/column -->
<!-- /wp:kadence/rowlayout -->
