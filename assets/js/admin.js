/**
 * 勤怠管理 | 長距離ドライバー - admin.js
 */
(function ($) {
    'use strict';

    // ドライバー名が選択されたらボタンを活性化
    function updateBtnState() {
        var crew  = $('#dr-select-crew').val();
        var month = $('#dr-select-month').val();
        $('#dr-btn-open').prop('disabled', !crew || !month);
    }

    $('#dr-select-crew').on('change', updateBtnState);
    $('#dr-select-month').on('change input', updateBtnState);

    // 初期評価
    updateBtnState();

})(jQuery);
