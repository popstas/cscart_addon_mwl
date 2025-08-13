{capture name="mainbox"}
    {if $lists}
        <ul data-ca-mwl-lists>
        {foreach $lists as $l}
            <li data-ca-mwl-list-id="{$l.list_id}">
                <a data-ca-mwl-list-name href="{fn_url("mwl_xlsx.list?list_id=`$l.list_id`")}">{$l.name}</a>
                ({$l.products_count})
                <a href="{fn_url("mwl_xlsx.export?list_id=`$l.list_id`")}">{__("mwl_xlsx.export")}</a>
                <a href="#" data-ca-mwl-rename>{__("mwl_xlsx.rename")}</a>
                <a href="#" data-ca-mwl-delete>{__("mwl_xlsx.remove")}</a>
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
