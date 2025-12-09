{* File: design/themes/abt__unitheme2/templates/addons/mwl_xlsx/overrides/common/product_data.tpl
   Purpose: Add description wrapper with fade effect and "Show all" button
*}
{capture name="prod_descr_`$obj_id`"}
    {if $show_descr}
        {if $product.short_description}
            <div class="mwl-description-wrapper">
                <div class="product-description mwl-description-content" {live_edit name="product:short_description:{$product.product_id}"}>{$product.short_description|strip_tags nofilter}</div>
            </div>
        {else}
            {* Get full description without truncation for comparison *}
            {$full_desc = $product.full_description|strip_tags}
            {$truncated_desc = $product.full_description|strip_tags|truncate:300}
            {$is_truncated = $full_desc|strlen > $truncated_desc|strlen}
            
            <div class="mwl-description-wrapper {if $is_truncated}mwl-description-collapsed{/if}">
                <div class="product-description mwl-description-content {if $is_truncated}mwl-description-collapsed{/if}" {live_edit name="product:full_description:{$product.product_id}" phrase=$product.full_description}>
                    {if $is_truncated}
                        <span class="mwl-description-short">{$truncated_desc nofilter}</span>
                        <span class="mwl-description-full">{$full_desc nofilter}</span>
                    {else}
                        {$full_desc nofilter}
                    {/if}
                </div>
                {if $is_truncated}
                    <div class="mwl-description-fade"></div>
                    <button type="button" class="mwl-description-toggle" data-ca-mwl-description-toggle>
                        {__("mwl_xlsx.show_all")|default:"Показать все"}
                    </button>
                {/if}
            </div>
        {/if}
    {/if}
{/capture}
{if $no_capture}
    {$capture_name = "prod_descr_`$obj_id`"}
    {$smarty.capture.$capture_name nofilter}
{/if}

