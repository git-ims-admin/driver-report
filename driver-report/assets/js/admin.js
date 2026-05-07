/**
 * 勤怠管理 | 長距離ドライバー - admin.js
 */
(function ($) {
    'use strict';

    /* ---------------------------------------------------------------
     * 集計ボタンの活性制御
     * ------------------------------------------------------------- */
    function updateBtnState() {
        var driver = $('#dr-select-driver').val();
        var month  = $('#dr-select-month').val();
        $('#dr-btn-open').prop('disabled', !driver || !month);
    }

    $('#dr-select-driver').on('change', updateBtnState);
    $('#dr-select-month').on('change input', updateBtnState);

    /* ---------------------------------------------------------------
     * エラー表示ヘルパー
     * ------------------------------------------------------------- */
    function showError(msg) {
        $('#dr-load-error').text(msg).show();
        $('#dr-select-driver').html('<option value="">― 取得失敗 ―</option>');
    }

    /* ---------------------------------------------------------------
     * ページ読み込み時：ドライバー名一覧を AJAX で取得
     * ------------------------------------------------------------- */
    function loadDrivers() {

        // drData が未定義の場合はスクリプト読み込み失敗
        if ( typeof drData === 'undefined' ) {
            showError('drData が未定義です。プラグインの有効化・再読み込みを試してください。');
            return;
        }

        $.ajax({
            url     : drData.ajaxUrl,   // wp-admin/admin-ajax.php
            type    : 'POST',
            data    : {
                action : 'dr_get_drivers',
                nonce  : drData.nonce,
            },
            success : function (res) {
                var $sel = $('#dr-select-driver');
                $sel.empty();

                if ( ! res.success ) {
                    showError('取得エラー：' + res.data.message);
                    return;
                }

                var drivers = res.data.drivers;

                if ( ! drivers || drivers.length === 0 ) {
                    $sel.append('<option value="">― データがありません ―</option>');
                    return;
                }

                $sel.append('<option value="">― 社員を選択 ―</option>');
                $.each(drivers, function (i, name) {
                    $sel.append($('<option>').val(name).text(name));
                });

                updateBtnState();
            },
            error : function (xhr) {
                // HTTP エラー時：ステータスとレスポンス本文を両方表示
                var body = xhr.responseText
                    ? xhr.responseText.substring(0, 200)
                    : '（レスポンスなし）';
                showError(
                    '通信エラー HTTP ' + xhr.status + '\n' +
                    'レスポンス：' + body
                );
            }
        });
    }

    /* ---------------------------------------------------------------
     * ページ読み込み時：対象月を前月にセット
     * ------------------------------------------------------------- */
    function initMonth() {
        if ( typeof drData !== 'undefined' ) {
            $('#dr-select-month').val(drData.defaultMonth);
        }
        updateBtnState();
    }

    /* ---------------------------------------------------------------
     * 「集計表を開く」ボタン
     * ------------------------------------------------------------- */
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

    /* ---------------------------------------------------------------
     * 初期化
     * ------------------------------------------------------------- */
    loadDrivers();
    initMonth();

})(jQuery);
