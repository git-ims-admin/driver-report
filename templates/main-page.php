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
    <div class="dr-card dr-search-card">
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
                                    <?php echo esc_html( $emp['name'] ); ?>
                                    <?php if ( $emp['employee_code'] !== '―' ) : ?>（<?php echo esc_html( $emp['employee_code'] ); ?>）<?php endif; ?>
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
    <div class="dr-card dr-result-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-id-alt"></span>
            <?php echo esc_html( $selected_month ); ?>　<?php echo esc_html( $emp_info['name'] ); ?> さんの集計表
        </div>
        <div class="dr-card-body">

            <!-- 社員情報 -->
            <table class="dr-info-table">
                <thead>
                    <tr>
                        <th>社員名</th>
                        <th>社員No.</th>
                        <th>乗務員コード</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $emp_info['name'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['employee_code'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['crew_code'] ); ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- 勤怠メインテーブル -->
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
                            <th class="col-min">日残業</th>
                            <th class="col-min">深夜時間</th>
                            <th class="col-min">振替時間</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $monthly_rows as $row ) :
                        $is_holiday = ! $row['has_data'];
                        $row_class  = '';
                        if ( $row['is_sun'] ) $row_class = 'dr-row-sun';
                        elseif ( $row['is_sat'] ) $row_class = 'dr-row-sat';
                        elseif ( $is_holiday ) $row_class = 'dr-row-off';
                    ?>
                        <tr class="<?php echo $row_class; ?>">

                            <!-- 日付 -->
                            <td class="col-date">
                                <?php echo esc_html( substr( $row['date'], 5 ) ); ?>
                                <span class="dr-dow"><?php echo esc_html( $row['dow'] ); ?></span>
                            </td>

                            <!-- 勤怠種別 -->
                            <td class="col-kintai">
                                <select class="dr-kintai-select" name="kintai[<?php echo esc_attr( $row['date'] ); ?>]">
                                    <?php foreach ( $kintai_types as $type ) : ?>
                                        <option value="<?php echo esc_attr( $type ); ?>"
                                            <?php selected( $row['default_kintai'], $type ); ?>>
                                            <?php echo esc_html( $type ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <!-- 始業時刻 -->
                            <td class="col-time"><?php echo esc_html( $row['start_time'] ); ?></td>

                            <!-- 終業時刻 -->
                            <td class="col-time"><?php echo esc_html( $row['end_time'] ); ?></td>

                            <!-- 拘束時間 -->
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['kousoku_min'] ) ); ?></td>

                            <!-- 労働時間 -->
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['labor_min'] ) ); ?></td>

                            <!-- 運転時間 -->
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['drive_min'] ) ); ?></td>

                            <!-- 積卸時間 -->
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['cargo_min'] ) ); ?></td>

                            <!-- 休憩時間 = 拘束 - 労働 -->
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['break_calc_min'] ) ); ?></td>

                            <!-- 日残業 -->
                            <td class="col-min <?php echo ( $row['overtime_min'] > 0 ) ? 'dr-cell-over' : ''; ?>">
                                <?php echo esc_html( Tanpopo_DriverReport::format_min( $row['overtime_min'] ) ); ?>
                            </td>

                            <!-- 深夜時間 -->
                            <td class="col-min"><?php echo esc_html( Tanpopo_DriverReport::format_min( $row['midnight_min'] ) ); ?></td>

                            <!-- 振替時間（勤怠種別＝振替出勤の場合のみ労働時間を表示） -->
                            <td class="col-min dr-furikae-cell"
                                data-labor="<?php echo esc_attr( Tanpopo_DriverReport::format_min( $row['labor_min'] ) ); ?>"
                                data-default-kintai="<?php echo esc_attr( $row['default_kintai'] ); ?>">
                                <?php if ( $row['default_kintai'] === '振替出勤' ) : ?>
                                    <?php echo esc_html( Tanpopo_DriverReport::format_min( $row['labor_min'] ) ); ?>
                                <?php endif; ?>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div><!-- /.dr-table-wrap -->

        </div>
    </div>
    <?php endif; ?>

</div>
