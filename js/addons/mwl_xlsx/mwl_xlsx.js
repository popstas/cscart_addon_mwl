(function(_, $) {
    $.ceEvent('on', 'ce.commoninit', function(context) {
        var last_list_id = localStorage.getItem('mwl_last_list');
        $('[data-ca-list-select-xlsx]', context).each(function() {
            var $select = $(this);
            if (last_list_id && $select.find('option[value="' + last_list_id + '"]').length) {
                $select.val(last_list_id).trigger('change');
            }
        });
    });

    $(document).on('change', '[data-ca-list-select-xlsx]', function() {
        var $input = $('[data-ca-mwl-new-list-name]');
        var value = $(this).val();
        if (value === '_new') {
            $input.show().focus();
        } else {
            $input.hide();
            localStorage.setItem('mwl_last_list', value);
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
                        data = parseResponse(data);
                        list_id = data.list_id;
                        $select.prepend($('<option>', { value: list_id, text: data.name }));
                        $select.val(list_id);
                        $('[data-ca-mwl-new-list-name]').val('').hide();
                        localStorage.setItem('mwl_last_list', list_id);
                        addToList(product_id, list_id);
                    }
                });
            }
        } else {
            localStorage.setItem('mwl_last_list', list_id);
            addToList(product_id, list_id);
        }
        return false;
    });

    var $renameDialog = $('#mwl_xlsx_rename_dialog');
    var $deleteDialog = $('#mwl_xlsx_delete_dialog');

    $(document).on('click', '[data-ca-mwl-rename]', function() {
        var $li = $(this).closest('[data-ca-mwl-list-id]');
        var list_id = $li.data('caMwlListId');
        var current_name = $li.find('[data-ca-mwl-list-name]').text();
        $renameDialog.data('caMwlListId', list_id);
        $('#mwl_xlsx_rename_input').val(current_name);
        $renameDialog.ceDialog('open', {
            title: _.tr('mwl_xlsx.rename') || 'Rename'
        });
        return false;
    });

    $(document).on('click', '[data-ca-mwl-rename-save]', function() {
        var list_id = $renameDialog.data('caMwlListId');
        var name = $('#mwl_xlsx_rename_input').val().trim();
        if (name) {
            $.ceAjax('request', fn_url('mwl_xlsx.rename_list'), {
                method: 'post',
                data: { list_id: list_id, name: name },
                callback: function(data) {
                    data = parseResponse(data);
                    if (data && data.success) {
                        location.reload();
                    }
                }
            });
        }
        $renameDialog.ceDialog('close');
        return false;
    });

    $(document).on('click', '[data-ca-mwl-rename-cancel]', function() {
        $renameDialog.ceDialog('close');
        return false;
    });

    $(document).on('click', '[data-ca-mwl-delete]', function() {
        var list_id = $(this).closest('[data-ca-mwl-list-id]').data('caMwlListId');
        $deleteDialog.data('caMwlListId', list_id);
        $deleteDialog.ceDialog('open', {
            title: _.tr('mwl_xlsx.remove') || 'Remove'
        });
        return false;
    });

    $(document).on('click', '[data-ca-mwl-delete-confirm]', function() {
        var list_id = $deleteDialog.data('caMwlListId');
        $.ceAjax('request', fn_url('mwl_xlsx.delete_list'), {
            method: 'post',
            data: { list_id: list_id },
            callback: function(data) {
                data = parseResponse(data);
                if (data && data.success) {
                    location.reload();
                }
            }
        });
        $deleteDialog.ceDialog('close');
        return false;
    });

    $(document).on('click', '[data-ca-mwl-delete-cancel]', function() {
        $deleteDialog.ceDialog('close');
        return false;
    });

    function addToList(product_id, list_id) {
        $.ceAjax('request', fn_url('mwl_xlsx.add'), {
            method: 'post',
            data: { product_id: product_id, list_id: list_id },
            callback: function(data) {
                data = parseResponse(data);
                var message = (data && data.message) ? data.message : (_.tr('mwl_xlsx.added_plain') || 'Added to wishlist');
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

    function parseResponse(data) {
        if (data && typeof data.text === 'string') {
            try {
                return JSON.parse(data.text);
            } catch (e) {
            }
        }
        return data || {};
    }
})(Tygh, Tygh.$);

