/**
 * 勤怠管理 | 長距離ドライバー - admin.js
 */
(function ($) {
    'use strict';

    /* ---- 集計ボタンの活性制御 ---- */
    function updateBtnState() {
        var crew  = $('#dr-select-crew').val();
        var month = $('#dr-select-month').val();
        $('#dr-btn-open').prop('disabled', !crew || !month);
    }

    $('#dr-select-crew').on('change', updateBtnState);
    $('#dr-select-month').on('change input', updateBtnState);
    updateBtnState();

    /* ---- 勤怠種別変更 → 振替時間セルの表示切替 ---- */
    $(document).on('change', '.dr-kintai-select', function () {
        var $sel  = $(this);
        var $cell = $sel.closest('tr').find('.dr-furikae-cell');
        var labor = $cell.data('labor');

        if ( $sel.val() === '振替出勤' ) {
            $cell.text( labor );
        } else {
            $cell.text('');
        }
    });

})(jQuery);
