{capture name="mainbox"}

<form action="{""|fn_url}" method="post" class="form-horizontal form-edit" name="mwl_xlsx_status_map_form">
    <input type="hidden" name="map_id" value="{$smarty.request.map_id|default:0}" />
    <input type="hidden" name="entity_type" value="order" />

    <div class="control-group">
        <label for="entity_status" class="control-label cm-required">{__("mwl_xlsx.entity_status")}:</label>
        <div class="controls">
            <select name="entity_status" id="entity_status" required>
                <option value="">{__("select")}</option>
                {foreach from=$entity_statuses key=status_code item=status_data}
                    <option value="{$status_code}" {if $status_code == ($smarty.request.entity_status|default:$mapping.entity_status)}selected="selected"{/if}>
                        {$status_code} - {$status_data.description}
                    </option>
                {/foreach}
            </select>
        </div>
    </div>

    <div class="control-group">
        <label for="planfix_status_id" class="control-label cm-required">{__("mwl_xlsx.planfix_status_id")}:</label>
        <div class="controls">
            <input type="text" name="planfix_status_id" id="planfix_status_id" value="{$smarty.request.planfix_status_id|default:$mapping.planfix_status_id}" required />
        </div>
    </div>

    <div class="control-group">
        <label for="planfix_status_name" class="control-label">{__("mwl_xlsx.planfix_status_name")}:</label>
        <div class="controls">
            <input type="text" name="planfix_status_name" id="planfix_status_name" value="{$smarty.request.planfix_status_name|default:$mapping.planfix_status_name}" />
        </div>
    </div>

    <div class="control-group">
        <label for="is_default" class="control-label">{__("mwl_xlsx.is_default")}:</label>
        <div class="controls">
            <input type="hidden" name="is_default" value="0" />
            <input type="checkbox" name="is_default" id="is_default" value="1" {if $smarty.request.is_default|default:$mapping.is_default}checked="checked"{/if} />
        </div>
    </div>

</form>

{capture name="buttons"}
    {include file="buttons/button.tpl"
        but_role="submit"
        but_target_form="mwl_xlsx_status_map_form"
        but_text=__("save")
        but_meta="btn-primary"
    }
    {include file="buttons/button.tpl"
        but_role="text"
        but_text=__("cancel")
        but_href="?dispatch=mwl_xlsx_status_map.manage"
    }
{/capture}

</capture>

{include file="common/mainbox.tpl"
    title="{__("mwl_xlsx."|cat:($smarty.request.map_id|default:0 ? 'edit' : 'add')|cat:"_status_mapping")}"
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
