/**
 * 勤怠管理 | 長距離ドライバー - admin.js
 */
(function ($) {
    'use strict';

    /* ================================================================
       集計表示ページ
       ================================================================ */

    /* ---- 集計ボタンの活性制御 ---- */
    function updateBtnState() {
        var crew = $('#dr-select-crew').val();
        var month = $('#dr-select-month').val();
        $('#dr-btn-open').prop('disabled', !crew || !month);
    }

    $('#dr-select-crew').on('change', updateBtnState);
    $('#dr-select-month').on('change input', updateBtnState);
    updateBtnState();

    /* ---- 所属チップ → 社員セレクトのフィルタリング ---- */
    $(document).on('click', '.dr-chip', function () {
        var $chip = $(this);
        var affil = $chip.data('affil');

        $('.dr-chip').removeClass('dr-chip-active');
        $chip.addClass('dr-chip-active');

        var $select = $('#dr-select-crew');
        var $options = $select.find('option[data-affil]');

        if (affil === 'all') {
            $options.show();
        } else {
            $options.each(function () {
                if ($(this).data('affil') == affil) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }

        var $selected = $select.find('option:selected');
        if ($selected.val() !== '' && $selected.is(':hidden')) {
            $select.val('');
        }

        updateBtnState();
    });

    /* ---- 勤怠種別変更 → data-auto フラグ ---- */
    $(document).on('change', '.dr-kintai-select', function () {
        $(this).closest('tr').attr('data-auto', 'false');
    });

    /* ---- H:MM 文字列 → 分（整数）変換 ---- */
    function parseMin(str) {
        if (!str || str.trim() === '') return 0;
        var parts = str.trim().split(':');
        var h = parseInt(parts[0], 10) || 0;
        var m = parseInt(parts[1], 10) || 0;
        return h * 60 + m;
    }

    /* ---- 保存（更新）ボタン ---- */
    $(document).on('click', '#dr-btn-save', function () {
        var $btn = $(this);
        var crewCode = $btn.data('crew');
        var month = $btn.data('month');
        var $msg = $('#dr-save-message');

        // 全行の勤怠データを収集
        var rows = [];
        $('tbody tr[data-date]').each(function () {
            var $tr = $(this);
            rows.push({
                date: $tr.data('date'),
                kintai_type: $tr.find('.dr-kintai-select').val() || '',
                furikae_label: $tr.data('furikae') || '',
                is_manual: $tr.attr('data-auto') === 'false' ? 1 : 0,
                hayatai_min: parseMin($tr.find('.dr-hayatai-input').val()),
                note: $tr.find('.dr-note-input').val() || '',
            });
        });

        $btn.prop('disabled', true).text('保存中...');
        $msg.hide();

        $.post(drData.ajaxUrl, {
            action: 'dr_kintai_save',
            nonce: drData.nonce,
            crew_code: crewCode,
            rows: rows,
        }, function (res) {
            if (res.success) {
                $msg.text(res.data.saved + '件を保存しました')
                    .css({ color: '#2c5f2e', background: '#f0fff0', borderLeft: '4px solid #2c5f2e', padding: '8px 20px' })
                    .show();
                drRefreshSummary(crewCode, month);
            } else {
                $msg.text('保存に失敗しました：' + (res.data.message || ''))
                    .css({ color: '#7a1a1a', background: '#fff0f0', borderLeft: '4px solid #d63638', padding: '8px 20px' })
                    .show();
            }
        }).fail(function () {
            $msg.text('通信エラーが発生しました')
                .css({ color: '#7a1a1a', background: '#fff0f0', borderLeft: '4px solid #d63638', padding: '8px 20px' })
                .show();
        }).always(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> 保存（更新）');
            setTimeout(function () { $msg.fadeOut(); }, 4000);
        });
    });

    /* ================================================================
       休日マスタ設定ページ
       ================================================================ */

    var _editingId = 0;

    function hmShowMessage(msg, isError) {
        var $m = $('#hm-message');
        $m.text(msg).css('color', isError ? '#d63638' : '#2c5f2e');
        setTimeout(function () { $m.text(''); }, 4000);
    }

    function hmReloadTable() {
        $.post(drData.ajaxUrl, {
            action: 'dr_holiday_get_rules',
            nonce: drData.nonce,
        }, function (res) {
            if (!res.success) return;
            var rules = res.data;
            var $tbody = $('#hm-rule-tbody');
            $tbody.empty();

            if (!rules.length) {
                $tbody.append('<tr><td colspan="5" style="text-align:center;color:#aaa;padding:24px;">登録済みルールはありません</td></tr>');
                return;
            }

            var dowLabels = ['日', '月', '火', '水', '木', '金', '土'];
            $.each(rules, function (i, r) {
                var weeks = r.week_numbers.split(',').join('・');
                var activeLabel = r.is_active == 1 ? '有効' : '無効';
                var activeClass = r.is_active == 1 ? 'hm-active' : 'hm-inactive';
                var toggleLabel = r.is_active == 1 ? '無効化' : '有効化';
                var toggleBg = r.is_active == 1 ? '#aaa' : '#2c5f2e';
                var $tr = $(
                    '<tr data-id="' + r.id + '">' +
                    '<td>' + $('<span>').text(r.affiliation_name).html() + '</td>' +
                    '<td>' + dowLabels[parseInt(r.day_of_week)] + '曜日</td>' +
                    '<td>第' + weeks + '週</td>' +
                    '<td><span class="hm-status ' + activeClass + '">' + activeLabel + '</span></td>' +
                    '<td>' +
                    '<button class="dr-btn hm-btn-edit" style="height:30px;padding:0 12px;font-size:12px;background:#2e6da4;color:#fff;"' +
                    ' data-id="' + r.id + '" data-affil="' + r.affiliation_id + '" data-dow="' + r.day_of_week + '" data-weeks="' + r.week_numbers + '">編集</button>' +
                    '<button class="dr-btn hm-btn-toggle" style="height:30px;padding:0 12px;font-size:12px;background:' + toggleBg + ';color:#fff;margin-left:4px;"' +
                    ' data-id="' + r.id + '" data-active="' + r.is_active + '">' + toggleLabel + '</button>' +
                    '<button class="dr-btn hm-btn-delete" style="height:30px;padding:0 12px;font-size:12px;background:#d63638;color:#fff;margin-left:4px;"' +
                    ' data-id="' + r.id + '">削除</button>' +
                    '</td>' +
                    '</tr>'
                );
                $tbody.append($tr);
            });
        });
    }

    /* 保存ボタン */
    $(document).on('click', '#hm-btn-save', function () {
        var affilId = $('#hm-affiliation').val();
        var dow = $('#hm-dow').val();
        var weeks = $('#hm-weeks').val().trim();

        if (!affilId || weeks === '') {
            hmShowMessage('所属と対象週は必須です', true);
            return;
        }

        $.post(drData.ajaxUrl, {
            action: 'dr_holiday_save_rule',
            nonce: drData.nonce,
            id: _editingId,
            affiliation_id: affilId,
            day_of_week: dow,
            week_numbers: weeks,
        }, function (res) {
            if (res.success) {
                hmShowMessage('保存しました', false);
                hmResetForm();
                hmReloadTable();
            } else {
                hmShowMessage(res.data.message || '保存に失敗しました', true);
            }
        });
    });

    /* キャンセルボタン */
    $(document).on('click', '#hm-btn-cancel', function () {
        hmResetForm();
    });

    function hmResetForm() {
        _editingId = 0;
        $('#hm-affiliation').val('');
        $('#hm-dow').val('0');
        $('#hm-weeks').val('');
        $('#hm-btn-cancel').hide();
        $('#hm-btn-save').text('保存');
    }

    /* 編集ボタン */
    $(document).on('click', '.hm-btn-edit', function () {
        var $btn = $(this);
        _editingId = parseInt($btn.data('id'));
        $('#hm-affiliation').val($btn.data('affil'));
        $('#hm-dow').val($btn.data('dow'));
        $('#hm-weeks').val($btn.data('weeks'));
        $('#hm-btn-cancel').show();
        $('#hm-btn-save').text('更新');
        $('html, body').animate({ scrollTop: 0 }, 300);
    });

    /* 有効/無効切替ボタン */
    $(document).on('click', '.hm-btn-toggle', function () {
        var $btn = $(this);
        var id = parseInt($btn.data('id'));
        var isActive = parseInt($btn.data('active'));
        var newActive = isActive === 1 ? 0 : 1;
        $.post(drData.ajaxUrl, {
            action: 'dr_holiday_toggle_rule',
            nonce: drData.nonce,
            id: id,
            is_active: newActive,
        }, function (res) {
            if (res.success) hmReloadTable();
        });
    });

    /* 削除ボタン */
    $(document).on('click', '.hm-btn-delete', function () {
        if (!window.confirm('このルールを削除しますか？')) return;
        var id = parseInt($(this).data('id'));
        $.post(drData.ajaxUrl, {
            action: 'dr_holiday_delete_rule',
            nonce: drData.nonce,
            id: id,
        }, function (res) {
            if (res.success) hmReloadTable();
        });
    });
    function drRefreshSummary(crewCode, yearMonth) {
        $.post(drData.ajaxUrl, {
            action: 'dr_get_monthly_summary',
            nonce: drData.nonce,
            crew_code: crewCode,
            year_month: yearMonth,
        }, function (res) {
            if (!res.success) return;
            var s = res.data;

            // 各セルを data-ms 属性で特定して更新
            $('[data-ms="attendance"]').html(s.attendance + '<span class="dr-ms-unit">日</span>');
            $('[data-ms="absent"]').html(s.absent + '<span class="dr-ms-unit">日</span>')
                .toggleClass('dr-ms-alert', s.absent > 0);
            $('[data-ms="holiday_work"]').html(s.holiday_work + '<span class="dr-ms-unit">日</span>')
                .toggleClass('dr-ms-warn', s.holiday_work > 0);
            $('[data-ms="paid_consumed"]').html(
                s.paid_has_data
                    ? parseFloat(s.paid_consumed).toFixed(1) + '<span class="dr-ms-unit">日</span>'
                    : '<span class="dr-ms-na">―</span>'
            );
            $('[data-ms="paid_remaining"]').html(
                s.paid_has_data
                    ? parseFloat(s.paid_remaining).toFixed(1) + '<span class="dr-ms-unit">日</span>'
                    : '<span class="dr-ms-na">―</span>'
            );
            $('[data-ms="labor"]').html(s.labor_str);
            $('[data-ms="hayatai"]').html(s.hayatai_str || '―')
                .toggleClass('dr-ms-warn', s.hayatai_min > 0);
            $('[data-ms="overtime"]').html(s.overtime_str)
                .toggleClass('dr-ms-over', s.overtime_min > 0);
        });
    }
})(jQuery);
