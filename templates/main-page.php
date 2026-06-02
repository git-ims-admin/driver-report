<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_url = admin_url( 'admin.php?page=driver-report' );
?>

<div class="wrap dr-wrap">

    <div class="dr-page-header">
        <h1 class="dr-page-title">
            <span class="dashicons dashicons-car"></span>
            勤怠管理 | 長距離ドライバー
        </h1>
        <p class="dr-page-desc">拘束時間管理データをもとに、ドライバーごとの月次勤怠データを確認できます。</p>
    </div>

    <?php if ( ! empty( $db_error ) ) : ?>
    <div class="dr-notice dr-notice-error">
        <strong>DBエラー：</strong><?php echo esc_html( $db_error ); ?>
    </div>
    <?php endif; ?>

    <!-- 検索フォーム -->
    <div class="dr-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-search"></span>
            集計条件の選択
        </div>
        <div class="dr-card-body">
            <form method="GET" action="<?php echo esc_url( $page_url ); ?>">
                <input type="hidden" name="page" value="driver-report">

                <?php
                // 所属一覧をemployeesから生成（重複除去・順序維持）
                $affil_map = [];
                foreach ( $employees as $emp ) {
                    $aid = (int) $emp['affiliation_id'];
                    if ( ! isset( $affil_map[ $aid ] ) ) {
                        $affil_map[ $aid ] = $emp['affiliation_name'];
                    }
                }
                ?>
                <!-- 所属フィルターチップ -->
                <?php if ( count( $affil_map ) > 1 ) : ?>
                <div class="dr-affil-chips">
                    <button type="button" class="dr-chip dr-chip-active" data-affil="all">すべて</button>
                    <?php foreach ( $affil_map as $aid => $aname ) : ?>
                    <button type="button" class="dr-chip" data-affil="<?php echo esc_attr( $aid ); ?>">
                        <?php echo esc_html( $aname ); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="dr-form-row">
                    <div class="dr-form-group">
                        <label class="dr-label" for="dr-select-crew">社員名</label>
                        <select id="dr-select-crew" name="dr_crew" class="dr-select">
                            <option value="">― 社員を選択 ―</option>
                            <?php foreach ( $employees as $emp ) : ?>
                                <option value="<?php echo esc_attr( $emp['crew_code'] ); ?>"
                                    data-affil="<?php echo esc_attr( $emp['affiliation_id'] ); ?>"
                                    <?php selected( $selected_crew, $emp['crew_code'] ); ?>>
                                    <?php if ( $emp['employee_code'] !== '―' ) : ?>[<?php echo esc_html( $emp['employee_code'] ); ?>]<?php endif; ?><?php echo esc_html( $emp['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dr-form-group">
                        <label class="dr-label" for="dr-select-month">対象月</label>
                        <input type="month" id="dr-select-month" name="dr_month" class="dr-input-month"
                            value="<?php echo esc_attr( $selected_month ); ?>">
                    </div>
                    <div class="dr-form-group dr-form-group--btn">
                        <button type="submit" id="dr-btn-open" class="dr-btn dr-btn-primary"
                            <?php echo ( $selected_crew === '' ) ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-chart-bar"></span>
                            集計表を開く
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ( $emp_info !== null ) : ?>

    <!-- 集計結果 -->
    <div class="dr-card">
        <?php
        $prev_month = date( 'Y-m', strtotime( $selected_month . '-01 -1 month' ) );
        $next_month = date( 'Y-m', strtotime( $selected_month . '-01 +1 month' ) );
        $prev_url   = esc_url( add_query_arg( [ 'page' => 'driver-report', 'dr_crew' => $selected_crew, 'dr_month' => $prev_month ], admin_url( 'admin.php' ) ) );
        $next_url   = esc_url( add_query_arg( [ 'page' => 'driver-report', 'dr_crew' => $selected_crew, 'dr_month' => $next_month ], admin_url( 'admin.php' ) ) );
        ?>
        <div class="dr-card-header" style="justify-content:space-between;">
            <span style="display:flex;align-items:center;gap:12px;">
                <span class="dashicons dashicons-id-alt"></span>
                <a href="<?php echo $prev_url; ?>" class="dr-btn dr-btn-nav" title="前月">
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </a>
                <span><?php echo esc_html( $selected_month ); ?>　<?php echo esc_html( $emp_info['name'] ); ?> さんの集計表</span>
                <a href="<?php echo $next_url; ?>" class="dr-btn dr-btn-nav" title="翌月">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </a>
            </span>
            <button type="button" id="dr-btn-save"
                class="dr-btn dr-btn-save"
                data-crew="<?php echo esc_attr( $selected_crew ); ?>"
                data-month="<?php echo esc_attr( $selected_month ); ?>">
                <span class="dashicons dashicons-saved"></span>
                保存（更新）
            </button>
        </div>
        <div id="dr-save-message" style="display:none;padding:8px 20px;font-size:13px;font-weight:700;"></div>
        <div class="dr-card-body">

            <!-- 社員情報 -->
            <table class="dr-info-table">
                <thead>
                    <tr><th>社員名</th><th>社員No.</th><th>乗務員コード</th><th>所属</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $emp_info['name'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['employee_code'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['crew_code'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['affiliation_name'] ); ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- 警告バッジ -->
            <?php
            $alerts = $monthly_rows[0]['_alerts'] ?? [];
            if ( ! empty( $alerts ) ) :
            ?>
            <div class="dr-alerts">
                <?php foreach ( $alerts as $alert ) : ?>
                <div class="dr-alert <?php echo $alert['type'] === 'error' ? 'dr-alert-error' : 'dr-alert-warn'; ?>">
                    <span class="dashicons <?php echo $alert['type'] === 'error' ? 'dashicons-warning' : 'dashicons-info'; ?>"></span>
                    <?php echo esc_html( $alert['message'] ); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- 日別一覧テーブル -->
            <div class="dr-table-wrap">
                <table class="dr-main-table">
                    <thead>
                        <tr>
                            <th class="col-date">日付</th>
                            <th class="col-kintai">勤怠種別</th>
                            <th class="col-time">始業時刻</th>
                            <th class="col-time">終業時刻</th>
                            <th class="col-min">拘束時間</th>
                            <th class="col-min">労働時間</th>
                            <th class="col-min">運転時間</th>
                            <th class="col-min">積卸時間</th>
                            <th class="col-min">休憩時間</th>
                            <th class="col-min">深夜時間</th>
                            <th class="col-min">日残業</th>
                             <th class="col-jiba">地場</th>
                            <th class="col-min">早退/遅刻</th>
                            <th class="col-note">備考</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $monthly_rows as $row ) :
                        $row_class  = '';
                        $kintai_val = $row['default_kintai'];
                        if ( $row['is_sun'] )         $row_class = 'dr-row-sun';
                        elseif ( $row['is_sat'] )     $row_class = 'dr-row-sat';
                        elseif ( ! $row['has_data'] ) $row_class = 'dr-row-off';
                    ?>
                        <tr class="<?php echo $row_class; ?>"
                            data-date="<?php echo esc_attr( $row['date'] ); ?>"
                            data-auto="<?php echo $row['is_manual'] ? 'false' : 'true'; ?>"
                            data-furikae="<?php echo esc_attr( $row['furikae_label'] ); ?>">
                            <td class="col-date">
                                <span class="dr-date-row">
                                    <?php echo esc_html( substr( $row['date'], 5 ) ); ?>
                                    <span class="dr-dow"><?php echo esc_html( $row['dow'] ); ?></span>
                                </span>
                                <?php if ( ! empty( $row['furikae_label'] ) ) : ?>
                                <span class="dr-furikae-label"><?php echo esc_html( $row['furikae_label'] ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-kintai">
                                <select class="dr-kintai-select" name="kintai[<?php echo esc_attr( $row['date'] ); ?>]">
                                    <option value="" <?php selected( $kintai_val, '' ); ?>>― 選択 ―</option>
                                    <?php foreach ( $kintai_types as $type ) : ?>
                                        <option value="<?php echo esc_attr( $type ); ?>"
                                            <?php selected( $kintai_val, $type ); ?>>
                                            <?php echo esc_html( $type ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="col-time"><?php echo esc_html( $row['start_time'] ); ?></td>
                            <td class="col-time"><?php echo esc_html( $row['end_time'] ); ?></td>
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['kousoku_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['labor_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['drive_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['cargo_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['break_calc_min'] ) ); ?></td>
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['midnight_min'] ) ); ?></td>
                            <td class="col-min <?php echo ( (int)$row['overtime_min'] > 0 && ! $row['kyuujitsu_kinmu'] ) ? 'dr-cell-over' : ''; ?>">
                                <?php if ( $row['kyuujitsu_kinmu'] ) : ?>
                                    <span class="dr-badge-kyuujitsu">休日出勤</span>
                                <?php else : ?>
                                    <?php echo esc_html( Tanpopo_DriverReport::format_min( $row['overtime_min'] ) ); ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-jiba">
                                <label class="dr-jiba-toggle">
                                    <input type="checkbox"
                                    class="dr-jiba-input"
                                    <?php echo ( $row['jiba'] ?? false ) ? 'checked' : ''; ?>>
                                    <span class="dr-jiba-slider"></span>
                                </label>
                            </td>
                            <td class="col-min">
                                <input type="text"
                                    inputmode="numeric"
                                    class="dr-hayatai-input"
                                    value="<?php echo $row['hayatai_min'] > 0 ? esc_attr( DR_Compute::format_min( $row['hayatai_min'] ) ) : ''; ?>"
                                    placeholder="0:00">
                            </td>
                            <td class="col-note">
                                <input type="text"
                                    class="dr-note-input"
                                    value="<?php echo esc_attr( $row['note'] ?? '' ); ?>"
                                    placeholder="備考">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div><!-- /.dr-table-wrap 日別 -->

            <!-- 週次サマリーテーブル -->
            <?php if ( $weekly ) : ?>
            <div class="dr-table-wrap dr-weekly-wrap">
                <table class="dr-weekly-table">
                    <thead>
                        <tr>
                            <th class="wcol-label">期間</th>
                            <th class="wcol-date">開始日</th>
                            <th class="wcol-date">終了日</th>
                            <th class="wcol-days">日数</th>
                            <th class="wcol-min">拘束時間</th>
                            <th class="wcol-min">労働時間</th>
                            <th class="wcol-min">運転時間</th>
                            <th class="wcol-min">積卸時間</th>
                            <th class="wcol-min">休憩時間</th>
                            <th class="wcol-min">深夜時間</th>
                            <th class="wcol-min">日残業</th>
                            <th class="wcol-min">週残業</th>
                            <th class="wcol-min">確定残業</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $weekly['weeks'] as $w ) :
                            $w_class = '';
                            if ( $w['is_carryover'] )   $w_class = 'dr-week-carryover';
                            if ( $w['is_prev_carry'] )  $w_class = 'dr-week-prev-carry';
                        ?>
                        <tr class="<?php echo $w_class; ?>">
                            <td class="wcol-label"><?php echo esc_html( $w['label'] ); ?></td>
                            <td class="wcol-date"><?php echo esc_html( $w['disp_start'] ); ?></td>
                            <td class="wcol-date"><?php echo esc_html( $w['disp_end'] ); ?></td>
                            <td class="wcol-days"><?php echo esc_html( $w['days'] ); ?>日</td>
                            <?php if ( $w['is_prev_carry'] ) : ?>
                            <td class="wcol-min dr-cell-na">―</td>
                            <td class="wcol-min dr-cell-na">―</td>
                            <td class="wcol-min dr-cell-na">―</td>
                            <td class="wcol-min dr-cell-na">―</td>
                            <td class="wcol-min dr-cell-na">―</td>
                            <td class="wcol-min dr-cell-na">―</td>
                            <td class="wcol-min <?php echo ( (int)$w['day_overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $w['day_overtime_min'] ) ); ?>
                            </td>
                            <td class="wcol-min <?php echo ( (int)$w['week_overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $w['week_overtime_min'] ) ); ?>
                            </td>
                            <td class="wcol-min dr-cell-na">―</td>
                            <?php else : ?>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $w['kousoku_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $w['labor_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $w['drive_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $w['cargo_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $w['break_min'] ) ); ?></td>
                            <td class="wcol-min">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $w['midnight_min'] ) ); ?>
                            </td>
                            <td class="wcol-min <?php echo ( (int)$w['day_overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $w['day_overtime_min'] ) ); ?>
                            </td>
                            <td class="wcol-min <?php echo ( (int)$w['week_overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php if ( $w['is_carryover'] ) : ?>
                                    <span class="dr-badge-carryover">次月繰越</span>
                                <?php else : ?>
                                    <?php echo esc_html( Tanpopo_DriverReport::format_min( $w['week_overtime_min'] ) ); ?>
                                <?php endif; ?>
                            </td>
                            <td class="wcol-min <?php echo ( (int)$w['confirmed_overtime'] > 0 ) ? 'dr-cell-over' : ''; ?> dr-cell-confirmed
                                <?php echo ( $w['carry_days'] > 0 ) ? 'dr-cell-has-tip' : ''; ?>"
                                <?php if ( $w['carry_days'] > 0 ) : ?>
                                title="前月繰越（<?php echo (int)$w['carry_days']; ?>日分）を加算した全<?php echo (int)$w['carry_days'] + (int)$w['days']; ?>日間の労働時間で週残業を判定し、日残業合計と比較して大きい方を確定残業としています。"
                                <?php endif; ?>
                            >
                                <?php if ( $w['is_carryover'] ) : ?>
                                    <span class="dr-badge-carryover">次月繰越</span>
                                <?php else : ?>
                                    <?php echo esc_html( Tanpopo_DriverReport::format_min( $w['confirmed_overtime'] ) ); ?>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="dr-week-total">
                            <td class="wcol-label">月間合計</td>
                            <td class="wcol-date" colspan="2"></td>
                            <td class="wcol-days"><?php echo esc_html( $weekly['total']['days'] ); ?>日</td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['kousoku_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['labor_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['drive_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['cargo_min'] ) ); ?></td>
                            <td class="wcol-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['break_min'] ) ); ?></td>
                            <td class="wcol-min">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['midnight_min'] ) ); ?>
                            </td>
                            <td class="wcol-min <?php echo ( (int)$weekly['total']['day_overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['day_overtime_min'] ) ); ?>
                            </td>
                            <td class="wcol-min <?php echo ( (int)$weekly['total']['week_overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['week_overtime_min'] ) ); ?>
                            </td>
                            <td class="wcol-min <?php echo ( (int)$weekly['total']['confirmed_overtime'] > 0 ) ? 'dr-cell-over' : ''; ?> dr-cell-confirmed">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $weekly['total']['confirmed_overtime'] ) ); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div><!-- /.dr-weekly-wrap -->
            <?php endif; ?>

            <!-- ============================================================
                 月間サマリ
                 ============================================================ -->
            <?php if ( $monthly_summary !== null ) : ?>
            <div class="dr-monthly-summary-wrap">
                <div class="dr-card-header" style="border-radius:6px 6px 0 0;">
                    <span class="dashicons dashicons-chart-area"></span>
                    月間サマリ
                </div>
                <div style="overflow-x:auto;">
                    <table class="dr-ms-table">
                        <thead>
                            <tr>
                                <th>出勤日数</th>
                                <th>欠勤日数</th>
                                <th>休日出勤日数</th>
                                <th>有給消化日数</th>
                                <th>有給残日数</th>
                                <th>労働時間</th>
                                <th>早退遅刻時間</th>
                                <th>確定残業時間</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="dr-ms-num" data-ms="attendance"><?php echo (int) $monthly_summary['attendance']; ?><span class="dr-ms-unit">日</span></td>
                                <td class="dr-ms-num <?php echo $monthly_summary['absent'] > 0 ? 'dr-ms-alert' : ''; ?>" data-ms="absent"><?php echo (int) $monthly_summary['absent']; ?><span class="dr-ms-unit">日</span></td>
                                <td class="dr-ms-num <?php echo $monthly_summary['holiday_work'] > 0 ? 'dr-ms-warn' : ''; ?>" data-ms="holiday_work"><?php echo (int) $monthly_summary['holiday_work']; ?><span class="dr-ms-unit">日</span></td>
                                <td class="dr-ms-num" data-ms="paid_consumed">
                                    <?php if ( $monthly_summary['paid_has_data'] ) : ?>
                                        <?php echo number_format( $monthly_summary['paid_consumed'], 1 ); ?><span class="dr-ms-unit">日</span>
                                    <?php else : ?>
                                        <span class="dr-ms-na">―</span>
                                    <?php endif; ?>
                                </td>
                                <td class="dr-ms-num" data-ms="paid_remaining">
                                    <?php if ( $monthly_summary['paid_has_data'] ) : ?>
                                        <?php echo number_format( $monthly_summary['paid_remaining'], 1 ); ?><span class="dr-ms-unit">日</span>
                                    <?php else : ?>
                                        <span class="dr-ms-na">―</span>
                                    <?php endif; ?>
                                </td>
                                <td class="dr-ms-num" data-ms="labor"><?php echo esc_html( DR_Compute::format_min( $monthly_summary['labor_min'] ) ); ?></td>
                                <td class="dr-ms-num <?php echo $monthly_summary['hayatai_min'] > 0 ? 'dr-ms-warn' : ''; ?>" data-ms="hayatai">
                                    <?php echo $monthly_summary['hayatai_min'] > 0 ? esc_html( DR_Compute::format_min( $monthly_summary['hayatai_min'] ) ) : '―'; ?>
                                </td>
                                <td class="dr-ms-num <?php echo (int)$monthly_summary['overtime_min'] > 0 ? 'dr-ms-over' : ''; ?>" data-ms="overtime"><?php echo esc_html( DR_Compute::format_min( $monthly_summary['overtime_min'] ) ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.dr-card-body -->
    </div><!-- /.dr-card -->

    <?php endif; ?>

</div><!-- /.dr-wrap -->