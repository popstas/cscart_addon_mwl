{capture name="mainbox"}
    <form action="{fn_url('')}" method="post" class="form-horizontal form-edit" name="mwl_xlsx_settings_form">
        <input type="hidden" name="dispatch" value="mwl_xlsx.settings" />

        <div class="control-group">
            <label class="control-label" for="elm_hide_price_for_guests">{__("mwl_xlsx.hide_price_for_guests")}:</label>
            <div class="controls">
                <input type="hidden" name="mwl_xlsx[hide_price_for_guests]" value="N" />
                <input type="checkbox" name="mwl_xlsx[hide_price_for_guests]" id="elm_hide_price_for_guests" value="Y" {if $mwl_xlsx.hide_price_for_guests == 'Y'}checked="checked"{/if} />
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="elm_authorized_usergroups">{__("mwl_xlsx.authorized_usergroups")}:</label>
            <div class="controls">
                <select id="elm_authorized_usergroups" name="mwl_xlsx[authorized_usergroups][]" multiple="multiple" size="5">
                    {foreach from=$usergroups item=ug}
                        <option value="{$ug.usergroup_id}" {if $ug.usergroup_id|in_array:$mwl_xlsx.authorized_usergroups}selected="selected"{/if}>{$ug.usergroup}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="elm_show_extra_button">{__("mwl_xlsx.show_extra_button")}:</label>
            <div class="controls">
                <input type="hidden" name="mwl_xlsx[show_extra_button]" value="N" />
                <input type="checkbox" name="mwl_xlsx[show_extra_button]" id="elm_show_extra_button" value="Y" {if $mwl_xlsx.show_extra_button == 'Y'}checked="checked"{/if} />
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="elm_allowed_usergroups">{__("mwl_xlsx.allowed_usergroups")}:</label>
            <div class="controls">
                <select id="elm_allowed_usergroups" name="mwl_xlsx[allowed_usergroups][]" multiple="multiple" size="5">
                    {foreach from=$usergroups item=ug}
                        <option value="{$ug.usergroup_id}" {if $ug.usergroup_id|in_array:$mwl_xlsx.allowed_usergroups}selected="selected"{/if}>{$ug.usergroup}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="elm_compact_price_slider_labels">{__("mwl_xlsx.setting.compact_price_slider_labels")}:</label>
            <div class="controls">
                <input type="hidden" name="mwl_xlsx[compact_price_slider_labels]" value="N" />
                <input type="checkbox" name="mwl_xlsx[compact_price_slider_labels]" id="elm_compact_price_slider_labels" value="Y" {if $mwl_xlsx.compact_price_slider_labels == 'Y'}checked="checked"{/if} />
                <p class="muted description">{__("mwl_xlsx.setting.compact_price_slider_labels.desc")}</p>
            </div>
        </div>

        <div class="buttons-container">
            {include file="buttons/save_changes.tpl" but_name="dispatch[mwl_xlsx.settings]"}
        </div>
    </form>
{/capture}

{include file="common/mainbox.tpl" title=__('settings') content=$smarty.capture.mainbox}
