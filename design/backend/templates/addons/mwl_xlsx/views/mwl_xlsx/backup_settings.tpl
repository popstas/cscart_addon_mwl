{capture name="mainbox"}
    <form action="{$config.current_url|fn_url}" method="post" name="mwl_xlsx_backup_settings" class="form-horizontal form-edit" enctype="multipart/form-data">
        <input type="hidden" name="dispatch" value="mwl_xlsx.backup_settings" />
        <div class="control-group">
            <label class="control-label">{__("mwl_xlsx.settings_backup_last_run")}</label>
            <div class="controls">
                {if $last_backup}
                    {$last_backup}
                {else}
                    {__("mwl_xlsx.settings_backup_never")}
                {/if}
            </div>
        </div>

        <div class="control-group">
            <div class="controls">
                {include file="buttons/button.tpl" but_role="submit" but_meta="btn-primary" but_name="dispatch[mwl_xlsx.backup_settings]" but_text=__("mwl_xlsx.settings_backup_run")}
            </div>
        </div>

    </form>
{/capture}

{include file="common/mainbox.tpl" title=__("mwl_xlsx.settings_backup") content=$smarty.capture.mainbox}

