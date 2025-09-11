{if fn_mwl_xlsx_user_can_access_lists($auth)}
{capture name="mainbox"}
    <div class="mwl_xlsx-view-page">
        {if $products}
            <a class="mwl_xlsx-export" target="_blank" href="{fn_url("mwl_xlsx.export?list_id=`$list.list_id`")}" title="{__("mwl_xlsx.export")}"><img src="{$images_dir}/addons/mwl_xlsx/xlsx.svg" alt="{__("mwl_xlsx.export")}" width="20" height="20" /> {__("mwl_xlsx.export")}</a>
            <a class="mwl_xlsx-export" target="_blank" href="{fn_url("mwl_xlsx.export_google?list_id=`$list.list_id`")}" title="{__("mwl_xlsx.export_google")}"><img src="{$images_dir}/addons/mwl_xlsx/gsheet.svg" alt="{__("mwl_xlsx.export_google")}" width="20" height="20" /> {__("mwl_xlsx.export_google")}</a>
            <br /><br />
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
    </div>
{/capture}

{include file="blocks/wrappers/mainbox_general.tpl"
    title=$list.name
    content=$smarty.capture.mainbox
}
{else}
    {include file="common/no_items.tpl"}
{/if}
