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
            <label class="control-label" for="elm_hide_features">{__("mwl_xlsx.hide_features")}:</label>
            <div class="controls">
                <select id="elm_hide_features" name="mwl_xlsx[hide_features][]" multiple="multiple" size="10">
                    {foreach from=$product_features item=feature}
                        <option value="{$feature.feature_id}" {if $feature.feature_id|in_array:$mwl_xlsx.hide_features}selected="selected"{/if}>{$feature.description}</option>
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

        <div class="control-group">
            <label class="control-label" for="elm_linkify_feature_urls">{__("mwl_xlsx.setting.linkify_feature_urls")}:</label>
            <div class="controls">
                <input type="hidden" name="mwl_xlsx[linkify_feature_urls]" value="N" />
                <input type="checkbox" name="mwl_xlsx[linkify_feature_urls]" id="elm_linkify_feature_urls" value="Y" {if $mwl_xlsx.linkify_feature_urls == 'Y'}checked="checked"{/if} />
                <p class="muted description">{__("mwl_xlsx.setting.linkify_feature_urls.desc")}</p>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="elm_format_feature_numbers">{__("mwl_xlsx.setting.format_feature_numbers")}:</label>
            <div class="controls">
                <input type="hidden" name="mwl_xlsx[format_feature_numbers]" value="N" />
                <input type="checkbox" name="mwl_xlsx[format_feature_numbers]" id="elm_format_feature_numbers" value="Y" {if $mwl_xlsx.format_feature_numbers == 'Y'}checked="checked"{/if} />
                <p class="muted description">{__("mwl_xlsx.setting.format_feature_numbers.desc")}</p>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="elm_show_price_hint">{__("mwl_xlsx.setting.show_price_hint")}:</label>
            <div class="controls">
                <input type="hidden" name="mwl_xlsx[show_price_hint]" value="N" />
                <input type="checkbox" name="mwl_xlsx[show_price_hint]" id="elm_show_price_hint" value="Y" {if $mwl_xlsx.show_price_hint == 'Y'}checked="checked"{/if} />
                <p class="muted description">{__("mwl_xlsx.setting.show_price_hint.desc")}</p>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="elm_auto_detect_language">{__("mwl_xlsx.setting.auto_detect_language")}:</label>
            <div class="controls">
                <input type="hidden" name="mwl_xlsx[auto_detect_language]" value="N" />
                <input type="checkbox" name="mwl_xlsx[auto_detect_language]" id="elm_auto_detect_language" value="Y" {if $mwl_xlsx.auto_detect_language == 'Y'}checked="checked"{/if} />
                <p class="muted description">{__("mwl_xlsx.setting.auto_detect_language.desc")}</p>
            </div>
        </div>

        <div class="buttons-container">
            {include file="buttons/save_changes.tpl" but_name="dispatch[mwl_xlsx.settings]"}
        </div>
    </form>
{/capture}

{include file="common/mainbox.tpl" title=__('settings') content=$smarty.capture.mainbox}
