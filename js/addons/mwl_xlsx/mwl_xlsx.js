(function(_, $) {
    $(document).on('change', '[data-ca-list-select-xlsx]', function() {
        var $input = $('[data-ca-mwl-new-list-name]');
        if ($(this).val() === '_new') {
            $input.show().focus();
        } else {
            $input.hide();
        }
    });

    $(document).on('click', '[data-ca-add-to-mwl_xlsx]', function() {
        var product_id = $(this).data('caProductId');
        var $select = $('[data-ca-list-select-xlsx]');
        var list_id = $select.val();
        if (list_id === '_new') {
            var name = $('[data-ca-mwl-new-list-name]').val();
            if (name) {
                $.ceAjax('request', fn_url('mwl_xlsx.create_list'), {
                    method: 'post',
                    data: { name: name },
                    callback: function(data) {
                        list_id = data.list_id;
                        $select.prepend($('<option>', { value: list_id, text: data.name }));
                        $select.val(list_id);
                        $('[data-ca-mwl-new-list-name]').val('').hide();
                        addToList(product_id, list_id);
                    }
                });
            }
        } else {
            addToList(product_id, list_id);
        }
        return false;
    });

    $(document).on('click', '[data-ca-mwl-rename]', function() {
        var $li = $(this).closest('[data-ca-mwl-list-id]');
        var list_id = $li.data('caMwlListId');
        var current_name = $li.find('[data-ca-mwl-list-name]').text();
        var name = prompt(_.tr('mwl_xlsx.enter_list_name') || 'Enter list name', current_name);
        if (name && name !== current_name) {
            $.ceAjax('request', fn_url('mwl_xlsx.rename_list'), {
                method: 'post',
                data: { list_id: list_id, name: name },
                callback: function(data) {
                    if (data && data.success) {
                        $li.find('[data-ca-mwl-list-name]').text(data.name);
                    }
                }
            });
        }
        return false;
    });

    $(document).on('click', '[data-ca-mwl-delete]', function() {
        var $li = $(this).closest('[data-ca-mwl-list-id]');
        var list_id = $li.data('caMwlListId');
        if (confirm(_.tr('mwl_xlsx.confirm_remove') || 'Remove this list?')) {
            $.ceAjax('request', fn_url('mwl_xlsx.delete_list'), {
                method: 'post',
                data: { list_id: list_id },
                callback: function(data) {
                    if (data && data.success) {
                        $li.remove();
                        if (!$('[data-ca-mwl-list-id]').length) {
                            location.reload();
                        }
                    }
                }
            });
        }
        return false;
    });

    function addToList(product_id, list_id) {
        $.ceAjax('request', fn_url('mwl_xlsx.add'), {
            method: 'post',
            data: { product_id: product_id, list_id: list_id },
            callback: function(data) {
                var message = (data && data.message) ? data.message : (_.tr('mwl_xlsx.added') || 'Added to wishlist');
                $.ceNotification('show', {
                    type: 'N',
                    title: '',
                    message: message,
                    message_state: 'I',
                    overlay: true
                });
            }
        });
    }
})(Tygh, Tygh.$);

