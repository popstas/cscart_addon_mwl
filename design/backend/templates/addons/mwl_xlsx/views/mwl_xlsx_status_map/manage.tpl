{capture name="mainbox"}

<form action="{""|fn_url}" method="post" class="form-horizontal form-edit" name="mwl_xlsx_status_map_form">

<div class="control-toolbar clearfix">
    <div class="buttons-container buttons-bg">
        {include file="buttons/button.tpl"
            but_role="text"
            but_text=__("mwl_xlsx.add_status_mapping")
            but_href="?dispatch=mwl_xlsx_status_map.add"
            but_meta="btn-primary"
        }
    </div>
</div>

<div class="table-responsive-wrapper">
    <table class="table table-middle table-responsive">
        <thead>
            <tr>
                <th width="15%">{__("mwl_xlsx.entity_type")}</th>
                <th width="15%">{__("mwl_xlsx.entity_status")}</th>
                <th width="15%">{__("mwl_xlsx.planfix_status_id")}</th>
                <th width="25%">{__("mwl_xlsx.planfix_status_name")}</th>
                <th width="10%" class="center">{__("mwl_xlsx.is_default")}</th>
                <th width="20%" class="right">{__("tools")}</th>
            </tr>
        </thead>
        <tbody>
        {if $mappings}
            {foreach from=$mappings item=mapping}
            <tr>
                <td>{$mapping.entity_type}</td>
                <td>{$mapping.entity_status} - {$entity_statuses[$mapping.entity_status].description}</td>
                <td>{$mapping.planfix_status_id}</td>
                <td>{$mapping.planfix_status_name}</td>
                <td class="center">
                    {if $mapping.is_default}
                        <span class="label label-success">{__("yes")}</span>
                    {else}
                        <span class="label label-default">{__("no")}</span>
                    {/if}
                </td>
                <td class="right">
                    {include file="buttons/button.tpl"
                        but_role="text"
                        but_text=__("edit")
                        but_href="?dispatch=mwl_xlsx_status_map.update&map_id=`$mapping.map_id`"
                        but_meta="btn btn-small"
                    }
                    {include file="buttons/button.tpl"
                        but_role="text"
                        but_meta="btn btn-small cm-confirm cm-post"
                        but_href="?dispatch=mwl_xlsx_status_map.delete&map_id=`$mapping.map_id`"
                    }
                </td>
            </tr>
            {/foreach}
        {else}
            <tr class="no-items">
                <td colspan="6" class="center">
                    {__("no_data")}
                </td>
            </tr>
        {/if}
        </tbody>
    </table>
</div>

</form>

{capture name="buttons"}
    {include file="buttons/button.tpl"
        but_role="text"
        but_text=__("mwl_xlsx.add_status_mapping")
        but_href="?dispatch=mwl_xlsx_status_map.add"
        but_meta="btn-primary"
    }
{/capture}

{/capture}

{include file="common/mainbox.tpl"
    title=__("mwl_xlsx.status_map_management")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
}
