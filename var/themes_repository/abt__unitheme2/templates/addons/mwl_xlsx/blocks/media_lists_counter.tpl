{* Шаблон счётчика media-lists для верхней панели UT2 *}
{assign var="count" value=$media_lists_count}
{assign var="show_zero" value=$block.properties.show_zero|default:"N"}
{assign var="link_url" value=$block.properties.link_url|default:"media-lists"}

{* Поддержка как ЧПУ (/media-lists), так и dispatch (mwl_xlsx.manage / mwl_xlsx.view) *}
{if $link_url|substr:0:1 == '/'}
    {assign var="href" value=$link_url}
{else}
    {assign var="href" value=$link_url|fn_url}
{/if}

{if $count || $show_zero == "Y"}
<div class="ut2-top-wishlist-count" id="mwl_media_lists_count">
    <a class="cm-tooltip ty-wishlist__a {if $count}active{/if}" href="{$href}" rel="nofollow" title="{__("mwl_xlsx.media_lists")}">
        <span>
            <i class="ut2-icon-article"></i>
            <span class="count">{$count}</span>
        </span>
    </a>
</div>
{/if}
