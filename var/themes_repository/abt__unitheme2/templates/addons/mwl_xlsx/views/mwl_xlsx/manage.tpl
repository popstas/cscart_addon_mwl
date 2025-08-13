{capture name="mainbox"}
    {if $lists}
        <ul data-ca-mwl-lists>
        {foreach $lists as $l}
            <li data-ca-mwl-list-id="{$l.list_id}">
                <a data-ca-mwl-list-name href="{$l.list_id|fn_mwl_xlsx_url|fn_url}">{$l.name}</a>
                ({$l.products_count})
                <a class="mwl_xlsx-export" href="{fn_url("mwl_xlsx.export?list_id=`$l.list_id`")}" title="{__("mwl_xlsx.export")}"><img src="{$images_dir}/addons/mwl_xlsx/xlsx.svg" alt="{__("mwl_xlsx.export")}" width="20" height="20" /></a>
                <a href="#" data-ca-mwl-rename title="{__("mwl_xlsx.rename")}"><i class="ut2-icon-more_vert"></i></a>
                <a href="#" data-ca-mwl-delete title="{__("mwl_xlsx.remove")}"><i class="ut2-icon-baseline-delete"></i></a>
            </li>
        {/foreach}
        </ul>
    {else}
        {include file="common/no_items.tpl"}
    {/if}
{/capture}

<div id="mwl_xlsx_rename_dialog" class="hidden">
    <div class="ty-control-group">
        <label for="mwl_xlsx_rename_input" class="ty-control-group__title">{__("mwl_xlsx.enter_list_name")}</label>
        <input type="text" id="mwl_xlsx_rename_input" maxlength="50" class="ty-input-text" />
    </div>
    <div class="buttons-container">
        <button class="ty-btn ty-btn__primary" data-ca-mwl-rename-save>{__("save")}</button>
        <button class="ty-btn" data-ca-mwl-rename-cancel>{__("cancel")}</button>
    </div>
</div>

<div id="mwl_xlsx_delete_dialog" class="hidden">
    <div class="ty-dialog-body">{__("mwl_xlsx.confirm_remove")}</div>
    <div class="buttons-container">
        <button class="ty-btn ty-btn__primary" data-ca-mwl-delete-confirm>{__("mwl_xlsx.remove")}</button>
        <button class="ty-btn" data-ca-mwl-delete-cancel>{__("cancel")}</button>
    </div>
</div>

{include file="blocks/wrappers/mainbox_simple.tpl"
    title=__("mwl_xlsx.my_lists")
    content=$smarty.capture.mainbox
}
