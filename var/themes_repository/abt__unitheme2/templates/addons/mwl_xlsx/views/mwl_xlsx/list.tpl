{capture name="mainbox"}
    {if $products}
        {include file="blocks/list_templates/products.tpl" products=$products layout="products_without_options"}
    {else}
        {include file="common/no_items.tpl" text=__("mwl_xlsx.empty_list")}
    {/if}
{/capture}
{include file="common/mainbox.tpl" title=$list.name content=$smarty.capture.mainbox}
