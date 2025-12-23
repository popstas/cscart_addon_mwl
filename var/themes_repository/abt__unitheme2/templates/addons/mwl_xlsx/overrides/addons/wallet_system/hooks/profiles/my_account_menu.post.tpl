{if $auth.user_id}
                <li class="ty-wallet-info__item ty-dropdown-box__item"><a class="ty-wallet-info__a underlined" href="{"wallet_system.my_wallet"|fn_url}" rel="nofollow">{__("my_wallet")} ({include file="common/price.tpl" value=$helper->fnGetWalletAmount(null,$smarty.session.auth.user_id)})
                </a></li>

        {$allow_wallet_to_bank = $helper->fnCheckTransferWalletToBank()}

        {if $allow_wallet_to_bank == 'Y'}
                <li class="ty-wallet-info__item ty-dropdown-box__item">
                        <a class="ty-wallet-info__a underlined" href="{"wallet_system.wallet_to_bank"|fn_url}" rel="nofollow">{__("transfer_wallet_cash_to_bank")} </a>
                </li>
        {/if}

{/if}

