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
    <input type="text" class="mwl_xlsx-new-name ty-input-text" data-ca-mwl-new-list-name style="display:none" placeholder="{__("mwl_xlsx.enter_list_name")}">
    <button class="ty-btn" data-ca-add-to-mwl_xlsx data-ca-product-id="{$product.product_id}">
        {__("add_to_wishlist")}
    </button>
</div>
