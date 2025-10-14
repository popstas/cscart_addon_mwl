{capture name="mainbox"}
<div class="mwl-wallet-result">
    <h1 class="ty-mainbox-title">
        {if $wallet_is_success}
            {__("mwl_wallet.topup_success_title")}
        {elseif $wallet_is_cancel}
            {__("mwl_wallet.topup_cancel_title")}
        {else}
            {__("mwl_wallet.topup_pending_title")}
        {/if}
    </h1>

    <p class="ty-mb-l">
        {if $wallet_is_success}
            {__("mwl_wallet.topup_success_message")}
        {elseif $wallet_is_cancel}
            {__("mwl_wallet.topup_cancel_message")}
        {else}
            {__("mwl_wallet.topup_pending_message")}
        {/if}
    </p>

    {if $wallet_transaction}
    <div class="ty-mb-l">
        <table class="ty-table">
            <tbody>
                <tr>
                    <th>{__("mwl_wallet.transaction_id")}</th>
                    <td>{$wallet_transaction.txn_id}</td>
                </tr>
                <tr>
                    <th>{__("mwl_wallet.transaction_status")}</th>
                    <td>{__("mwl_wallet.status_"|cat:$wallet_transaction.status)}</td>
                </tr>
                <tr>
                    <th>{__("mwl_wallet.transaction_amount")}</th>
                    <td>{include file="common/price.tpl" value=$wallet_transaction.amount currency=$wallet_transaction.currency class="ty-price"}</td>
                </tr>
            </tbody>
        </table>
    </div>
    {/if}

    <a class="ty-btn ty-btn__primary" href="{"mwl_wallet.topup"|fn_url}">{__("mwl_wallet.back_to_wallet")}</a>
</div>
{/capture}
{include file="common/mainbox.tpl" title=__("mwl_wallet.my_wallet") content=$smarty.capture.mainbox}
