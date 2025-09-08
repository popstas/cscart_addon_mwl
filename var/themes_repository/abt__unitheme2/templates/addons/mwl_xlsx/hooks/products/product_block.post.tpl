{* "add all to list", design/themes/abt__unitheme2/templates/addons/mwl_xlsx/hooks/products/product_block.post.tpl *}
{if fn_mwl_xlsx_user_can_access_lists($auth) && $addons.mwl_xlsx.show_extra_button == "Y"}
    {if ($runtime.controller == 'categories' && $runtime.mode == 'view')
    || ($runtime.controller == 'companies' && $runtime.mode == 'products')
    }

        {if $smarty.foreach.products.first}
            {assign var="mwl_xlsx_ids" value=[] scope="root"}
        {/if}

        {append var="mwl_xlsx_ids" value=$product.product_id scope="root"}

        {if $smarty.foreach.products.last}
            <span class="mwl-products-on-page"
                  data-block-id="{$block.block_id|default:0}"
                  data-product-ids-json='{$mwl_xlsx_ids|json_encode nofilter}'
                  hidden></span>

            <script>
            (function(_, $) {
              _.mwl_products_on_page = _.mwl_products_on_page || {};
              var bid = {$block.block_id|default:0};
              var ids = {$mwl_xlsx_ids|json_encode nofilter} || [];
              if (!Array.isArray(ids)) {
                ids = String(ids || '').split(',').filter(Boolean).map(Number);
              }
              var prev = _.mwl_products_on_page[bid] || [];
              var uniq = {};
              prev.concat(ids).forEach(function(x){ if (x != null) uniq[x] = 1; });
              _.mwl_products_on_page[bid] = Object.keys(uniq).map(Number);
              $.ceEvent('trigger', 'mwl.products_on_page.updated', [bid, _.mwl_products_on_page[bid]]);
            }(Tygh, Tygh.$));
            </script>

            {if $auth}
                {assign var=lists value=fn_mwl_xlsx_get_lists($auth.user_id)}
            {else}
                {assign var=lists value=fn_mwl_xlsx_get_lists(null)}
            {/if}
            <div class="mwl_xlsx-control mwl_xlsx-control--category">
                <select class="mwl_xlsx-select" data-ca-list-select-xlsx>
                    {foreach $lists as $l}
                        <option value="{$l.list_id}">{$l.name}</option>
                    {/foreach}
                    <option value="_new">+ {__("mwl_xlsx.new_list")}</option>
                </select>
                <input type="text" class="mwl_xlsx-new-name ty-input-text" data-ca-mwl-new-list-name style="display:none" placeholder="{__("mwl_xlsx.enter_list_name")}">
                <button class="ty-btn" data-ca-add-all-to-mwl_xlsx data-ca-product-ids="{","|implode:$mwl_xlsx_ids}">
                    {__("mwl_xlsx.add_all_to_wishlist")}
                </button>
            </div>
        {/if}

    {/if}
{/if}
