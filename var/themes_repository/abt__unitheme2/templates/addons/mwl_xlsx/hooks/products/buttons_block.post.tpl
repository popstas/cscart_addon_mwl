{if ($runtime.controller == 'products' || $runtime.controller == 'categories') && $runtime.mode == 'view'}
    {if $auth}
        {assign var=lists value=fn_mwl_xlsx_get_lists($auth.user_id)}
    {else}
        {assign var=lists value=fn_mwl_xlsx_get_lists(null)}
    {/if}

    <div class="mwl_xlsx-control">
        <select class="mwl_xlsx-select" data-ca-list-select-xlsx>
            {foreach $lists as $l}
                <option value="{$l.list_id}">{$l.name}</option>
            {/foreach}
            <option value="_new">+ {__("mwl_xlsx.new_list")}</option>
        </select>
        <button class="ty-btn" data-ca-add-to-mwl_xlsx data-ca-product-id="{$product.product_id}">
            {__("mwl_xlsx.add_to_wishlist")}
        </button>

        <span class="ty-price-hint cm-tooltip ty-icon-help-circle" title="{__("mwl_xlsx.tooltip")}">
        </span>
    </div>

{elseif !empty($is_mwl_xlsx_view)}
    <div class="mwl_xlsx-control">
        <br>
        <button class="ty-btn" data-ca-remove-from-mwl_xlsx data-ca-product-id="{$product.product_id}" data-ca-list-id="{$product.mwl_list_id}">
            {__("mwl_xlsx.remove_from_list")}
        </button>
    </div>
{/if}