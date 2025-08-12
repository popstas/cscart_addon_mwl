<h1>{$list.name}</h1>
{if $products}
    {include file="blocks/list_templates/products.tpl" products=$products layout="products_without_options"}
{else}
    <p>{__("mwl_xlsx.empty_list")}</p>
{/if}
