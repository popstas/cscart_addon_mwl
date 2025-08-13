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

  $(_.doc).on('change', '[data-ca-list-select-xlsx]', function() {
    var value = $(this).val();
    if (value !== '_new') {
      localStorage.setItem('mwl_last_list', value);
    }
  });

  $(_.doc).on('click', '[data-ca-add-to-mwl_xlsx]', function() {
    var product_id = $(this).data('caProductId');
    var $control = $(this).closest('.mwl_xlsx-control');
    var $select = $control.find('[data-ca-list-select-xlsx]');
    resolveListId($select, function(list_id) {
      if (!list_id) { return; }
      addToList(product_id, list_id);
    });
    return false;
  });

  $(_.doc).on('click', '[data-ca-add-all-to-mwl_xlsx]', function() {
    var $btn = $(this);
    var product_ids = ($btn.data('caProductIds') || '').toString().split(',');
    var $control = $btn.closest('.mwl_xlsx-control');
    var $select = $control.find('[data-ca-list-select-xlsx]');
    resolveListId($select, function(list_id) {
      if (!list_id) { return; }
      addProductsToList(product_ids, list_id);
    });
    return false;
  });

  var $renameDialog = $('#mwl_xlsx_rename_dialog');
  var $deleteDialog = $('#mwl_xlsx_delete_dialog');
  var $newListDialog = $('#mwl_xlsx_new_list_dialog');
  if (!$newListDialog.length) {
    $('body').append(
      '<div id="mwl_xlsx_new_list_dialog" class="hidden">' +
      '<div class="ty-control-group">' +
      '<label for="mwl_xlsx_new_list_input" class="ty-control-group__title">' + (_.tr('mwl_xlsx.enter_list_name') || 'Enter list name') + '</label>' +
      '<input type="text" id="mwl_xlsx_new_list_input" maxlength="50" class="ty-input-text" />' +
      '</div>' +
      '<div class="buttons-container">' +
      '<button class="ty-btn ty-btn__primary" data-ca-mwl-new-list-ok>' + (_.tr('save') || 'Save') + '</button>' +
      '<button class="ty-btn" data-ca-mwl-new-list-cancel>' + (_.tr('cancel') || 'Cancel') + '</button>' +
      '</div>' +
      '</div>'
    );
    $newListDialog = $('#mwl_xlsx_new_list_dialog');
  }

  // New list dialog controls
  $(_.doc).on('click', '[data-ca-mwl-new-list-ok]', function() {
    var name = $('#mwl_xlsx_new_list_input').val().trim();
    var cb = $newListDialog.data('caMwlOnOk');
    if (name && typeof cb === 'function') {
      cb(name);
    }
    $newListDialog.ceDialog('close');
    return false;
  });

  $(_.doc).on('keydown', '#mwl_xlsx_new_list_input', function(e) {
    if (e.keyCode === 13) { // Enter
      $('[data-ca-mwl-new-list-ok]').trigger('click');
      e.preventDefault();
    }
  });

  $(_.doc).on('click', '[data-ca-mwl-new-list-cancel]', function() {
    $newListDialog.ceDialog('close');
    return false;
  });

  function getListName(callback) {
    $('#mwl_xlsx_new_list_input').val('');
    $newListDialog.data('caMwlOnOk', callback);
    $newListDialog.ceDialog('open', {
      title: _.tr('mwl_xlsx.new_list') || 'New media list'
    });
    setTimeout(function() { $('#mwl_xlsx_new_list_input').focus(); }, 0);
  }

  $(_.doc).on('click', '[data-ca-mwl-rename]', function() {
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

  $(_.doc).on('click', '[data-ca-mwl-rename-save]', function() {
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

  $(_.doc).on('keydown', '#mwl_xlsx_rename_input', function(e) {
    if (e.keyCode === 13) { // Enter
      $('[data-ca-mwl-rename-save]').trigger('click');
      e.preventDefault();
    }
  });

  $(_.doc).on('click', '[data-ca-mwl-rename-cancel]', function() {
    $renameDialog.ceDialog('close');
    return false;
  });

  $(_.doc).on('click', '[data-ca-mwl-delete]', function() {
    var list_id = $(this).closest('[data-ca-mwl-list-id]').data('caMwlListId');
    $deleteDialog.data('caMwlListId', list_id);
    $deleteDialog.ceDialog('open', {
      title: _.tr('mwl_xlsx.remove') || 'Remove'
    });
    return false;
  });

  $(_.doc).on('click', '[data-ca-mwl-delete-confirm]', function() {
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

  $(_.doc).on('click', '[data-ca-mwl-delete-cancel]', function() {
    $deleteDialog.ceDialog('close');
    return false;
  });

  // Resolves selected list id, creating a new list if "_new" is chosen.
  // Calls cb(list_id) on success, cb(null) if cancelled or failed.
  function resolveListId($select, cb) {
    var list_id = $select.val();
    if (list_id !== '_new') {
      localStorage.setItem('mwl_last_list', list_id);
      if (typeof cb === 'function') { cb(list_id); }
      return;
    }

    getListName(function(name) {
      if (!name) {
        if (typeof cb === 'function') { cb(null); }
        return;
      }
      $.ceAjax('request', fn_url('mwl_xlsx.create_list'), {
        method: 'post',
        data: { name: name },
        callback: function(data) {
          data = parseResponse(data);
          list_id = data.list_id;
          if (!list_id) {
            if (typeof cb === 'function') { cb(null); }
            return;
          }
          $select.prepend($('<option>', { value: list_id, text: data.name }));
          $select.val(list_id);
          localStorage.setItem('mwl_last_list', list_id);
          if (typeof cb === 'function') { cb(list_id); }
        }
      });
    });
  }

  function addProductsToList(product_ids, list_id) {
    product_ids = product_ids.slice(0, 20);
    $.ceAjax('request', fn_url('mwl_xlsx.add_list'), {
      method: 'post',
      data: { list_id: list_id, product_ids: product_ids },
      callback: function(data) {
        data = parseResponse(data);
        var message = (data && data.message) ? data.message : (_.tr('mwl_xlsx.added_plain') || 'Added to media list');
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

  function addToList(product_id, list_id) {
    $.ceAjax('request', fn_url('mwl_xlsx.add'), {
      method: 'post',
      data: { product_id: product_id, list_id: list_id },
      callback: function(data) {
        data = parseResponse(data);
        var message = (data && data.message) ? data.message : (_.tr('mwl_xlsx.added_plain') || 'Added to media list');
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

