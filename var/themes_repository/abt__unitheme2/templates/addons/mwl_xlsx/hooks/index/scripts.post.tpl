<script>
(function (_, $) {
  _.tr({
    "add": '{__("add")|escape:"javascript"}',
    "cancel": '{__("cancel")|escape:"javascript"}',
    "mwl_xlsx.add_to_wishlist": '{__("mwl_xlsx.add_to_wishlist")|escape:"javascript"}',
    "mwl_xlsx.added_plain": '{__("mwl_xlsx.added_plain")|escape:"javascript"}',
    "mwl_xlsx.added_short": '{__("mwl_xlsx.added_short")|escape:"javascript"}',
    "mwl_xlsx.default_list_name": '{__("mwl_xlsx.default_list_name")|escape:"javascript"}',
    "mwl_xlsx.enter_list_name": '{__("mwl_xlsx.enter_list_name")|escape:"javascript"}',
    "mwl_xlsx.go_to_list": '{__("mwl_xlsx.go_to_list")|escape:"javascript"}',
    "mwl_xlsx.my_lists": '{__("mwl_xlsx.my_lists")|escape:"javascript"}',
    "mwl_xlsx.new_list": '{__("mwl_xlsx.new_list")|escape:"javascript"}',
    "mwl_xlsx.remove": '{__("mwl_xlsx.remove")|escape:"javascript"}',
    "mwl_xlsx.removed": '{__("mwl_xlsx.removed")|escape:"javascript"}',
    "mwl_xlsx.rename": '{__("mwl_xlsx.rename")|escape:"javascript"}',
    "mwl_xlsx.select_list": '{__("mwl_xlsx.select_list")|escape:"javascript"}',
    "mwl_xlsx.shortnum_trillion": '{__("mwl_xlsx.shortnum_trillion")|escape:"javascript"}',
    "mwl_xlsx.shortnum_billion": '{__("mwl_xlsx.shortnum_billion")|escape:"javascript"}',
    "mwl_xlsx.shortnum_million": '{__("mwl_xlsx.shortnum_million")|escape:"javascript"}',
    "mwl_xlsx.shortnum_thousand": '{__("mwl_xlsx.shortnum_thousand")|escape:"javascript"}',
    "save": '{__("save")|escape:"javascript"}',
  });
})(Tygh, Tygh.$);
</script>

{script src="js/addons/mwl_xlsx/mwl_xlsx.js"}
{script src="js/addons/mwl_xlsx/short_price_slider.js"}
<script>
Tygh.addons = Tygh.addons || {};
Tygh.addons.mwl_xlsx = Tygh.addons.mwl_xlsx || {};
Tygh.addons.mwl_xlsx.max_list_items = {$addons.mwl_xlsx.max_list_items|default:0};
Tygh.addons.mwl_xlsx.compact_price_slider_labels = {if $addons.mwl_xlsx.compact_price_slider_labels == "Y"}true{else}false{/if};
</script>
<script>
  window.MWL_USER_ID = {$auth.user_id|default:0};
</script>
