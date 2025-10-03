{assign var="__profile" value=$user|default:$u|default:[]}
{assign var="__user_id" value=$__profile.user_id|default:0}
{assign var="__planfix_link" value=$mwl_planfix_user_links[$__user_id]|default:[]}

{if !$runtime.company_id}
    {assign var="__company" value=$__profile.company|default:''}
    <td class="left">
        {if $__company|strlen}{$__company|escape}{else}—{/if}
    </td>
{/if}

<td class="center">
    {if $__planfix_link.planfix_object_id|default:''}
        {if $__planfix_link.planfix_url|default:''}
            <a href="{$__planfix_link.planfix_url|escape}" target="_blank" rel="noopener noreferrer">
                {$__planfix_link.planfix_object_id|escape}
            </a>
        {else}
            <span>{$__planfix_link.planfix_object_id|escape}</span>
        {/if}
    {else}
        <span class="muted">&mdash;</span>
    {/if}
</td>
