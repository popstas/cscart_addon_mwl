{capture name="section"}
    {include file="views/orders/components/orders_search_form.tpl"}
{/capture}
{include file="common/section.tpl" section_title=__("search_options") section_content=$smarty.capture.section class="ty-search-form" collapse=true}

{assign var="c_url" value=$config.current_url|fn_query_remove:"sort_by":"sort_order"}
{if $search.sort_order == "asc"}
    {include_ext file="common/icon.tpl" class="ty-icon-down-dir" assign=sort_sign}
{else}
    {include_ext file="common/icon.tpl" class="ty-icon-up-dir" assign=sort_sign}
{/if}
{if !$config.tweaks.disable_dhtml}
    {assign var="ajax_class" value="cm-ajax"}
{/if}

{include file="common/pagination.tpl"}

<table class="ty-table ty-orders-search">
    <thead>
        <tr>
            <th>
                <a class="{$ajax_class}" href="{"`$c_url`&sort_by=date&sort_order=`$search.sort_order_rev`"|fn_url}" data-ca-target-id="pagination_contents">
                    {__("date")}, {__("id")}
                </a>
                {if $search.sort_by === "date"}{$sort_sign nofilter}{/if}
            </th>
            <th>
                <a class="{$ajax_class}" href="{"`$c_url`&sort_by=status&sort_order=`$search.sort_order_rev`"|fn_url}" data-ca-target-id="pagination_contents">
                    {__("status")}
                </a>
                {if $search.sort_by === "status"}{$sort_sign nofilter}{/if}
            </th>

            {hook name="orders:manage_header"}{/hook}

            <th>
                <a class="{$ajax_class}" href="{"`$c_url`&sort_by=total&sort_order=`$search.sort_order_rev`"|fn_url}" data-ca-target-id="pagination_contents">
                    {__("total")}
                </a>
                {if $search.sort_by === "total"}{$sort_sign nofilter}{/if}
            </th>
            <th>
                {__("mwl_xlsx.order_messages")}
            </th>
            <th class="ty-orders-search__header ty-orders-search__header--actions">
                {__("actions")}
            </th>
        </tr>
    </thead>

    {foreach from=$orders item="o"}
        <tr>
            <td class="ty-orders-search__item">
                <a href="{"orders.details?order_id=`$o.order_id`"|fn_url}">
                    {$o.timestamp|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}, <strong>#{$o.order_id}</strong>
                </a>
            </td>
            <td class="ty-orders-search__item">
                {include file="common/status.tpl" status=$o.status display="view"}
            </td>

            {hook name="orders:manage_data"}{/hook}

            <td class="ty-orders-search__item">
                {include file="common/price.tpl" value=$o.total}
            </td>
            <td class="ty-orders-search__item">
                {$mwl_xlsx_order_messages_count[$o.order_id]|default:0}
            </td>
            <td class="ty-orders-search__item ty-orders-search__item--actions">
                <a class="ty-btn ty-btn__secondary" href="{"orders.details?order_id=`$o.order_id`"|fn_url}">
                    {__("view")}
                </a>
            </td>
        </tr>
    {foreachelse}
        <tr class="ty-table__no-items">
            <td colspan="5">
                <p class="ty-no-items">{__("text_no_orders")}</p>
            </td>
        </tr>
    {/foreach}
</table>

{include file="common/pagination.tpl"}

{capture name="mainbox_title"}{__("orders")}{/capture}
