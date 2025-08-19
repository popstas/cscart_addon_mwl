{if !$quick_view && $product.product_id && !($runtime.controller == 'products' && $runtime.mode == 'view')}
    {assign var=product_url value=fn_url("products.view?product_id=`$product.product_id`")}
    <a href="{$product_url}"
       class="mwl-more-btn">
        {__("extra")}
    </a>
{/if}
