{if $order_info.order_id}
    {assign var="planfix_link" value=$mwl_planfix_order_link|default:[]}
    {assign var="planfix_meta" value=$planfix_link.extra.planfix_meta|default:[]}
    {assign var="last_incoming" value=$planfix_link.extra.last_incoming_status|default:[]}
    {assign var="last_outgoing" value=$planfix_link.extra.last_outgoing_status|default:[]}
    {assign var="last_payload_in" value=$planfix_link.extra.last_planfix_payload_in|default:[]}
    {assign var="has_link" value=($planfix_link.planfix_object_id|default:'') != ''}
    <div id="content_mwl_planfix" class="{if $selected_section && $selected_section != "mwl_planfix"}hidden cm-hide-save-button{/if}">
        <div class="well well-small">
            <div class="control-group">
                <label class="control-label">{__("mwl_xlsx.planfix_linked_task")}</label>
                <div class="controls">
                    {if $planfix_link.planfix_object_id|default:''}
                        {if $planfix_link.planfix_url|default:''}
                            <a href="{$planfix_link.planfix_url|escape:'html'}" target="_blank" rel="noopener noreferrer">
                                {$planfix_link.planfix_object_id|escape}
                            </a>
                        {else}
                            {$planfix_link.planfix_object_id|escape}
                        {/if}
                    {else}
                        <span class="muted">{__("mwl_xlsx.planfix_no_link")}</span>
                    {/if}
                </div>
            </div>

            <div class="control-group">
                <label class="control-label">{__("mwl_xlsx.planfix_actions")}</label>
                <div class="controls">
                    <form action="{"mwl_xlsx.planfix_create_task"|fn_url}" method="post" class="form-inline">
                        <input type="hidden" name="dispatch" value="mwl_xlsx.planfix_create_task" />
                        <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                        <input type="hidden" name="return_url" value="{$config.current_url|fn_url}" />
                        {include file="buttons/button.tpl"
                            but_role="submit"
                            but_meta="btn btn-primary"
                            but_text=__("mwl_xlsx.planfix_button_create")
                            but_disabled=$has_link
                        }
                    </form>

                    <form action="{"mwl_xlsx.planfix_bind_task"|fn_url}" method="post" class="form-inline">
                        <input type="hidden" name="dispatch" value="mwl_xlsx.planfix_bind_task" />
                        <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                        <input type="hidden" name="planfix_object_type" value="task" />
                        <input type="hidden" name="return_url" value="{$config.current_url|fn_url}" />
                        <div class="input-append">
                            <input type="text" class="input-medium" name="planfix_task_id" placeholder="{__("mwl_xlsx.planfix_placeholder_task_id")}" value="" />
                            {include file="buttons/button.tpl"
                                but_role="submit"
                                but_meta="btn"
                                but_text=__("mwl_xlsx.planfix_button_bind")
                            }
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <h4 class="subheader">{__("mwl_xlsx.planfix_metadata_heading")}</h4>
        <table class="table table-middle">
            <tbody>
            <tr>
                <th>{__("mwl_xlsx.planfix_status_id")}</th>
                <td>{$planfix_meta.status_id|default:''|escape}</td>
            </tr>
            <tr>
                <th>{__("mwl_xlsx.planfix_direction")}</th>
                <td>{$planfix_meta.direction|default:''|escape}</td>
            </tr>
            <tr>
                <th>{__("mwl_xlsx.planfix_last_status_from_planfix")}</th>
                <td>
                    {if $last_incoming.status_id|default:''}
                        {$last_incoming.status_id|escape}
                        <small class="muted">{$last_incoming.received_at|default:0|date_format:"`$settings.Appearance.date_format` `$settings.Appearance.time_format`"}</small>
                    {else}
                        <span class="muted">{__("mwl_xlsx.planfix_no_data")}</span>
                    {/if}
                </td>
            </tr>
            <tr>
                <th>{__("mwl_xlsx.planfix_last_status_from_cs")}</th>
                <td>
                    {if $last_outgoing.status_to|default:''}
                        {$last_outgoing.status_to|escape}
                        <small class="muted">{$last_outgoing.pushed_at|default:0|date_format:"`$settings.Appearance.date_format` `$settings.Appearance.time_format`"}</small>
                    {else}
                        <span class="muted">{__("mwl_xlsx.planfix_no_data")}</span>
                    {/if}
                </td>
            </tr>
            <tr>
                <th>{__("mwl_xlsx.planfix_last_push_at")}</th>
                <td>
                    {if $planfix_link.last_push_at|default:0}
                        {$planfix_link.last_push_at|date_format:"`$settings.Appearance.date_format` `$settings.Appearance.time_format`"}
                    {else}
                        <span class="muted">{__("mwl_xlsx.planfix_no_data")}</span>
                    {/if}
                </td>
            </tr>
            <tr>
                <th>{__("mwl_xlsx.planfix_last_payload_out")}</th>
                <td>
                    {if $planfix_link.last_payload_out_decoded|default:false}
                        <pre class="pre">{$planfix_link.last_payload_out_decoded|@print_r:true}</pre>
                    {elseif $planfix_link.last_payload_out|default:''}
                        <pre class="pre">{$planfix_link.last_payload_out|escape}</pre>
                    {else}
                        <span class="muted">{__("mwl_xlsx.planfix_no_data")}</span>
                    {/if}
                </td>
            </tr>
            <tr>
                <th>{__("mwl_xlsx.planfix_last_payload_in")}</th>
                <td>
                    {if $last_payload_in.payload|default:false}
                        <pre class="pre">{$last_payload_in.payload|@print_r:true}</pre>
                    {else}
                        <span class="muted">{__("mwl_xlsx.planfix_no_data")}</span>
                    {/if}
                </td>
            </tr>
            </tbody>
        </table>
    </div>
{/if}
