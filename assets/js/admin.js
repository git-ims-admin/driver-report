/**
 * 勤怠管理 | 長距離ドライバー - admin.js
 */
(function ($) {
    'use strict';

    function updateBtnState() {
        var driver = $('#dr-select-driver').val();
        var month  = $('#dr-select-month').val();
        $('#dr-btn-open').prop('disabled', !driver || !month);
    }

    $('#dr-select-driver').on('change', updateBtnState);
    $('#dr-select-month').on('change input', updateBtnState);

    // 初期評価（月は初期値あり、ドライバー未選択で disabled）
    updateBtnState();

    $('#dr-btn-open').on('click', function () {
        var driver = $('#dr-select-driver').val();
        var month  = $('#dr-select-month').val();
        if ( !driver || !month ) return;

        var label = month + '　' + driver + ' さんの集計表';
        $('#dr-result-title').html(
            '<span class="dashicons dashicons-id-alt"></span> ' +
            $('<span>').text(label).html()
        );

        $('#dr-result-body').html(
            '<p class="dr-placeholder">（集計表はここに表示されます）</p>'
        );

        $('#dr-result-area').fadeIn(200);
        $('html, body').animate({
            scrollTop: $('#dr-result-area').offset().top - 30
        }, 300);
    });

})(jQuery);
