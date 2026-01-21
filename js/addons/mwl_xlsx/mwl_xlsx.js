(function(_, $) {
  var $renameDialog = $('#mwl_xlsx_rename_dialog');
  var $deleteDialog = $('#mwl_xlsx_delete_dialog');
  var $newListDialog = $('#mwl_xlsx_new_list_dialog');
  var $addDialog = $('#mwl_xlsx_add_dialog');
  var ELEMENT_NODE = 1;
  var TEXT_NODE = 3;
  var MWL_LANG_COOKIE = 'mwl_lang_set';

  function findInContext(selector, context) {
    var $context = context ? $(context) : $(document);
    var $elements = $context.find(selector);

    if (context && $context.is && $context.is(selector)) {
      $elements = $elements.add($context);
    } else if (!context) {
      $elements = $(selector);
    }

    return $elements;
  }

  function getDocumentLocale() {
    var html = document.documentElement || {};
    if (html.lang) {
      return html.lang;
    }

    if (typeof navigator !== 'undefined') {
      return navigator.language || navigator.userLanguage || 'en';
    }

    return 'en';
  }

  function getCurrentLanguage() {
    var lang = '';

    try {
      if (_ && _.cart_language) {
        lang = _.cart_language;
      } else if (typeof Tygh !== 'undefined' && Tygh.cart_language) {
        lang = Tygh.cart_language;
      }
    } catch (e) {}

    if (!lang) {
      var html = document.documentElement || {};
      if (html.lang) {
        lang = html.lang;
      }
    }

    if (!lang && typeof navigator !== 'undefined') {
      lang = navigator.language || navigator.userLanguage || '';
    }

    return (lang || '').substr(0, 2).toLowerCase();
  }

  function formatFeatureNumbers(context) {
    var locale = getDocumentLocale();
    var $elements = findInContext('.ty-product-feature__value', context);

    $elements.each(function() {
      var $el = $(this);
      if ($el.data('mwlFormattedNumber')) { return; }

      var text = ($el.text() || '').replace(/\s+/g, '');
      if (!/^\d+$/.test(text)) { return; }

      var value = parseInt(text, 10);
      if (!value || value < 1000) { return; }

      try {
        $el.text(value.toLocaleString(locale));
      } catch (e) {
        $el.text(value.toLocaleString());
      }

      $el.data('mwlFormattedNumber', '1');
    });
  }

  function addPriceHints(context) {
    var hintText = _.tr ? _.tr('mwl_xlsx.price_hint_text') : '';
    if (!hintText) { return; }

    var $prices = findInContext('.ty-price', context);

    $prices.each(function() {
      var $price = $(this);
      if ($price.data('mwlPriceHintAdded')) { return; }
      if ($price.next('.ty-price-hint').length) {
        $price.data('mwlPriceHintAdded', true);
        return;
      }

      var $hint = $('<span/>', {
        'class': 'ty-price-hint cm-tooltip ty-icon-help-circle',
        title: hintText,
        text: '',
        css: {
          'margin-left': '10px',
          cursor: 'help'
        }
      });

      $price.after($hint);
      $price.data('mwlPriceHintAdded', true);
    });
  }

  function setLanguageFromBrowser() {
    if (!_.addons || !_.addons.mwl_xlsx || !_.addons.mwl_xlsx.auto_detect_language) { return; }

    if (document.cookie && document.cookie.indexOf(MWL_LANG_COOKIE + '=1') !== -1) { return; }
    if (window.location.search && /(^|&)sl=/.test(window.location.search.replace('?', '&'))) { return; }

    var browserLang = (typeof navigator !== 'undefined' && (navigator.language || navigator.userLanguage)) || '';
    browserLang = (browserLang || '').substr(0, 2).toLowerCase();
    if (!browserLang) { return; }

    var currentLang = getCurrentLanguage();
    if (browserLang === currentLang) { return; }

    var redirects = {
      ru: '?sl=ru',
      en: '?sl=en'
    };

    var target = redirects[browserLang];
    if (!target) { return; }

    var nextUrl = null;

    try {
      var url = new URL(window.location.href);
      url.searchParams.set('sl', browserLang);
      nextUrl = url.pathname + url.search + url.hash;
    } catch (e) {
      var path = window.location.pathname;
      var hash = window.location.hash || '';
      if (window.location.search) {
        var hasQuery = window.location.search.indexOf('?') !== -1;
        var separator = hasQuery ? '&' : '?';
        nextUrl = path + window.location.search + separator + 'sl=' + browserLang + hash;
      } else {
        nextUrl = path + target + hash;
      }
    }

    if (!nextUrl) { return; }

    document.cookie = MWL_LANG_COOKIE + '=1;path=/;max-age=' + (60 * 60 * 24 * 365);
    window.location.replace(nextUrl);
  }

  function linkifyElement(el) {
    if (!el || el.nodeType !== ELEMENT_NODE) { return; }

    var childNodes = Array.prototype.slice.call(el.childNodes || []);

    for (var i = 0; i < childNodes.length; i++) {
      var node = childNodes[i];

      if (node.nodeType === TEXT_NODE) {
        var text = node.textContent || '';
        if (text.indexOf('http') === -1) { continue; }

        var regex = /(https?:\/\/[^\s<]+)/g;
        var lastIndex = 0;
        var match;
        var frag = document.createDocumentFragment();
        var hasMatch = false;

        while ((match = regex.exec(text)) !== null) {
          hasMatch = true;

          if (match.index > lastIndex) {
            frag.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
          }

          var a = document.createElement('a');
          a.href = match[0];
          a.textContent = match[0];
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          frag.appendChild(a);

          lastIndex = match.index + match[0].length;
        }

        if (lastIndex < text.length) {
          frag.appendChild(document.createTextNode(text.slice(lastIndex)));
        }

        if (hasMatch) {
          el.replaceChild(frag, node);
        }
      } else if (node.nodeType === ELEMENT_NODE) {
        if (node.tagName && node.tagName.toLowerCase() === 'a') { continue; }
        linkifyElement(node);
      }
    }
  }

  function linkifyFeatureValues(context) {
    var $contextElements = $('.ty-product-feature__value', context);

    if (context && $(context).is && $(context).is('.ty-product-feature__value')) {
      $contextElements = $contextElements.add(context);
    }

    if (!$contextElements.length && !context) {
      $contextElements = $('.ty-product-feature__value');
    }

    $contextElements.each(function() {
      linkifyElement(this);
    });
  }

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

    // Initialize description toggle styles
    initDescriptionToggle();

    if (_ && _.addons && _.addons.mwl_xlsx) {
      if (_.addons.mwl_xlsx.linkify_feature_urls) {
        linkifyFeatureValues(context);
      }

      if (_.addons.mwl_xlsx.format_feature_numbers) {
        formatFeatureNumbers(context);
      }

      if (_.addons.mwl_xlsx.show_price_hint) {
        addPriceHints(context);
      }
    }

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
    const moveRequestPrice = false;
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

  // Also initialize on DOM ready and after AJAX updates
  $(function() {
    initDescriptionToggle();
    
    // Re-initialize after AJAX content updates (for tabs)
    if (typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(function(mutations) {
        var shouldInit = false;
        mutations.forEach(function(mutation) {
          if (mutation.addedNodes) {
            for (var i = 0; i < mutation.addedNodes.length; i++) {
              var node = mutation.addedNodes[i];
              if (node.nodeType === 1) { // Element node
                var $node = $(node);
                if ($node.is('#content_description') || $node.find('#content_description').length || 
                    $node.closest('#content_description').length) {
                  shouldInit = true;
                  break;
                }
              }
            }
          }
          // Also check if content_description content changed
          if (mutation.target && $(mutation.target).closest('#content_description').length) {
            shouldInit = true;
          }
        });
        if (shouldInit) {
          console.log('[MWL] MutationObserver detected content_description change');
          // Reset processed flag to allow re-processing
          $('#content_description').removeData('mwl-processed');
          setTimeout(initDescriptionToggle, 100);
        }
      });
      
      observer.observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true
      });
    }
    
    // Also listen for tab switching events
    $(_.doc).on('click', '.ty-tabs__item a, .tab-list-title', function() {
      console.log('[MWL] Tab clicked, will re-init description toggle');
      setTimeout(function() {
        $('#content_description').removeData('mwl-processed');
        initDescriptionToggle();
      }, 200);
    });
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

    var doc = _.doc && _.doc[0] ? _.doc[0] : document;
    var active = doc ? doc.activeElement : null;
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

  $(_.doc).on('keydown.mwl_xlsx', '[data-ca-vendor-communication="threadMessage"]', function(e) {
    var key = e.key || e.keyCode;
    if ((key !== 'Enter' && key !== 13) || !(e.ctrlKey || e.metaKey)) { return; }

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

  $(_.doc).on('click', '.mwl-planfix-create-task', function(e) {
    e.preventDefault();

    var $button = $(this);
    var orderId = $button.data('caOrderId');

    if (!orderId) {
      return;
    }

    var planfixEnabled = _.tr('mwl_xlsx.planfix_enabled') === 'Y';
    var planfixUrl = _.tr('mwl_xlsx.planfix_create_task_url');

    if (!planfixEnabled || !planfixUrl) {
      return;
    }
    var url = planfixUrl;

    $button.prop('disabled', true);

    $.ceAjax('request', url, {
      method: 'post',
      data: {
        order_id: orderId
      },
      callback: function(response) {
        $button.prop('disabled', false);

        if (!response) {
          return;
        }

        var message = response.message || _.tr('error') || 'Error';
        var responseBody = response.mcp_response && response.mcp_response.body ? String(response.mcp_response.body) : '';

        if (responseBody) {
          message += '\n' + responseBody;
        }

        if (response.success) {
          var link = response.link || {};
          var $row = $button.closest('tr');
          var $linkCell = $row.find('.mwl-planfix-link-cell');

          if ($linkCell.length && link.planfix_object_id) {
            if (link.planfix_url) {
              $linkCell.html(`<a href="${link.planfix_url}" target="_blank" rel="noopener noreferrer">${link.planfix_object_id}</a>`);
            } else {
              $linkCell.text(link.planfix_object_id);
            }
          }

          $button.remove();
          $.ceNotification('show', {
            type: 'N',
            title: _.tr('notice') || 'Notice',
            message: message
          });
        } else {
          $.ceNotification('show', {
            type: 'E',
            title: _.tr('error') || 'Error',
            message: message
          });
        }
      }
    });
  });

  $(_.doc).on('click', '.mwl-create-thread-link', function(e) {
    e.preventDefault();

    var $link = $(this);
    var orderId = $link.data('caOrderId');
    var companyId = $link.data('caCompanyId');

    if (!orderId || !companyId) {
      return;
    }

    // Disable the link to prevent multiple clicks
    $link.css('pointer-events', 'none');

    var dialogId = 'mwl_new_thread_dialog_' + orderId;
    var $dialog = $('#' + dialogId);

    if (!$dialog.length) {
      $dialog = $('<div/>', {
        id: dialogId,
        class: 'hidden'
      }).appendTo('body');
    }

    var title = _.tr('mwl_xlsx.thread_dialog_title')
      || _.tr('vendor_communication.contact_vendor')
      || 'Contact vendor';

    var returnUrl = typeof _.current_url !== 'undefined' ? _.current_url : '';
    var requestUrl = fn_url(
      'vendor_communication.create_thread?' +
      $.param({
        object_type: 'O',
        object_id: orderId,
        communication_type: 'vendor_to_customer',
        company_id: companyId,
        return_url: returnUrl
      })
    );

    $dialog.ceDialog('open', {
      href: requestUrl,
      title: title,
      height: 'auto',
      width: '700px',
      destroyOnClose: true
    });

    setTimeout(function() {
      $link.css('pointer-events', '');
    }, 300);
  });

  // Description expand/collapse functionality
  function initDescriptionToggle() {
    console.log('[MWL] initDescriptionToggle called');

    // Process #content_description element
    var $contentDesc = $('#content_description');
    console.log('[MWL] #content_description found:', $contentDesc.length, 'processed:', $contentDesc.data('mwl-processed'));
    
    if ($contentDesc.length && !$contentDesc.data('mwl-processed')) {
      // Add card class - element should be expanded by default in template
      if (!$contentDesc.hasClass('mwl-description-card')) {
        $contentDesc.addClass('mwl-description-card');
      }
      
      // Try to find the description div - it might be the last div or a direct child
      var $descDiv = $contentDesc.find('> div:last-child');
      if (!$descDiv.length) {
        $descDiv = $contentDesc.children('div').last();
      }
      if (!$descDiv.length) {
        // If no div found, use the content_description itself
        $descDiv = $contentDesc;
      }
      
      console.log('[MWL] Description div found:', $descDiv.length, 'HTML length:', $descDiv.html() ? $descDiv.html().length : 0);
      
      if ($descDiv.length) {
        var descTextPlain = $descDiv.text() || '';
        // Remove extra whitespace and newlines for length calculation
        descTextPlain = descTextPlain.replace(/\s+/g, ' ').trim();
        
        console.log('[MWL] Description text length:', descTextPlain.length, 'First 100 chars:', descTextPlain.substring(0, 100));
        
        // Check if description is long enough to truncate (more than 300 chars)
        if (descTextPlain.length > 300) {
          console.log('[MWL] Description is long enough, adding collapse functionality');
          
          // Wrap the description div in a container for better control
          if (!$descDiv.hasClass('mwl-description-text-wrapper')) {
            $descDiv.wrap('<div class="mwl-description-text-wrapper"></div>');
          }
          var $wrapper = $descDiv.parent('.mwl-description-text-wrapper');
          
          // Add fade overlay and toggle button (they should be in template, but add if missing)
          if (!$contentDesc.find('.mwl-description-fade').length) {
            console.log('[MWL] Adding fade overlay');
            $contentDesc.append('<div class="mwl-description-fade"></div>');
          }
          
          // Add toggle button (should be in template, but add if missing)
          if (!$contentDesc.find('.mwl-description-toggle').length) {
            console.log('[MWL] Adding toggle button');
            $contentDesc.append(
              '<a href="#" class="mwl-description-toggle" data-ca-mwl-description-toggle onclick="return false;"></a>'
            );
          }
          
          // Calculate line height and number of visible lines using Range API for accurate line count
          var computedStyle = window.getComputedStyle($descDiv[0]);
          var maxVisibleLines = 6; // Show 6 lines when collapsed
          
          // Function to count lines using Range API
          function countLines(element) {
            var text = element.innerText || element.textContent || '';
            if (!text.trim()) return 0;
            
            var styles = window.getComputedStyle(element);
            var lineHeight = parseFloat(styles.lineHeight);
            if (isNaN(lineHeight) || styles.lineHeight === 'normal') {
              var fontSize = parseFloat(styles.fontSize) || 14;
              lineHeight = fontSize * 1.5;
            }
            
            // Create a temporary clone to measure
            var $clone = $(element).clone()
              .css({
                position: 'absolute',
                visibility: 'hidden',
                width: $(element).width() + 'px',
                height: 'auto',
                maxHeight: 'none',
                overflow: 'visible',
                whiteSpace: 'normal',
                wordWrap: 'break-word'
              })
              .appendTo('body');
            
            var cloneEl = $clone[0];
            var range = document.createRange();
            range.selectNodeContents(cloneEl);
            
            var rects = range.getClientRects();
            var lines = rects.length;
            
            // Fallback: use height calculation if Range API doesn't work well
            if (lines === 0 || lines === 1) {
              var height = cloneEl.scrollHeight;
              var paddingTop = parseFloat(styles.paddingTop) || 0;
              var paddingBottom = parseFloat(styles.paddingBottom) || 0;
              var contentHeight = height - paddingTop - paddingBottom;
              lines = Math.round(contentHeight / lineHeight);
            }
            
            $clone.remove();
            return lines;
          }
          
          // Count total lines
          var fullLines = countLines($descDiv[0]);
          var hiddenLines = Math.max(0, fullLines - maxVisibleLines);
          
          console.log('[MWL] Full lines:', fullLines, 'Max visible:', maxVisibleLines, 'Hidden lines:', hiddenLines);
          
          // Only apply collapse if description has more lines than maxVisibleLines
          if (fullLines > maxVisibleLines + 2) {
            // Store hidden lines count in data attribute
            $contentDesc.data('mwl-hidden-lines', hiddenLines);
            $contentDesc.data('mwl-max-visible-lines', maxVisibleLines);
            
            // Update toggle button text
            var $toggleBtn = $contentDesc.find('.mwl-description-toggle');
            if ($toggleBtn.length) {
              var buttonText = '';
              if (hiddenLines > 0) {
                buttonText = hiddenLines.toString();
              }
              // $toggleBtn.text(buttonText);
            }
            
            // Collapse by default if description is long (element starts expanded in template)
            $contentDesc.addClass('mwl-description-collapsed');
          } else {
            // Description is too short, remove collapse functionality
            console.log('[MWL] Description is too short (' + fullLines + ' lines), removing collapse functionality');
            $contentDesc.removeClass('mwl-description-collapsed');
            $contentDesc.find('.mwl-description-fade').remove();
            $contentDesc.find('.mwl-description-toggle').remove();
          }
        } else {
          console.log('[MWL] Description is too short, skipping');
        }
        
        $contentDesc.data('mwl-processed', true);
        console.log('[MWL] Marked as processed');
      } else {
        console.log('[MWL] No description div found');
      }
    } else {
      if (!$contentDesc.length) {
        console.log('[MWL] #content_description element not found on page');
      }
    }

    // Initialize wrapper state for template-based descriptions
    $('.mwl-description-wrapper').each(function() {
      var $wrapper = $(this);
      var $content = $wrapper.find('.mwl-description-content');
      if ($content.hasClass('mwl-description-collapsed')) {
        $wrapper.addClass('mwl-description-collapsed');
      }
    });
  }

  $(_.doc).on('click', '[data-ca-mwl-description-toggle]', function(e) {
    e.preventDefault();
    console.log('[MWL] Toggle button clicked');
    var $btn = $(this);
    var $contentDesc = $('#content_description');
    var $wrapper = $btn.closest('.mwl-description-wrapper');
    
    // Handle #content_description (tabs-based description)
    if ($contentDesc.length && $contentDesc.has($btn).length) {
      console.log('[MWL] Toggling #content_description, current state:', $contentDesc.hasClass('mwl-description-collapsed') ? 'collapsed' : 'expanded');
      if ($contentDesc.hasClass('mwl-description-collapsed')) {
        // Expand
        $contentDesc.removeClass('mwl-description-collapsed');
        // Remove number when expanded
        $btn.text('');
        // console.log('[MWL] Expanded description');
      } else {
        // Collapse
        $contentDesc.addClass('mwl-description-collapsed');
        var hiddenLines = $contentDesc.data('mwl-hidden-lines') || 0;
        var buttonText = '';
        if (hiddenLines > 0) {
          buttonText = hiddenLines.toString();
        }
        // $btn.text(buttonText);
        // console.log('[MWL] Collapsed description');
      }
      return;
    }
    
    // Handle template-based description wrapper
    if ($wrapper.length) {
      console.log('[MWL] Toggling template-based wrapper');
      var $content = $wrapper.find('.mwl-description-content');
      var $fade = $wrapper.find('.mwl-description-fade');

      if ($wrapper.hasClass('mwl-description-collapsed')) {
        // Expand
        $wrapper.removeClass('mwl-description-collapsed');
        $content.removeClass('mwl-description-collapsed');
        $btn.text(_.tr('mwl_xlsx.show_less') || 'Скрыть');
      } else {
        // Collapse
        $wrapper.addClass('mwl-description-collapsed');
        $content.addClass('mwl-description-collapsed');
        $btn.text(_.tr('mwl_xlsx.show_all') || 'Показать все');
      }
    }
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
    var limit = parseInt(_.tr('mwl_xlsx.max_list_items') || 0, 10);
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

  $(function() {
    setLanguageFromBrowser();

    // Initialize description toggle styles
    initDescriptionToggle();

    if (_ && _.addons && _.addons.mwl_xlsx) {
      if (_.addons.mwl_xlsx.format_feature_numbers) {
        formatFeatureNumbers();
      }

      if (_.addons.mwl_xlsx.show_price_hint) {
        addPriceHints();
      }
    }
    
    // Re-initialize description toggle after a delay (for dynamically loaded content)
    setTimeout(initDescriptionToggle, 500);
  });

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
