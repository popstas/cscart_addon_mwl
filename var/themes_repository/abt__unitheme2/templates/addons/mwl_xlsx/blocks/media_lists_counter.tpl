{mwl_media_lists_count assign="count"}
{assign var="link_url" value="/media-lists"}
{assign var="href" value=$link_url|fn_url}

<div class="ut2-top-wishlist-count" id="mwl_media_lists_count">
    <a class="cm-tooltip ty-wishlist__a {if $count}active{/if}" href="{$href}" rel="nofollow" title="{__("mwl_xlsx.media_lists")}">
        <span>
            <i class="ut2-icon-article"></i>
            {if $count}<span class="count">{$count}</span>{/if}
        </span>
    </a>
</div>
