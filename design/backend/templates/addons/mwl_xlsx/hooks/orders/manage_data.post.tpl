{assign var="__order" value=$order|default:$o|default:[]}
{assign var="__order_id" value=$__order.order_id|default:0}

{if !$runtime.company_id}
    {assign var="__company" value=$__order.user.company|default:$__order.company|default:''}
    <td class="left">
        {if $__company|strlen}{$__company|escape}{else}&mdash;{/if}
    </td>
{/if}

{assign var="__order_items" value=$mwl_xlsx_order_items[$__order_id]|default:[]}
<td class="nowrap">
    {if $__order_items}
        <ul class="mwl-xlsx-order-items">
            {foreach from=$__order_items item="__item"}
                <li class="mwl-xlsx-order-items__item">
                    {$__item.amount}&times;&nbsp;{$__item.product|escape}
                </li>
            {/foreach}
        </ul>
    {else}
        <span class="muted">&mdash;</span>
    {/if}
</td>

{assign var="__order_messages" value=$mwl_xlsx_order_messages[$__order_id]|default:[]}
{assign var="__messages_total" value=$__order_messages.total|default:0}
{assign var="__messages_class" value="mwl-xlsx-order-messages-count"}
{if $__order_messages.has_unread}
    {assign var="__messages_class" value="`$__messages_class` text-warning"}
{/if}
<td class="center">
    {if $__order_messages.thread_id}
        <a class="{$__messages_class}" href="{"vendor_communication.view?thread_id=`$__order_messages.thread_id`"|fn_url}">
            {$__messages_total}
        </a>
    {else}
        <span class="{$__messages_class}">{$__messages_total}</span>
    {/if}
    {if $__order_messages.has_unread}
        <span class="mwl-xlsx-order-messages__unread text-warning">{__("mwl_xlsx.order_messages_unread")}</span>
    {/if}
</td>
