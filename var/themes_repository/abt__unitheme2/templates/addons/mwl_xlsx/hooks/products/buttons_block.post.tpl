{if fn_mwl_xlsx_user_can_access_lists($auth)}
    {assign var=is_lists value=(
        ($runtime.controller == 'products' && ($runtime.mode == 'view' || $runtime.mode == 'search'))
        || ($runtime.controller == 'categories' && $runtime.mode == 'view')
        || ($runtime.controller == 'companies' && $runtime.mode == 'products')
    )}
    {assign var=is_price_request value=(fn_mwl_xlsx_can_view_price($auth) && ($runtime.controller == 'products' && $runtime.mode == 'view'))}
    {assign var="view_layout" value=$selected_layout|default:$smarty.request.layout|default:$smarty.cookies.selected_layout|default:$settings.Appearance.default_products_view}
    {if $is_lists}
        {if $auth}
            {assign var=lists value=fn_mwl_xlsx_get_lists($auth.user_id)}
        {else}
            {assign var=lists value=fn_mwl_xlsx_get_lists(null)}
        {/if}

        <div class="mwl_xlsx-control">
            <select class="mwl_xlsx-select hidden" data-ca-list-select-xlsx>
                {foreach $lists as $l}
                    <option value="{$l.list_id}">{$l.name}</option>
                {/foreach}
                <option value="_new">+ {__("mwl_xlsx.new_list")}</option>
            </select>
            <button class="ty-btn" data-ca-add-to-mwl_xlsx data-ca-product-id="{$product.product_id}">
                {if $view_layout == "products_without_options" || $view_layout == "products_multicolumns"}
                    {__("mwl_xlsx.add_to_wishlist")}
                {else}
                    <i class="ut2-icon-article"></i>
                {/if}
            </button>

            {if $view_layout == "products_without_options"}
                <span class="ty-price-hint cm-tooltip ty-icon-help-circle" title="{__("mwl_xlsx.tooltip")}">
                </span>
            {/if}
        </div>

        {* Запросить проверку цены - Предположим, у нас есть переменные $item_id, $field_name, $field_value *}
        {assign var="item_id" value=$product.product_id}
        {assign var="field_name" value="price"}
        {assign var="field_value" value=$product.price}
        {if $is_price_request}
            <a
                href="{"mwl_xlsx.request_price_check?item_id=`$item_id|escape:url`&field=`$field_name|escape:url`&value=`$field_value|escape:url`"|fn_url}"
                data-ca-target-id="ajax_empty"
                data-ca-scroll="false"
                data-ca-ajax-preload="true"
                data-ca-ajax-full-render="false"
                class="request-price-check-btn ty-btn ty-btn__secondary cm-ajax cm-post"
            >
                {__('mwl_xlsx.price_check_button')}
            </a>
            {* Невидимая цель для cm-ajax, чтобы не перерисовывать страницу *}
            <div id="ajax_empty" class="hidden"></div>
        {/if}


    {elseif !empty($is_mwl_xlsx_view)}
        <div class="mwl_xlsx-control">
            <br>
            <button class="ty-btn" data-ca-remove-from-mwl_xlsx data-ca-product-id="{$product.product_id}" data-ca-list-id="{$product.mwl_list_id}">
                {__("mwl_xlsx.remove_from_list")}
            </button>
        </div>
    {/if}
{/if}
