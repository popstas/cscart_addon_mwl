{capture name="mainbox"}
    {if $products}
        {include file="blocks/list_templates/products_list.tpl"
            products=$products
            layout="products_without_options"
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
