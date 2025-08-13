{if $products}
    {if $auth}
        {assign var=lists value=fn_mwl_xlsx_get_lists($auth.user_id)}
    {else}
        {assign var=lists value=fn_mwl_xlsx_get_lists(null)}
    {/if}
    {assign var=mwl_product_ids value=[]}
    {foreach $products as $p}
        {append var=mwl_product_ids value=$p.product_id}
    {/foreach}
    <div class="mwl_xlsx-control mwl_xlsx-control--category">
        <select class="mwl_xlsx-select" data-ca-list-select-xlsx>
            {foreach $lists as $l}
                <option value="{$l.list_id}">{$l.name}</option>
            {/foreach}
            <option value="_new">+ {__("mwl_xlsx.new_list")}</option>
        </select>
        <input type="text" class="mwl_xlsx-new-name ty-input-text" data-ca-mwl-new-list-name style="display:none" placeholder="{__("mwl_xlsx.enter_list_name")}">
        <button class="ty-btn" data-ca-add-all-to-mwl_xlsx data-ca-product-ids="{","|implode:$mwl_product_ids}">
            {__("mwl_xlsx.add_all_to_wishlist")}
        </button>
    </div>
{/if}

