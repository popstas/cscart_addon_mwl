(function(_, $) {
  var $renameDialog = $('#mwl_xlsx_rename_dialog');
  var $deleteDialog = $('#mwl_xlsx_delete_dialog');
  var $newListDialog = $('#mwl_xlsx_new_list_dialog');
  var $addDialog = $('#mwl_xlsx_add_dialog');

  // === MWL: минимальное отслеживание целей Метрики ===
  function mwlGetMetrikaId() {
    if (window.MWL_METRIKA_ID) return parseInt(window.MWL_METRIKA_ID, 10);
    try {
      if (window.Ya && window.Ya._metrika && typeof window.Ya._metrika.counters === 'function') {
        var cs = window.Ya._metrika.counters();
        if (cs && cs[0] && cs[0].id) return cs[0].id;
      }
    } catch (e) {}
    for (var k in window) { if (/^yaCounter\d+$/.test(k)) return parseInt(k.replace('yaCounter',''), 10); }
    return null;
  }
  function mwlReach(goal, el) {
    var id = mwlGetMetrikaId();
    if (!goal || !id) return;
    var params = {};
    try {
      var ds = (el && el.dataset) ? el.dataset : {};
      for (var key in ds) { if (/^mwl/i.test(key)) params[key] = ds[key]; }
      var $el = $(el);
      var href = $el.attr('href'); if (href) params.href = href;
    } catch (e) {}
    if (typeof window.ym === 'function') { try { window.ym(id, 'reachGoal', goal, params); } catch (e) {} }
    else if (window['yaCounter' + id] && typeof window['yaCounter' + id].reachGoal === 'function') { try { window['yaCounter' + id].reachGoal(goal, params); } catch (e) {} }
  }

  // Один раз прокинем user_id в Метрику, если есть
  function setMwlUserId() {
    var id = mwlGetMetrikaId();
    if (!id || typeof window.ym !== 'function') { return; }

    var uid = window.MWL_USER_ID
      || (typeof _ !== 'undefined' && _.auth && _.auth.user_id)
      || null;

    if (!uid) {
      var el = document.body || document.documentElement;
      if (el && el.getAttribute) {
        uid = el.getAttribute('data-mwl-user-id');
      }
    }

    if (uid && String(uid) !== '0') {
      try { window.ym(id, 'userParams', { user_id: String(uid) }); } catch (e) {}
    }
  }

  $(_.doc).on('click', '.mwl_xlsx-export, .mwl_google-export', function() {
    mwlReach($(this).is('.mwl_google-export') ? 'MWL_GSHEETS_EXPORT' : 'MWL_XLSX_DOWNLOAD', this);
  });
  // === /MWL ===

  $.ceEvent('on', 'ce.commoninit', function(context) {
    // init user id in Metrika
    setMwlUserId();

    // init new list dialog
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
  
    // init add-to-list dialog
    if (!$addDialog.length) {
      $('body').append(
        '<div id="mwl_xlsx_add_dialog" class="hidden">' +
        '<div class="ty-control-group">' +
        '<label for="mwl_xlsx_add_select" class="ty-control-group__title">' + (_.tr('mwl_xlsx.select_list') || 'Select media list') + '</label>' +
        '<select id="mwl_xlsx_add_select" class="ty-input-text mwl_xlsx-select" data-ca-list-select-xlsx></select>' +
        '</div>' +
        '<div class="ty-control-group">' +
        '<a href="/media-lists/" target="_blank" class="">' + (_.tr('mwl_xlsx.my_lists') || 'My media lists') + '</a>' +
        ' · ' +
        '<a href="#" target="_blank" class="hidden" id="mwl_xlsx_go_to_list_link">' + (_.tr('mwl_xlsx.go_to_list') || 'Go to media list') + '</a>' +
        '</div>' +
        '<div class="buttons-container">' +
        '<button class="ty-btn ty-btn__primary" data-ca-mwl-add-dialog-confirm>' + (_.tr('add') || 'Add') + '</button>' +
        '<button class="ty-btn" data-ca-mwl-add-dialog-cancel>' + (_.tr('cancel') || 'Cancel') + '</button>' +
        '</div>' +
        '</div>'
      );
      $addDialog = $('#mwl_xlsx_add_dialog');
    }
  
  
    // set last list id
    var last_list_id = localStorage.getItem('mwl_last_list');
    $('[data-ca-list-select-xlsx]', context).each(function() {
      var $select = $(this);
      if (last_list_id && $select.find('option[value="' + last_list_id + '"]').length) {
        $select.val(last_list_id).trigger('change');
      }
    });

    // Move request-price-check button into the feature row value if present on product page
    const moveRequestPrice = true;
    if (moveRequestPrice) {
      try {
        var $opener = $('#opener_ut2_features_dialog_552', context);
        if (!$opener.length) { $opener = $('#opener_ut2_features_dialog_552'); }
        var $btn = $('.request-price-check-btn', context);
        if (!$btn.length) { $btn = $('.request-price-check-btn'); }
        if ($opener.length && $btn.length) {
          var $feature = $opener.first().closest('.ty-product-feature');
          if ($feature.length) {
            var $value = $feature.find('.ty-product-feature__value').first();
            if ($value.length) {
              $value.append($btn.first());
            } else {
              $btn.first().insertAfter($opener.first());
            }
          } else {
            $btn.first().insertAfter($opener.first());
          }
        }
      } catch (e) {}
    }
  });

  $(_.doc).on('change', '[data-ca-list-select-xlsx]', function() {
    var value = $(this).val();
    if (value !== '_new') {
      changeList(value);
    }
  });

  // Track last hovered product block's Add button on product list pages (cached, throttled)
  var $currentProductAddBtn = null;
  var mwlHoverTs = 0;
  $(_.doc).on('mouseenter', '.ty-product-list', function() {
    var now = Date.now();
    if (now - mwlHoverTs < 50) { return; }
    mwlHoverTs = now;

    var $container = $(this);
    var $btn = $container.data('mwlAddBtn');
    if (!$btn || !$btn.length || !$.contains(_.doc, $btn[0])) {
      $btn = $container.find('[data-ca-add-to-mwl_xlsx]').filter(':visible').first();
      $container.data('mwlAddBtn', $btn);
    }
    if ($btn && $btn.length && $btn.is(':visible')) {
      $currentProductAddBtn = $btn;
    }
  });

  $(_.doc).on('click', '[data-ca-add-to-mwl_xlsx]', function() {
    var product_id = $(this).data('caProductId');
    populateAddDialogOptions();
    $addDialog.data('caMwlProductId', product_id);
    $addDialog.ceDialog('open', {
      title: _.tr('mwl_xlsx.add_to_wishlist') || 'Add to media list'
    });
    setTimeout(function() { $('#mwl_xlsx_add_select').focus(); }, 0);
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

  // Global hotkey: press "a" to open Add-to-media-list dialog
  // Prefers last clicked product in lists; otherwise if exactly one target is visible
  $(_.doc).on('keydown.mwl_xlsx', function(e) {
    var key = e.key || e.keyCode;
    var isA = key === 'a' || key === 'A' || key === 65 || key === 'ф' || key === 'Ф';
    if (!isA) { return; }
    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || e.repeat || e.isComposing) { return; }

    // If Add dialog is already open, do nothing
    if ($addDialog && $addDialog.length && $addDialog.is(':visible')) { return; }

    var active = _.doc.activeElement;
    if (active) {
      var $active = $(active);
      if (
        $active.is('input, textarea, select') ||
        $active.is('[contenteditable], [contenteditable="true"]') ||
        active.isContentEditable
      ) {
        return;
      }
    }

    // If we have a stored product button from product lists, use it
    if ($currentProductAddBtn && $currentProductAddBtn.length && $.contains(_.doc, $currentProductAddBtn[0]) && $currentProductAddBtn.is(':visible')) {
      $currentProductAddBtn.trigger('click');
      e.preventDefault();
      return;
    }

    var $targets = $('[data-ca-add-to-mwl_xlsx]:visible');
    if ($targets.length === 1) {
      $targets.first().trigger('click');
      e.preventDefault();
    }
  });

  $(_.doc).on('keydown', '.ty-vendor-communication-new-message__input', function(e) {
    var key = e.key || e.keyCode;
    var isEnter = key === 'Enter' || key === 13;
    if (!isEnter || !(e.ctrlKey || e.metaKey)) { return; }

    var $form = $(this).closest('form');
    if ($form.length) {
      e.preventDefault();
      $form.trigger('submit');
    }
  });

  $(_.doc).on('click', '[data-ca-remove-from-mwl_xlsx]', function() {
    var $btn = $(this);
    var list_id = $btn.data('caListId');
    var product_id = $btn.data('caProductId');
    $.ceAjax('request', fn_url('mwl_xlsx.remove'), {
      method: 'post',
      data: { list_id: list_id, product_id: product_id },
      callback: function(data) {
        data = parseResponse(data);
        if (data && data.success) {
          $btn.text(_.tr('mwl_xlsx.removed') || 'Removed').prop('disabled', true);
        }
      }
    });
    return false;
  });

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

  function getListName(defaultName, callback) {
    // Support old signature: getListName(callback)
    if (typeof defaultName === 'function' && !callback) {
      callback = defaultName;
      defaultName = '';
    }
    var name = (defaultName || '').toString();
    var $input = $('#mwl_xlsx_new_list_input');
    $input.val(name);
    // Select text so user can overwrite quickly
    try { $input[0].setSelectionRange(0, name.length); } catch (e) {}
    $newListDialog.data('caMwlOnOk', callback);
    $newListDialog.ceDialog('open', {
      title: _.tr('mwl_xlsx.new_list') || 'New media list'
    });
    setTimeout(function() { $input.focus(); }, 0);
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

  // Add dialog controls
  $(_.doc).on('click', '[data-ca-mwl-add-dialog-cancel]', function() {
    $addDialog.ceDialog('close');
    return false;
  });

  $(_.doc).on('keydown', '#mwl_xlsx_add_select', function(e) {
    if (e.keyCode === 13) { // Enter
      $('[data-ca-mwl-add-dialog-confirm]').trigger('click');
      e.preventDefault();
    }
  });

  // In Add-dialog: press "a" to confirm
  $(_.doc).on('keydown', '#mwl_xlsx_add_dialog', function(e) {
    var key = e.key || e.keyCode;
    var isA = key === 'a' || key === 'A' || key === 65 || key === 'ф' || key === 'Ф';
    if (!isA) { return; }
    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || e.repeat || e.isComposing) { return; }
    $addDialog.find('[data-ca-mwl-add-dialog-confirm]').trigger('click');
    // $addDialog.ceDiaalog('close');
    e.preventDefault();
  });

  $(_.doc).on('click', '[data-ca-mwl-add-dialog-confirm]', function() {
    var product_id = $addDialog.data('caMwlProductId');
    var $select = $addDialog.find('[data-ca-list-select-xlsx]');
    resolveListId($select, function(list_id) {
      if (!list_id) { return; }
      addToList(product_id, list_id);
    });
    $addDialog.ceDialog('close');

    return false;
  });

  function populateAddDialogOptions() {
    var $dlgSelect = $addDialog.find('[data-ca-list-select-xlsx]');
    $dlgSelect.empty();
    // Try to take options from any existing select on the page (excluding dialog itself)
    var $source = $('[data-ca-list-select-xlsx]').not($dlgSelect).first();
    if ($source.length && $source.find('option').length) {
      $source.find('option').each(function() {
        var val = this.value;
        var txt = $(this).text();
        $dlgSelect.append($('<option/>', { value: val, text: txt }));
      });
    } else {
      // Fallback: only allow creating a new list
      var newLabel = '+ ' + (_.tr('mwl_xlsx.new_list') || 'New media list');
      $dlgSelect.append($('<option/>', { value: '_new', text: newLabel }));
    }

    var last_list_id = localStorage.getItem('mwl_last_list');
    if (last_list_id && $dlgSelect.find('option[value="' + last_list_id + '"]').length) {
      $dlgSelect.val(last_list_id);
    }
  }

  // Resolves selected list id, creating a new list if "_new" is chosen.
  // Calls cb(list_id) on success, cb(null) if cancelled or failed.
  function resolveListId($select, cb) {
    var list_id = $select.val();
    if (list_id !== '_new') {
      changeList(list_id);
      if (typeof cb === 'function') { cb(list_id); }
      return;
    }

    // If there are no existing lists (except the special "_new"), prefill default name
    var existingCount = $select.find('option').filter(function() { return this.value !== '_new'; }).length;
    var defaultName = existingCount ? '' : (_.tr('mwl_xlsx.default_list_name') || 'Media list');

    getListName(defaultName, function(name) {
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

          changeList(list_id, data.name);

          var $counter = $('#mwl_media_lists_count');
          if ($counter.length) {
            var $count = $counter.find('.count');
            if ($count.length) {
              var n = parseInt($count.text(), 10) || 0;
              $count.text(n + 1);
            } else {
              $counter.find('span').append('<span class="count">1</span>');
              $counter.find('a.ty-wishlist__a').addClass('active');
            }
          }

          if (typeof cb === 'function') { cb(list_id); }
        }
      });
    });
  }

  function changeList(list_id, list_name) {
    // If list_name provided, ensure option exists and select it; otherwise just select existing option
    if (list_name) {
      var optionHtml = '<option value="' + list_id + '">' + list_name + '</option>';
      $('[data-ca-list-select-xlsx]').each(function() {
        var $sel = $(this);
        var $opt = $sel.find('option[value="' + list_id + '"]');
        if ($opt.length) {
          $opt.text(list_name);
        } else {
          $sel.prepend(optionHtml);
        }
        $sel.val(list_id);
      });
    } else {
      $('[data-ca-list-select-xlsx]').each(function() {
        $(this).val(list_id);
      });
    }
    localStorage.setItem('mwl_last_list', list_id);

    try {
      var $link = $('#mwl_xlsx_go_to_list_link');
      if ($link.length) {
        if (list_id && list_id !== '_new') {
          var goLabel = _.tr('mwl_xlsx.go_to_list') || 'Go to';
          var displayName = list_name || (function() {
            var $opt = $('[data-ca-list-select-xlsx] option[value="' + list_id + '"]').first();
            var t = $opt.length ? $opt.text() : '';
            return t || (_.tr('mwl_xlsx.default_list_name') || 'Media list');
          })();
          $link.attr('href', '/media-lists/' + list_id)
            .text(goLabel + ' ' + displayName)
            .removeClass('hidden');
        } else {
          $link.addClass('hidden');
        }
      }
    } catch (e) {}
  }

  function addProductsToList(product_ids, list_id) {
    var limit = _.addons && _.addons.mwl_xlsx && parseInt(_.addons.mwl_xlsx.max_list_items, 10);
    if (limit > 0) {
      product_ids = product_ids.slice(0, limit);
    }
    $.ceAjax('request', fn_url('mwl_xlsx.add_list'), {
      method: 'post',
      data: { list_id: list_id, product_ids: product_ids },
      callback: function(data) {
        data = parseResponse(data);
        var message = (data && data.message) ? data.message : (_.tr('mwl_xlsx.added_plain') || 'Added to media list');
        if (!data || !data.message) {
          var goLabel = _.tr('mwl_xlsx.go_to_list') || 'Go to';
          var listName = (function() {
            var $opt = $('[data-ca-list-select-xlsx] option[value="' + list_id + '"]').first();
            var t = $opt.length ? $opt.text() : '';
            return t || (_.tr('mwl_xlsx.default_list_name') || 'Media list');
          })();
          var safeName = listName.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;');
          message += ' <a target="_blank" href="/media-lists/' + list_id + '">' + goLabel + ' ' + safeName + '</a>';
        }
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
        if (!data || !data.message) {
          var goLabel = _.tr('mwl_xlsx.go_to_list') || 'Go to';
          var listName = (function() {
            var $opt = $('[data-ca-list-select-xlsx] option[value="' + list_id + '"]').first();
            var t = $opt.length ? $opt.text() : '';
            return t || (_.tr('mwl_xlsx.default_list_name') || 'Media list');
          })();
          var safeName = listName.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;');
          message += ' <a target="_blank" href="/media-lists/' + list_id + '">' + goLabel + ' ' + safeName + '</a>';
        }
        $.ceNotification('show', {
          type: 'N',
          title: '',
          message: message,
          message_state: 'I',
          overlay: true
        });

        // Update last hovered product list button text to "Added"
        if ($currentProductAddBtn && $currentProductAddBtn.length && $.contains(_.doc, $currentProductAddBtn[0])) {
          var addedShort = _.tr('mwl_xlsx.added_short') || 'Added';
          $currentProductAddBtn.text(addedShort);
        }
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
