(function(_, $) {
    $(document).on('click', '[data-ca-add-to-mwl_xlsx]', function() {
        var product_id = $(this).data('caProductId');
        var list_id = $('[data-ca-list-select-xlsx]').val();
        if (list_id === '_new') {
            var name = prompt(_.tr('mwl_xlsx.enter_list_name'));
            if (name) {
                $.ceAjax('request', fn_url('mwl_xlsx.create_list'), {
                    method: 'post',
                    data: { name: name },
                    callback: function(data) {
                        list_id = data.list_id;
                        addToList(product_id, list_id);
                    }
                });
            }
        } else {
            addToList(product_id, list_id);
        }
        return false;
    });

    function addToList(product_id, list_id) {
        $.ceAjax('request', fn_url('mwl_xlsx.add'), {
            method: 'post',
            data: { product_id: product_id, list_id: list_id },
            callback: function() {
                alert(_.tr('mwl_xlsx.added'));
            }
        });
    }
})(Tygh, Tygh.$);

