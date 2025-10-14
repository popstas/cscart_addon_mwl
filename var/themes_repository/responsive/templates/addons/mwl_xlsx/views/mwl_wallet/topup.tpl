{capture name="mainbox"}
<div class="mwl-wallet">
    <div class="mwl-wallet__balance ty-mb-l">
        <h2 class="ty-subheader">{__("mwl_wallet.current_balance_title")}</h2>
        <div class="mwl-wallet__balance-amount ty-h2">
            {include file="common/price.tpl" value=$wallet_balance.balance currency=$wallet_balance.currency span_id="mwl_wallet_balance" class="ty-price"}
        </div>
        <p class="ty-muted">{__("mwl_wallet.balance_currency", ["[currency]" => $wallet_balance.currency])}</p>
    </div>

    <div class="mwl-wallet__topup ty-mb-l">
        <h2 class="ty-subheader">{__("mwl_wallet.topup_title")}</h2>
        <form action="{"mwl_wallet.create_checkout"|fn_url}" method="post">
            <div class="ty-control-group">
                <label class="ty-control-group__label" for="mwl_wallet_amount">{__("mwl_wallet.amount_label")}</label>
                <input id="mwl_wallet_amount" type="number" name="amount" step="0.01" value="" min="{$wallet_limits.min}"{if $wallet_limits.max > 0} max="{$wallet_limits.max}"{/if} required class="ty-input-text-full" placeholder="{$wallet_limits.min}" />
                <p class="ty-help-block">
                    {__("mwl_wallet.amount_hint_min", ["[min]" => $wallet_limits.min, "[currency]" => $wallet_selected_currency])}
                    {if $wallet_limits.max > 0}
                        <br />{__("mwl_wallet.amount_hint_max", ["[max]" => $wallet_limits.max, "[currency]" => $wallet_selected_currency])}
                    {/if}
                </p>
            </div>

            {if $wallet_allowed_currencies|count > 1}
            <div class="ty-control-group">
                <label class="ty-control-group__label" for="mwl_wallet_currency">{__("mwl_wallet.currency_label")}</label>
                <select id="mwl_wallet_currency" name="currency" class="ty-select-block">
                    {foreach from=$wallet_allowed_currencies item="currency_code"}
                        <option value="{$currency_code}"{if $currency_code == $wallet_selected_currency} selected="selected"{/if}>{$currencies.$currency_code.description|default:$currency_code}</option>
                    {/foreach}
                </select>
            </div>
            {else}
                <input type="hidden" name="currency" value="{$wallet_selected_currency}" />
            {/if}

            {if $wallet_fee_settings.percent > 0 || $wallet_fee_settings.fixed > 0}
            <div class="ty-alert ty-alert-info ty-mb-s">
                {__("mwl_wallet.fee_notice", [
                    "[percent]" => $wallet_fee_settings.percent|number_format:2,
                    "[fixed]" => $wallet_fee_settings.fixed|number_format:2,
                    "[currency]" => $wallet_selected_currency
                ])}
            </div>
            {/if}

            <button class="ty-btn ty-btn__primary" type="submit">{__("mwl_wallet.topup_button")}</button>
        </form>
    </div>

    <div class="mwl-wallet__history">
        <h2 class="ty-subheader">{__("mwl_wallet.transaction_history_title")}</h2>
        {if $wallet_transactions}
        <table class="ty-table">
            <thead>
                <tr>
                    <th>{__("mwl_wallet.transaction_date")}</th>
                    <th>{__("mwl_wallet.transaction_type")}</th>
                    <th>{__("mwl_wallet.transaction_status")}</th>
                    <th class="ty-right">{__("mwl_wallet.transaction_amount")}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$wallet_transactions item="txn"}
                <tr>
                    <td>{$txn.created_at|date_format:"%d.%m.%Y %H:%M"}</td>
                    <td>{__("mwl_wallet.type_"|cat:$txn.type)}</td>
                    <td>{__("mwl_wallet.status_"|cat:$txn.status)}</td>
                    <td class="ty-right">
                        {include file="common/price.tpl" value=$txn.amount currency=$txn.currency class="ty-price" span_id="mwl_wallet_txn_{$txn.txn_id}"}
                    </td>
                </tr>
                {/foreach}
            </tbody>
        </table>
        {else}
        <p class="ty-muted">{__("mwl_wallet.no_transactions")}</p>
        {/if}
    </div>
</div>
{/capture}
{include file="common/mainbox.tpl" title=__("mwl_wallet.my_wallet") content=$smarty.capture.mainbox}
