<h1>{__("mwl_xlsx.my_lists")}</h1>
<ul>
{foreach $lists as $l}
    <li><a href="{"mwl_xlsx.list?list_id=`$l.list_id`"|fn_url}">{$l.name}</a> ({$l.products_count} {__("products")})</li>
{/foreach}
</ul>
