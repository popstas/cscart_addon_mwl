{capture name="mainbox"}
    {if $lists}
        <ul>
        {foreach $lists as $l}
            <li>
                <a href="{fn_url("mwl_xlsx.list?list_id=`$l.list_id`")}">{$l.name}</a>
                ({$l.products_count})
                <a href="{fn_url("mwl_xlsx.export?list_id=`$l.list_id`")}">{__("mwl_xlsx.export")}</a>
            </li>
        {/foreach}
        </ul>
    {else}
        {include file="common/no_items.tpl"}
    {/if}
{/capture}

{include file="blocks/wrappers/mainbox_simple.tpl"
    title=__("mwl_xlsx.my_lists")
    content=$smarty.capture.mainbox
}
