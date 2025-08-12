<h1>{$list.name}</h1>
{if $products}
    {include file="blocks/list_templates/products.tpl" products=$products}
{else}
    <p>{__("mwl_xlsx.empty_list")}</p>
{/if}
