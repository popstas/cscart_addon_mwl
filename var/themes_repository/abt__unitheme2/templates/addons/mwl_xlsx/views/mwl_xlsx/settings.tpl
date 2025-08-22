{if fn_mwl_xlsx_user_can_access_lists($auth)}
{capture name="mainbox"}
    <form action="{fn_url('mwl_xlsx.save_settings')}" method="post" class="cm-ajax">
        <div class="ty-control-group">
            <label class="ty-control-group__title" for="mwl_price_multiplier">{__("mwl_xlsx.price_multiplier")}</label>
            <input type="number" step="0.01" min="0" name="price_multiplier" id="mwl_price_multiplier" value="{$user_settings.price_multiplier|default:1}" class="ty-input-text" />
        </div>
        <div class="ty-control-group">
            <label class="ty-control-group__title" for="mwl_round_to">{__("mwl_xlsx.round_to")}</label>
            <input type="number" step="10" min="0" name="round_to" id="mwl_round_to" value="{$user_settings.round_to|default:10}" class="ty-input-text" />
        </div>
        <div class="ty-control-group">
            <label class="ty-control-group__title" for="mwl_price_append">{__("mwl_xlsx.price_append")}</label>
            <input type="text" name="price_append" id="mwl_price_append" value="{$user_settings.price_append|default:0}" class="ty-input-text" />
        </div>
        <div class="buttons-container">
            <button type="submit" class="ty-btn ty-btn__primary">{__("save")}</button>
        </div>
    </form>
{/capture}

{include file="blocks/wrappers/mainbox_general.tpl"
    title=__("mwl_xlsx.settings")
    content=$smarty.capture.mainbox
}
{else}
    {include file="common/no_items.tpl"}
{/if}
