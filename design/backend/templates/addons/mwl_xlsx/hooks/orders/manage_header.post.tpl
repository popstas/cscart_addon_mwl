{* Добавляем колонку с компанией покупателя, списком товаров и сообщениями *}
{if !$runtime.company_id}
    <th class="left">{__("company")}</th>
{/if}
<th class="nowrap">{__("mwl_xlsx.order_items")}</th>
<th class="center">{__("mwl_xlsx.order_messages")}</th>
<th class="center">{__("mwl_xlsx.planfix_task")}</th>
