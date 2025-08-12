<h1>{__("mwl_xlsx.my_lists")}</h1>
<ul>
{foreach $lists as $l}
    <li><a href="{fn_url("mwl_xlsx.list?list_id=`$l.list_id`")}">{$l.name}</a> ({$l.products_count})</li>
{/foreach}
</ul>
