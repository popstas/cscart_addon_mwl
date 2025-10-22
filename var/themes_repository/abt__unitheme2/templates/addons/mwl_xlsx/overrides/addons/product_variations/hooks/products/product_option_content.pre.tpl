{if $product.variation_features_variants && $product.detailed_params.info_type === "D"}
    {script src="js/addons/product_variations/picker_features.js"}
    <div id="features_{$obj_prefix}{$obj_id}_AOC">
        {$container = "ut2_pb__sticky_add_to_cart,product_detail_page"}
        {$product_url = "products.view"}
        {$show_all_possible_feature_variants = $addons.product_variations.variations_show_all_possible_feature_variants === "YesNo::YES"|enum}
        {$allow_negative_amount = $allow_negative_amount|default:$settings.General.allow_negative_amount === "YesNo::YES"|enum}

        {if $quick_view}
            {$container = "product_main_info_form_{$obj_prefix}{$quick_view_additional_container}"}
            {$product_url = "products.quick_view?product_id=`$product.product_id`&prev_url=`$current_url`"|trim}
        {/if}

        {if $is_microstore}
            {$product_url = $product_url|fn_link_attach:"is_microstore=Y"}
            {if $product.company_id}
                {$product_url = $product_url|fn_link_attach:"microstore_company_id=`$product.company_id`"}
            {/if}
        {/if}

        {if $ut2_select_variation}
            {$container = "ut2_select_variation_wrapper_{$obj_prefix}"}
            {$product_url = "products.ut2_select_variation?product_id=`$product.product_id`&prev_url=`$current_url`"|trim}
        {/if}

        {if $product.detailed_params.is_preview}
            {$product_url = $product_url|fn_link_attach:"action=preview"}
        {/if}

        <div class="cm-picker-product-variation-features ty-product-options" style="--tb-var-image-width: {$settings.Thumbnails.product_variant_mini_icon_width};--tb-var-image-height: {$settings.Thumbnails.product_variant_mini_icon_height}">
            {$feature_style_dropdown = "\Tygh\Enum\ProductFeatureStyles::DROP_DOWN"|constant}
            {$feature_style_images = "\Tygh\Enum\ProductFeatureStyles::DROP_DOWN_IMAGES"|constant}
            {$feature_style_labels = "\Tygh\Enum\ProductFeatureStyles::DROP_DOWN_LABELS"|constant}
            {$purpose_create_variations = "\Tygh\Addons\ProductVariations\Product\FeaturePurposes::CREATE_VARIATION_OF_CATALOG_ITEM"|constant}

            {foreach $product.variation_features_variants as $feature}

                {* --- НОВОЕ: считаем «реально доступные» варианты и пропускаем одиночные --- *}
                {assign var="_available_variants" value=[]}
                {foreach $feature.variants as $_v}
                    {if $_v.product.status || $show_all_possible_feature_variants}
                        {$_available_variants[] = $_v}
                    {/if}
                {/foreach}
                {if $_available_variants|count <= 1}
                    {continue}
                {/if}
                {* --- /НОВОЕ --- *}

                {$is_feature_default_style = !in_array($feature.feature_style, [$feature_style_images, $feature_style_labels, $feature_style_dropdown])}
                <div class="ty-control-group ty-product-options__item clearfix">
                    <div class="ut2{if $feature.feature_style === $feature_style_images}-vimg{else}-vopt{/if}__wrap">
                        <label class="ty-control-group__label ty-product-options__item-label">{$feature.description}:</label>

                        {if $feature.feature_style === $feature_style_images}
                            {foreach $feature.variants as $variant}
                                {if $feature.variant_id != $variant.variant_id}{continue}{/if}
                                {if $variant.product.status || $show_all_possible_feature_variants}
                                    <div class="ty-product-option-container ty-product-option-container--feature-style-images">
                                        <div class="ty-product-option-child">{if $feature.prefix}{$feature.prefix} {/if}{$variant.variant}{if $feature.suffix} {$feature.suffix}{/if}</div>
                                    </div>
                                {/if}
                            {/foreach}

                        {elseif $feature.feature_style === $feature_style_dropdown || $is_feature_default_style}
                            <div class="ty-product-option-container">
                                <div class="ty-product-option-child">
                                    <select class="{if $feature.purpose === $purpose_create_variations || $quick_view || $ut2_select_variation}cm-ajax{/if} {if !$quick_view}cm-history{/if} cm-ajax-force"
                                            data-ca-target-id="{$container}">
                                        {foreach $feature.variants as $variant}
                                            {if $variant.product.status}
                                                <option
                                                    data-ca-variant-id="{$variant.variant_id}"
                                                    data-ca-product-url="{$product_url|fn_link_attach:"product_id={$variant.product.product_id}"|fn_url}"
                                                    {if $feature.variant_id == $variant.variant_id}selected="selected"{/if}
                                                >
                                                    {if $feature.prefix}{$feature.prefix} {/if}{$variant.variant}{if $feature.suffix} {$feature.suffix}{/if}
                                                </option>
                                            {elseif $show_all_possible_feature_variants}
                                                <option disabled>{$variant.variant}</option>
                                            {/if}
                                        {/foreach}
                                    </select>
                                </div>
                            </div>
                        {/if}
                    </div>

                    {if $feature.feature_style === $feature_style_images}
                        {* ... остальной блок изображений БЕЗ ИЗМЕНЕНИЙ ... *}
                        {capture name="variant_images"}
                            {foreach $feature.variants as $variant}
                                {if $variant.showed_product_id}
                                    {$variant_product_id = $variant.showed_product_id}
                                {else}
                                    {$variant_product_id = $variant.product.product_id}
                                {/if}
                                {if $variant_product_id && $variant.product.status}
                                    {if $settings.abt__ut2.general.change_main_image_on_variation_hover.{$settings.ab__device} == "YesNo::YES"|enum}
                                        {if $quick_view}
                                            {$image_width=$settings.Thumbnails.product_quick_view_thumbnail_width}
                                            {$image_height=$settings.Thumbnails.product_quick_view_thumbnail_height}
                                        {elseif $product.details_layout !='bigpicture_template'}
                                            {$image_width=$settings.Thumbnails.product_details_thumbnail_width}
                                            {$image_height=$settings.Thumbnails.product_details_thumbnail_height}
                                        {/if}
                                        {include file="common/image.tpl" images=$variant.product.main_pair capture_image=true}
                                    {/if}
                                    <a {if $variant.product.amount >= 1 || $allow_negative_amount || $details_page}href="{$product_url|fn_link_attach:"product_id={$variant_product_id}"|fn_url}"{/if}
                                       class="ty-product-options__image--wrapper {if $variant.product.abt__ut2_is_in_stock < 1 && $settings.abt__ut2.products.highlight_unavailable_variations[$settings.ab__device] == "YesNo::YES"|enum}ty-product-options__image--wrapper--disabled{/if} {if $settings.ab__device=='desktop'}cm-tooltip{/if} {if $variant.variant_id == $feature.variant_id}ty-product-options__image--wrapper--active{/if} {if $feature.purpose === $purpose_create_variations || $quick_view || $ut2_select_variation}cm-ajax {if !$ut2_select_variation}cm-history {/if}cm-ajax-cache{/if}"
                                       title="{$feature.prefix} {$variant.variant} {$feature.suffix}"
                                       {if $feature.purpose === $purpose_create_variations || $quick_view || $ut2_select_variation}data-ca-target-id="{$container}"{/if}>
                                        {include file="common/image.tpl"
                                            obj_id="image_feature_variant_{$feature.feature_id}_{$variant.variant_id}_{$obj_prefix}{$obj_id}"
                                            class="ty-product-options__image"
                                            images=$variant.product.main_pair
                                            image_width=$settings.Thumbnails.product_variant_mini_icon_width
                                            image_height=$settings.Thumbnails.product_variant_mini_icon_height
                                            image_additional_attrs=["width"=>$settings.Thumbnails.product_variant_mini_icon_width,"height"=>$settings.Thumbnails.product_variant_mini_icon_height]
                                        }
                                    </a>
                                {elseif $show_all_possible_feature_variants}
                                    <label class="ty-product-options__radio--label ty-product-options__radio--label--disabled">
                                        <span class="ty-product-option-checkbox">{$feature.prefix}</span>
                                        <bdi>{$variant.variant}</bdi>
                                        <span class="ty-product-option-checkbox">{$feature.suffix}</span>
                                    </label>
                                {/if}
                            {/foreach}
                        {/capture}

                        {if $smarty.capture.variant_images|trim}
                            <div class="ty-clear-both">
                                {$smarty.capture.variant_images nofilter}
                            </div>
                        {/if}

                    {elseif $feature.feature_style === $feature_style_labels}
                        <div class="ty-clear-both">
                            {foreach $feature.variants as $variant}
                                {if $variant.product.product_id && $variant.product.status}
                                    <input type="radio"
                                           name="feature_{$feature.feature_id}"
                                           value="{$variant.variant_id}"
                                           {if $feature.variant_id == $variant.variant_id}checked{/if}
                                           id="feature_{$feature.feature_id}_variant_{$variant.variant_id}_{$obj_prefix}{$obj_id}"
                                           data-ca-variant-id="{$variant.variant_id}"
                                           data-ca-product-url="{$product_url|fn_link_attach:"product_id={$variant.product.product_id}"|fn_url}"
                                           class="hidden ty-product-options__radio {if $feature.purpose === $purpose_create_variations || $quick_view || $ut2_select_variation}cm-ajax{/if} {if $details_page}cm-history{/if} cm-ajax-force"
                                           data-ca-target-id="{$container}" />
                                    <label for="feature_{$feature.feature_id}_variant_{$variant.variant_id}_{$obj_prefix}{$obj_id}"
                                           class="ty-product-options__radio--label {if !$variant.product.abt__ut2_is_in_stock && $settings.abt__ut2.products.highlight_unavailable_variations[$settings.ab__device] == "YesNo::YES"|enum}ty-product-options__radio--label--disabled{/if}">
                                        <span class="ty-product-option-checkbox">{$feature.prefix}</span>
                                        <bdi>{$variant.variant}</bdi>
                                        <span class="ty-product-option-checkbox">{$feature.suffix}</span>
                                    </label>
                                {elseif $show_all_possible_feature_variants}
                                    <label class="ty-product-options__radio--label ty-product-options__radio--label--disabled">
                                        <span class="ty-product-option-checkbox">{$feature.prefix}</span>
                                        <bdi>{$variant.variant}</bdi>
                                        <span class="ty-product-option-checkbox">{$feature.suffix}</span>
                                    </label>
                                {/if}
                            {/foreach}
                        </div>
                    {/if}
                </div>
            {/foreach}
        </div>
    </div>
{/if}
