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
                <div class="dr-form-row">
                    <div class="dr-form-group">
                        <label class="dr-label" for="dr-select-crew">社員名</label>
                        <select id="dr-select-crew" name="dr_crew" class="dr-select">
                            <option value="">― 社員を選択 ―</option>
                            <?php foreach ( $employees as $emp ) : ?>
                                <option value="<?php echo esc_attr( $emp['crew_code'] ); ?>"
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
        <div class="dr-card-header">
            <span class="dashicons dashicons-id-alt"></span>
            <?php echo esc_html( $selected_month ); ?>　<?php echo esc_html( $emp_info['name'] ); ?> さんの集計表
        </div>
        <div class="dr-card-body">

            <!-- 社員情報 -->
            <table class="dr-info-table">
                <thead>
                    <tr><th>社員名</th><th>社員No.</th><th>乗務員コード</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $emp_info['name'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['employee_code'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['crew_code'] ); ?></td>
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
                            <th class="col-min">振替時間</th>
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
                        <tr class="<?php echo $row_class; ?>" data-auto="true">
                            <td class="col-date">
                                <?php echo esc_html( substr( $row['date'], 5 ) ); ?>
                                <span class="dr-dow"><?php echo esc_html( $row['dow'] ); ?></span>
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
                            <td class="col-min <?php echo ( (int)$row['overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $row['overtime_min'] ) ); ?>
                            </td>
                            <td class="col-min dr-furikae-cell"
                                data-labor="<?php echo esc_attr( Tanpopo_DriverReport::format_min( $row['labor_min'] ) ); ?>">
                                <?php echo in_array( $kintai_val, [ '法定振替休', '所定振替休' ], true ) ? esc_html( Tanpopo_DriverReport::format_min( $row['labor_min'] ) ) : ''; ?>
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

        </div><!-- /.dr-card-body -->
    </div><!-- /.dr-card -->

    <?php endif; ?>

</div><!-- /.dr-wrap -->
