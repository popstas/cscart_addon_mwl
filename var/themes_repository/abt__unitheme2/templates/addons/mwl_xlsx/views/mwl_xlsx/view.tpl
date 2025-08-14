{capture name="mainbox"}
    <a class="mwl_xlsx-export" href="{fn_url("mwl_xlsx.export?list_id=`$list.list_id`")}" title="{__("mwl_xlsx.export")}"><img src="{$images_dir}/addons/mwl_xlsx/xlsx.svg" alt="{__("mwl_xlsx.export")}" width="20" height="20" /></a>
    {if $products}
        {include file="blocks/list_templates/products_list.tpl"
            is_mwl_xlsx_view=true
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
            show_add_to_cart=true
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
