{assign var="wallet" value=fn_mwl_wallet_get_balance($auth.user_id|default:0)}
{if $wallet}
<div class="ty-control-group">
    <label class="ty-control-group__label">{__("mwl_wallet.checkout_balance_label")}</label>
    <div class="ty-control-group__item">
        <span class="ty-price">{include file="common/price.tpl" value=$wallet.balance currency=$wallet.currency span_id="mwl_wallet_checkout_balance" class="ty-price"}</span>
    </div>
</div>
{/if}
<div class="ty-control-group">
    <label class="ty-control-group__label">{__("mwl_wallet.checkout_hint_title")}</label>
    <div class="ty-control-group__item">{__("mwl_wallet.checkout_hint_text")}</div>
</div>
