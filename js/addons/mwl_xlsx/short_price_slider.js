(function(_, $) {
    'use strict';

    /**
     * Компактное отображение чисел для ценового слайдера
     * 275435920 -> "275 млн." (ru) / "275 M" (en)
     */
    function shortNum(n) {
        if (!Tygh.addons.mwl_xlsx.compact_price_slider_labels) {
            return n;
        }
        
        n = parseFloat(n) || 0;
        var t = _.tr('mwl_xlsx.shortnum_trillion') || " T";
        var b = _.tr('mwl_xlsx.shortnum_billion') || " B";
        var m = _.tr('mwl_xlsx.shortnum_million') || " M";
        var k = _.tr('mwl_xlsx.shortnum_thousand') || " K";

        var result;
        if (Math.abs(n) >= 1e12) {
            result = Math.floor(n / 1e12) + t;
        } else if (Math.abs(n) >= 1e9) {
            result = Math.floor(n / 1e9) + b;
        } else if (Math.abs(n) >= 1e6) {
            result = Math.floor(n / 1e6) + m;
        } else if (Math.abs(n) >= 1e3) {
            result = Math.floor(n / 1e3) + k;
        } else {
            result = Math.floor(n).toString();
        }
        
        return result;
    }

    /**
     * Форматирование числа с разделителями тысяч
     * 123000000 -> "123 000 000"
     */
    function formatNumber(n) {
        n = parseFloat(n) || 0;
        var result = Math.floor(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return result;
    }

    /**
     * Форматирует инпуты ценового слайдера
     */
    function formatSliderInputs() {
        $('.ty-price-slider__input-text').each(function() {
            var $input = $(this);
            var value = $input.val();
            
            if (value && !isNaN(value)) {
                var formattedValue = formatNumber(value);
                $input.val(formattedValue);
            }
        });
    }

    /**
     * Обновляет метки ценового слайдера компактными значениями
     */
    function updateSliderLabels() {
        if (!Tygh.addons.mwl_xlsx.compact_price_slider_labels) {
            return;
        }
        
        var $sliders = $('.ty-range-slider__wrapper');
        
        $sliders.each(function() {
            var $wrapper = $(this);
            var $items = $wrapper.find('.ty-range-slider__item');
            
            if ($items.length >= 2) {
                // Обновляем только крайние метки (первую и последнюю)
                var $first = $items.first();
                var $last = $items.last();
                
                // Находим элементы с числовыми значениями внутри bdi
                var $firstNumSpan = $first.find('.ty-range-slider__num span span');
                var $lastNumSpan = $last.find('.ty-range-slider__num span span');
                
                if ($firstNumSpan.length && $lastNumSpan.length) {
                    // Получаем значения из input полей по data-ca-previous-value
                    var $sliderContainer = $wrapper.closest('.cm-product-filters-checkbox-container');
                    var $leftInput = $sliderContainer.find('input[id$="_left"]');
                    var $rightInput = $sliderContainer.find('input[id$="_right"]');
                    
                    var firstNum = parseFloat($leftInput.attr('data-ca-previous-value')) || 0;
                    var lastNum = parseFloat($rightInput.attr('data-ca-previous-value')) || 0;
                    
                    // Получаем префикс и суффикс из input контейнеров
                    var firstPrefix = $leftInput.siblings('.ty-price-slider__filter-prefix').text() || '';
                    var firstSuffix = $leftInput.siblings('.ty-price-slider__filter-suffix').text() || '';
                    var lastPrefix = $rightInput.siblings('.ty-price-slider__filter-prefix').text() || '';
                    var lastSuffix = $rightInput.siblings('.ty-price-slider__filter-suffix').text() || '';
                    
                    // Обновляем только числовую часть, сохраняя префикс и суффикс
                    var firstShortNum = shortNum(firstNum);
                    var lastShortNum = shortNum(lastNum);
                    
                    // Обновляем содержимое span с числом
                    $firstNumSpan.text(firstShortNum);
                    $lastNumSpan.text(lastShortNum);
                }
            }
        });
    }

    // Инициализация при загрузке страницы
    $(document).ready(function() {
        // console.log('[MWL] Document ready, compact_labels:', Tygh.addons.mwl_xlsx.compact_price_slider_labels);
        
        // Вызываем initSlider для инициализации всех слайдеров
        setTimeout(function() {
            window.initSlider(document);
        }, 100);
        
        if (Tygh.addons.mwl_xlsx.compact_price_slider_labels) {
            setTimeout(function() {
                updateSliderLabels();
            }, 200);
        }
        
        formatSliderInputs();
    });

    // Обновление при изменении фильтров через AJAX
    $.ceEvent('on', 'ce.ajaxload', function() {
        if (Tygh.addons.mwl_xlsx.compact_price_slider_labels) {
            setTimeout(updateSliderLabels, 100);
        }
        setTimeout(formatSliderInputs, 100);
    });

    // Обработчик для форматирования при изменении инпутов (делегирование событий)
    $(document).on('input blur keyup', '.ty-price-slider__input-text', function() {
        var $input = $(this);
        var value = $input.val();
        
        if (value && !isNaN(value)) {
            var formattedValue = formatNumber(value);
            $input.val(formattedValue);
        }
    });

    // Обработчик для форматирования при изменении значений в инпутах слайдера
    $(document).on('change input', 'input[id$="_left"], input[id$="_right"]', function() {
        var $input = $(this);
        var value = $input.val();
        
        if (value && !isNaN(value)) {
            var formattedValue = formatNumber(value);
            $input.val(formattedValue);
        }
    });

    // Убираем перехват $.fn.val, так как теперь форматирование встроено в initSlider

    // Перехватываем события инициализации для обновления меток
    $.ceEvent('on', 'ce.commoninit', function(context) {
        // Вызываем нашу функцию initSlider для инициализации слайдеров
        setTimeout(function() {
            window.initSlider(context);
        }, 100);
        
        // Обновляем метки слайдеров если включена настройка
        if (Tygh.addons.mwl_xlsx.compact_price_slider_labels) {
            setTimeout(function() {
                updateSliderLabels();
            }, 150);
        }
    });

    // Полностью заменяем оригинальную функцию initSlider
    window.initSlider = function(parent) {
        $(parent).find('.cm-range-slider').each(function () {
            var $el = $(this);
            var id = $el.prop('id');
            var json_data = $('#' + id + '_json').val();
            
            if ($el.data('uiSlider') || !json_data) {
                return false;
            }
            
            var data = $.parseJSON(json_data) || null;
            if (!data) {
                return false;
            }
            
            console.log('[MWL] initSlider: initializing', id, 'values:', data.left, '-', data.right);
            
            $el.slider({
                disabled: data.disabled,
                range: true,
                min: data.min,
                max: data.max,
                step: data.step,
                values: [data.left, data.right],
                slide: function (event, ui) {
                    // Форматируем значения перед установкой
                    var formattedLeft = formatNumber(ui.values[0]);
                    var formattedRight = formatNumber(ui.values[1]);
                    
                    $('#' + id + '_left').val(formattedLeft);
                    $('#' + id + '_right').val(formattedRight);
                    
                    // Обновляем метки слайдера в реальном времени
                    if (Tygh.addons.mwl_xlsx.compact_price_slider_labels) {
                        updateSliderLabels();
                    }
                },
                change: function (event, ui) {
                    // Вызываем abortRequest если она существует
                    if (typeof abortRequest === 'function') {
                        abortRequest();
                    }
                    
                    var statusBoxDelay = 1000; // REQUEST_DELAY
                    var loadDelay = 3000; // REQUEST_DELAY * 3

                    // If the slider is dragged, remove the delay.
                    if (event.handleObj) {
                        loadDelay = statusBoxDelay = 333; // REQUEST_DELAY / 3
                    }
                    
                    var timerStatusBox = setTimeout(function () {
                        if (typeof $.toggleStatusBox === 'function') {
                            $.toggleStatusBox('show', {
                                show_overlay: false
                            });
                        }
                    }, statusBoxDelay);
                    
                    var replacement = ui.values[0] + '-' + ui.values[1];
                    if (data.extra) {
                        replacement = replacement + '-' + data.extra;
                    }
                    
                    var $checkbox = $('#elm_checkbox_' + id);
                    var timer = setTimeout(function () {
                        $checkbox.data('prevVal', $checkbox.val());
                        $checkbox.val(replacement).prop('checked', true).trigger('change');
                    }, loadDelay);
                },
                start: function (event, ui) {
                    if (typeof abortRequest === 'function') {
                        abortRequest();
                    }
                }
            });
            
            // Проверяем, нужно ли активировать чекбокс
            if (data.left != data.min || data.right != data.max) {
                var replacement = data.left + '-' + data.right;
                if (data.extra) {
                    replacement = replacement + '-' + data.extra;
                }
                $('#elm_checkbox_' + id).val(replacement).prop('checked', true);
            }
            
            // Обработчики для инпутов
            $('#' + id + '_left, #' + id + '_right').off('change input focus').on('change input focus', function () {
                var $inputsContainer = $(this).closest('.cm-product-filters-checkbox-container');
                var $inputLeft = $inputsContainer.find('#' + id + '_left');
                var $inputRight = $inputsContainer.find('#' + id + '_right');
                var inputLeftValue = parseFloat($inputLeft.val()) || 0;
                var inputRightValue = parseFloat($inputRight.val()) || 0;
                
                if (inputLeftValue === $inputLeft.data('caPreviousValue') && inputRightValue === $inputRight.data('caPreviousValue')) {
                    if (typeof abortRequest === 'function') {
                        abortRequest();
                    }
                    return;
                }
                
                $inputLeft.data('previousValue', inputLeftValue);
                $inputRight.data('previousValue', inputRightValue);
                
                // Форматируем значения перед установкой в слайдер
                var formattedLeft = formatNumber(inputLeftValue);
                var formattedRight = formatNumber(inputRightValue);
                
                $el.slider('values', [inputLeftValue, inputRightValue]);
            });
            
            // Форматируем начальные значения в инпутах
            var $leftInput = $('#' + id + '_left');
            var $rightInput = $('#' + id + '_right');
            
            if ($leftInput.length && $leftInput.val()) {
                var formattedLeft = formatNumber($leftInput.val());
                $leftInput.val(formattedLeft);
            }
            if ($rightInput.length && $rightInput.val()) {
                var formattedRight = formatNumber($rightInput.val());
                $rightInput.val(formattedRight);
            }
            
            // Показываем слайдер если нужно
            if ($el.parents('.filter-wrap').hasClass('open')) {
                $el.parent('.price-slider').show();
            }
        });
        
        // Обновляем метки слайдеров после инициализации
        setTimeout(function() {
            if (Tygh.addons.mwl_xlsx.compact_price_slider_labels) {
                updateSliderLabels();
            }
        }, 50);
    };

    // Экспорт для возможного использования извне
    _.mwlShortPriceSlider = {
        update: updateSliderLabels,
        shortNum: shortNum,
        formatNumber: formatNumber,
        formatInputs: formatSliderInputs
    };

}(Tygh, Tygh.$));
