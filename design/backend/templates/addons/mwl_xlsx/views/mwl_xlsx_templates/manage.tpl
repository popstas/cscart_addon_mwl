{capture name="mainbox"}

<form action="{""|fn_url}" method="post" class="form-horizontal form-edit" enctype="multipart/form-data" name="mwl_xlsx_upload_form">
    <input type="hidden" name="result_ids" value="content_templates_list"/>

    <div class="control-group">
        <label class="control-label">{__("upload")}</label>
        <div class="controls">
            {include file="common/fileuploader.tpl"
                var_name="xlsx_template"
                allowed_ext="xlsx"
                upload_an_image=false
                label=__("mwl_xlsx.upload_xlsx_template")
            }
            {include file="buttons/button.tpl"
                but_role="submit"
                but_text=__("upload")
                but_name="dispatch[mwl_xlsx_templates.upload]"
                but_meta="btn-primary"
            }
        </div>
    </div>
</form>

<div id="content_templates_list">
    {if $mwl_xlsx_templates}
        <table class="table table-middle">
            <thead>
                <tr>
                    <th width="40">#</th>
                    <th>{__("name")}</th>
                    <th class="center">{__("size")}</th>
                    <th class="right">{__("date")}</th>
                    <th class="right">{__("tools")}</th>
                </tr>
            </thead>
            <tbody>
            {foreach from=$mwl_xlsx_templates item=t}
                <tr>
                    <td>{$t.template_id}</td>
                    <td>{$t.name}</td>
                    <td class="center">{($t.size/1024)|ceil} KB</td>
                    <td class="right">{($t.created_at)|date_format:"%d.%m.%Y %H:%M"}</td>
                    <td class="right">
                        {include file="buttons/button.tpl"
                            but_role="text"
                            but_meta="cm-confirm cm-post"
                            but_text=__("delete")
                            but_name="dispatch[mwl_xlsx_templates.delete]"
                            but_href="mwl_xlsx_templates.delete?template_id=`$t.template_id`"
                        }
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {else}
        <p class="no-items">{__("no_items")}</p>
    {/if}
<!--content_templates_list--></div>

{/capture}

{include file="common/mainbox.tpl"
    title=__("mwl_xlsx.xlsx_templates")
    content=$smarty.capture.mainbox
}

