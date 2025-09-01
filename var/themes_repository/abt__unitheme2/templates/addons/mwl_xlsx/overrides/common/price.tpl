{* File: design/themes/abt__unitheme2/templates/addons/mwl_xlsx/overrides/common/price.tpl
   Purpose: keep original Unitheme2 price rendering but show it only to users in group (isAgency) or admins.
*}
{strip}
    {* Access gate: allow admins or users in usergroup isAgency *}
    {assign var=can_see value=false}
    {if $auth.area == "A"}
        {assign var=can_see value=true}
    {elseif $auth.user_id && fn_mwl_xlsx_can_view_price($auth)}
        {assign var=can_see value=true}
    {/if}

    {if $can_see}
        {if $settings.General.alternative_currency == "use_selected_and_alternative"}
            {$temp = $value|format_price:$currencies.$primary_currency:$span_id:$class:false:$live_editor_name:$live_editor_phrase}
            {$temp|fn_abt__ut2_format_price:$currencies.$secondary_currency:$span_id:$class nofilter}
            {if $secondary_currency != $primary_currency}
                {if $class}<span class="{$class}">{/if}
                (
                {if $class}</span>{/if}
                {$value = $value|format_price:$currencies.$secondary_currency:$span_id:$class:true:$is_integer:$live_editor_name:$live_editor_phrase}
                <bdi>.{$value|fn_abt__ut2_format_price:$currencies.$secondary_currency:$span_id:$class nofilter}</bdi>
                {if $class}<span class="{$class}">{/if}
                )
                {if $class}</span>{/if}
            {/if}
        {else}
            {$value = $value|format_price:$currencies.$secondary_currency:$span_id:$class:true:$live_editor_name:$live_editor_phrase}
            <bdi>{$value|fn_abt__ut2_format_price:$currencies.$secondary_currency:$span_id:$class nofilter}</bdi>
        {/if}
    {else}
        <a class="ty-login-to-view{if $class} {$class}{/if}" href="{fn_url('auth.login_form')}">{__("sign_in_to_view_price")}</a>
    {/if}
{/strip}
