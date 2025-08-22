{if fn_mwl_xlsx_user_can_access_lists($auth) && $addons.mwl_xlsx.show_extra_button == "Y"}
    <div class="mwl_xlsx-control">
        <button class="ty-btn" data-ca-add-to-mwl_xlsx data-ca-product-id="{$product.product_id}">
            {__("mwl_xlsx.add_to_wishlist")}
        </button>
    </div>
{/if}
