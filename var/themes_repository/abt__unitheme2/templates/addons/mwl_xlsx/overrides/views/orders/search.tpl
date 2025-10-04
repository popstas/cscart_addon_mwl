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
                {__("mwl_xlsx.order_items")}
            </th>
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
            {assign var="order_date_format" value="`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
            <td class="ty-orders-search__item">
                <a href="{"orders.details?order_id=`$o.order_id`"|fn_url}">
                    {$o.timestamp|date_format:$order_date_format}, <strong>#{$o.order_id}</strong>
                </a>
            </td>
            <td class="ty-orders-search__item">
                {include file="common/status.tpl" status=$o.status display="view"}
            </td>

            {hook name="orders:manage_data"}{/hook}

            {assign var="order_items" value=$mwl_xlsx_order_items[$o.order_id]|default:[]}
            <td class="ty-orders-search__item ty-orders-search__item--products">
                {if $order_items}
                    <ul class="mwl-xlsx-order-items">
                        {foreach from=$order_items item="order_item"}
                            <li class="mwl-xlsx-order-items__item">
                                {$order_item.amount}&times;&nbsp;{$order_item.product|escape}
                            </li>
                        {/foreach}
                    </ul>
                {else}
                    <span class="mwl-xlsx-order-items__empty">&mdash;</span>
                {/if}
            </td>
            <td class="ty-orders-search__item">
                {include file="common/price.tpl" value=$o.total}
            </td>
            {assign var="order_messages" value=$mwl_xlsx_order_messages[$o.order_id]|default:[]}
            {assign var="messages_total" value=$order_messages.total|default:0}
            {assign var="messages_class" value="mwl-xlsx-order-messages-count"}
            {if $order_messages.has_unread}
                {assign var="messages_class" value="`$messages_class` ty-text-warning"}
            {/if}
            {assign var="last_message" value=$order_messages.last_message|default:''}
            {assign var="last_message" value=$last_message|strip_tags|replace:"\r":' '|replace:"\n":' '|trim}
            <td class="ty-orders-search__item">
                {if $order_messages.thread_id}
                    <a class="{$messages_class} mwl-xlsx-order-messages-link" href="{"vendor_communication.view?thread_id=`$order_messages.thread_id`"|fn_url}" title="{$last_message|escape}">
                        <span class="mwl-xlsx-order-messages-link__total">{$messages_total}</span>
                    </a>
                {else}
                    <!--<span class="{$messages_class}">
                        {$messages_total}
                    </span>-->
                    {if $addons.vendor_communication.show_on_order === "YesNo::YES"|enum
                        && !$vendor_communication_order_thread
                    }
                        {include file="addons/vendor_communication/views/vendor_communication/components/new_thread_button.tpl"
                            title=__("vendor_communication.start_communication")
                            object_id=$o.order_id
                            meta="ty-btn ty-btn__text"
                            show_form=false
                        }
                    {/if}
                    
                    {include
                        file="addons/vendor_communication/views/vendor_communication/components/new_thread_form.tpl"
                        object_type=$smarty.const.VC_OBJECT_TYPE_ORDER
                        object_id=$o.order_id
                        company_id=$o.company_id
                        vendor_name=$o.company_name|default:''
                        redirect_url="`$config.current_url`&selected_section=vendor_communication"
                        no_ajax=true
                        product=false
                    }
                {/if}
            </td>
            <td class="ty-orders-search__item ty-orders-search__item--actions">
                <a class="ty-btn ty-btn__secondary" href="{"orders.details?order_id=`$o.order_id`"|fn_url}">
                    {__("view")}
                </a>
            </td>
        </tr>
    {foreachelse}
        <tr class="ty-table__no-items">
            <td colspan="6">
                <p class="ty-no-items">{__("text_no_orders")}</p>
            </td>
        </tr>
    {/foreach}
</table>

{include file="common/pagination.tpl"}

{capture name="mainbox_title"}{__("orders")}{/capture}
