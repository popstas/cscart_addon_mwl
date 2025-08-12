<h1>{$list.name}</h1>
{if $products}
    {include file="common/products.tpl" products=$products layout="products_without_options"}
{else}
    <p>{__("mwl_xlsx.empty_list")}</p>
{/if}
