{mwl_user_can_access_lists assign="can"}
{if $can}
{assign var="link_url" value="/profiles-update/"}
{assign var="href" value=$link_url|fn_url}
{assign var="status" value="..."}
{assign var="status_text" value="..."}

{mwl_xlsx_get_customer_status assign="status"}
{mwl_xlsx_get_customer_status_text assign="status_text"}

{if $status && $status != ''}
<div class="ut2-top-customer-status" id="mwl_customer_status">
    {__("mwl_xlsx.your_status")}:
    <a class="ty-customer-status mwl-status-label {$status}" href="{$href}" rel="nofollow">
        {$status_text}
    </a>
    <span class="ty-status-hint cm-tooltip ty-icon-help-circle" title="{__("mwl_xlsx.status_tooltip")}"></span>
</div>
{/if}
{/if}
