(function(_, $) {
    $(document).on('click', '[data-ca-add-to-mwl_xlsx]', function(e) {
        e.preventDefault();
        var product_id = $(this).data('caProductId');
        var list_id = $(this).data('caListId');
        addToList(product_id, list_id);
    });

    $(document).on('click', '[data-ca-save-new-list]', function(e) {
        e.preventDefault();
        var product_id = $(this).data('caProductId');
        var name = $(this).closest('.mwl_xlsx-new').find('[data-ca-new-list-name]').val();

        if (name) {
            $.ceAjax('request', fn_url('mwl_xlsx.create_list'), {
                method: 'post',
                data: { name: name },
                callback: function(data) {
                    addToList(product_id, data.list_id);
                }
            });
        }
    });

    function addToList(product_id, list_id) {
        $.ceAjax('request', fn_url('mwl_xlsx.add'), {
            method: 'post',
            data: { product_id: product_id, list_id: list_id },
            callback: function() {
                $.ceNotification('show', {
                    type: 'N',
                    title: _.tr('notice'),
                    message: _.tr('mwl_xlsx.added')
                });
            }
        });
    }
})(Tygh, Tygh.$);

