{if $auth}
    {assign var=lists value=fn_mwl_xlsx_get_lists($auth.user_id)}
{else}
    {assign var=lists value=fn_mwl_xlsx_get_lists(null)}
{/if}

<div class="mwl_xlsx-control ty-dropdown-box">
    <div class="ty-dropdown-box__title cm-combination" id="sw_mwl_xlsx_{$product.product_id}">
        {__("add_to_wishlist")}
    </div>
    <div id="mwl_xlsx_{$product.product_id}" class="cm-popup-box ty-dropdown-box__content hidden">
        <ul class="mwl_xlsx-lists">
            {foreach $lists as $l}
                <li><a href="#" data-ca-add-to-mwl_xlsx data-ca-product-id="{$product.product_id}" data-ca-list-id="{$l.list_id}">{$l.name}</a></li>
            {/foreach}
            <li class="mwl_xlsx-new">
                <input type="text" placeholder="{__("mwl_xlsx.enter_list_name")}" data-ca-new-list-name />
                <button class="ty-btn ty-btn--primary" data-ca-save-new-list data-ca-product-id="{$product.product_id}">+</button>
            </li>
        </ul>
    </div>
</div>
