{if fn_mwl_xlsx_user_can_access_lists($auth)}
{capture name="mainbox"}
    {if $lists}
        <table class="ty-table" data-ca-mwl-lists>
            <thead>
                <tr>
                    <th class="ty-left">{__("mwl_xlsx.list_name")}</th>
                    <th class="">{__("mwl_xlsx.items")}</th>
                    <th class="">{__("mwl_xlsx.actions")}</th>
                </tr>
            </thead>
            <tbody>
            {foreach $lists as $l}
                <tr data-ca-mwl-list-id="{$l.list_id}">
                    <td class="ty-strong"><a data-ca-mwl-list-name href="{$l.list_id|fn_mwl_xlsx_url|fn_url}">{$l.name}</a></td>
                    <td class="">{$l.products_count}</td>
                    <td class="">
                        <a class="mwl_xlsx-export" href="{fn_url("mwl_xlsx.export?list_id=`$l.list_id`")}" title="{__("mwl_xlsx.export")}"><img src="{$images_dir}/addons/mwl_xlsx/xlsx.svg" alt="{__("mwl_xlsx.export")}" width="20" height="20" /></a>
                        <a class="mwl_xlsx-export" href="{fn_url("mwl_xlsx.export_google?list_id=`$l.list_id`")}" title="{__("mwl_xlsx.export_google")}"><img src="{$images_dir}/addons/mwl_xlsx/gsheet.svg" alt="{__("mwl_xlsx.export_google")}" width="20" height="20" /></a>
                        <a href="#" data-ca-mwl-rename title="{__("mwl_xlsx.rename")}"><i class="ut2-icon-more_vert"></i></a>
                        <a href="#" data-ca-mwl-delete title="{__("mwl_xlsx.remove")}"><i class="ut2-icon-baseline-delete"></i></a>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {else}
        {include file="common/no_items.tpl"}
    {/if}
    <div class="ty-mbm">
        <a class="mwl-settings" href="{fn_url('mwl_xlsx.settings')}">{__("mwl_xlsx.settings")}</a>
    </div>
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
{else}
    {include file="common/no_items.tpl"}
{/if}
