{if !fn_mwl_xlsx_can_view_price($auth)}
    {capture name="price"}
        <span class="mwl-xlsx-login-to-view">{__("mwl_xlsx.login_to_view_price")}</span>
    {/capture}
{/if}
