{if !$runtime.company_id}
    {assign var="__company" value=$user.company|default:$u.company|default:''}
    <td class="left">
        {if $__company|strlen}{$__company|escape}{else}—{/if}
    </td>
{/if}
