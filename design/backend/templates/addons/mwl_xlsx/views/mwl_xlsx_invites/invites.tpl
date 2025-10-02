{* $Id: mwl_xlsx_invites.tpl,v 1.0 2024/01/01 00:00:00 $ *}

{capture name="mainbox"}

{capture name="tabsbox"}
<div id="content_invites">

{if $invites}
    <table class="table table-middle table--relative table-responsive">
        <thead>
            <tr>
                <th width="5%">{__("id")}</th>
                <th width="25%">{__("email")}</th>
                <th width="20%">{__("name")}</th>
                <th width="20%">{__("company")}</th>
                <th width="15%">{__("mwl_xlsx.last_login")}</th>
                <th width="15%">{__("mwl_xlsx.remaining_hours")}</th>
            </tr>
        </thead>
        <tbody>
        {foreach from=$invites item=invite}
            <tr>
                <td class="center">{$invite.user_id}</td>
                <td>
                    <a href="{"profiles.update?user_id=`$invite.user_id`"|fn_url}">{$invite.email}</a>
                </td>
                <td>
                    {if $invite.firstname || $invite.lastname}
                        {$invite.firstname} {$invite.lastname}
                    {else}
                        <span class="muted">{__("mwl_xlsx.no_name")}</span>
                    {/if}
                </td>
                <td>
                    {if $invite.company}
                        {$invite.company}
                    {else}
                        <span class="muted">{__("mwl_xlsx.no_company")}</span>
                    {/if}
                </td>
                <td class="center">
                    {if $invite.last_login}
                        {$invite.last_login|date_format:"%d.%m.%Y %H:%M"}
                    {else}
                        <span class="muted">{__("mwl_xlsx.never_logged_in")}</span>
                    {/if}
                </td>
                <td class="center">
                    {if $invite.remaining_hours > 24}
                        <span class="text-success">{$invite.remaining_days} {__("days")}</span>
                    {elseif $invite.remaining_hours > 1}
                        <span class="text-warning">{$invite.remaining_hours} {__("hours")}</span>
                    {else}
                        <span class="text-error">{$invite.remaining_hours} {__("hours")}</span>
                    {/if}
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
{else}
    <p class="no-items">{__("mwl_xlsx.no_invites")}</p>
{/if}

</div>
{/capture}

{include file="common/tabsbox.tpl" content=$smarty.capture.tabsbox active_tab="invites"}

{/capture}

{include file="common/mainbox.tpl" title=__("mwl_xlsx.invites") content=$smarty.capture.mainbox}
