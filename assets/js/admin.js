/**
 * 勤怠管理 | 長距離ドライバー - admin.js
 */
(function ($) {
    'use strict';

    // ドライバー名が選択されたらボタンを活性化
    function updateBtnState() {
        var driver = $('#dr-select-driver').val();
        var month  = $('#dr-select-month').val();
        $('#dr-btn-open').prop('disabled', !driver || !month);
    }

    $('#dr-select-driver').on('change', updateBtnState);
    $('#dr-select-month').on('change input', updateBtnState);

    // 初期評価
    updateBtnState();

})(jQuery);
