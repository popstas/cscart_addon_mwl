{capture name="mainbox"}
    {if $products}
        {include file="blocks/list_templates/products_list.tpl"
            products=$products
            layout="products_without_options"

            show_name=true
            show_descr=true
            show_features=true
            show_sku=false
            show_price=true
            show_old_price=true
            show_rating=true
            show_product_amount=true
            show_discount_label=true
            show_add_to_cart=false
            but_role="action"

            no_pagination=true
            item_number=0
        }
    {else}
        {include file="common/no_items.tpl" text=__("mwl_xlsx.empty_list")}
    {/if}
{/capture}

{include file="blocks/wrappers/mainbox_general.tpl"
    title=$list.name
    content=$smarty.capture.mainbox
}
