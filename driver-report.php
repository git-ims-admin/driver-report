<?php
/**
 * Plugin Name: 勤怠管理 | 長距離ドライバー
 * Description: 長距離ドライバーの勤怠データを収集・表示・CSV出力するプラグイン
 * Version:     1.1.0
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'DR_VERSION' ) )    define( 'DR_VERSION',    '1.1.0' );
if ( ! defined( 'DR_PLUGIN_DIR' ) ) define( 'DR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'DR_PLUGIN_URL' ) ) define( 'DR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'Tanpopo_DriverReport' ) ) :

class Tanpopo_DriverReport {

    const KINTAI_TYPES = [ '出勤', '法定休', '所定休', '年休', '振替休', '振替出勤', '緊急出勤' ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        register_activation_hook( __FILE__,  [ $this, 'activate' ] );
    }

    /* ---------------------------------------------------------------
     * プラグイン有効化：wp_dr_carryover テーブル作成
     * ------------------------------------------------------------- */
    public function activate() {
        global $wpdb;
        $table   = $wpdb->prefix . 'dr_carryover';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `crew_code`    VARCHAR(20)      NOT NULL,
            `year_month`   CHAR(7)          NOT NULL COMMENT '繰越先の月 YYYY-MM',
            `labor_min`    INT              NOT NULL DEFAULT 0,
            `drive_min`    INT              NOT NULL DEFAULT 0,
            `cargo_min`    INT              NOT NULL DEFAULT 0,
            `kousoku_min`  INT              NOT NULL DEFAULT 0,
            `midnight_min` INT              NOT NULL DEFAULT 0,
            `days`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_month` (`crew_code`(20), `year_month`)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ---------------------------------------------------------------
     * メニュー登録
     * ------------------------------------------------------------- */
    public function add_menu() {
        add_menu_page(
            '勤怠管理 | 長距離ドライバー',
            '勤怠管理 | 長距離ドライバー',
            'manage_options',
            'driver-report',
            [ $this, 'render_page' ],
            'dashicons-car',
            28
        );
    }

    /* ---------------------------------------------------------------
     * アセット読み込み
     * ------------------------------------------------------------- */
    public function enqueue_assets() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
        if ( $page !== 'driver-report' ) return;
        wp_enqueue_style( 'dr-admin', DR_PLUGIN_URL . 'assets/css/admin.css', [], DR_VERSION );
        wp_enqueue_script( 'dr-admin', DR_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], DR_VERSION, true );
        wp_localize_script( 'dr-admin', 'drData', [
            'defaultMonth' => date( 'Y-m', strtotime( 'first day of last month' ) ),
        ] );
    }

    /* ===============================================================
     * ユーティリティ
     * ============================================================= */

    /** 分 → H:MM */
    public static function format_min( $min ) {
        if ( $min === null || $min === '' ) return '';
        $min = (int) $min;
        if ( $min < 0 ) {
            return '-' . floor( abs($min) / 60 ) . ':' . str_pad( abs($min) % 60, 2, '0', STR_PAD_LEFT );
        }
        return floor( $min / 60 ) . ':' . str_pad( $min % 60, 2, '0', STR_PAD_LEFT );
    }

    /** tenrec entries の最終終業時刻（3業務→2業務→1業務の順） */
    private static function get_last_g_time( $entry ) {
        foreach ( [ 'g7_time', 'g5_time', 'g3_time' ] as $key ) {
            $val = trim( $entry[ $key ] ?? '' );
            if ( $val !== '' ) return $val;
        }
        return '';
    }

    /* ===============================================================
     * DB アクセス
     * ============================================================= */

    /** wp_kousoku_log の crew_code から社員一覧取得 */
    private function get_employees_from_kousoku() {
        global $wpdb;
        $rows = $wpdb->get_results( "
            SELECT
                k.crew_code,
                COALESCE( m.name,          '（未登録）' ) AS name,
                COALESCE( m.employee_code, '―'          ) AS employee_code
            FROM (
                SELECT DISTINCT crew_code
                FROM `{$wpdb->prefix}kousoku_log`
                WHERE crew_code IS NOT NULL AND crew_code <> ''
            ) k
            LEFT JOIN `{$wpdb->prefix}emp_master` m
                ON m.crew_code COLLATE utf8mb4_unicode_520_ci = k.crew_code COLLATE utf8mb4_unicode_520_ci
            ORDER BY k.crew_code ASC
        ", ARRAY_A );
        return [
            'employees' => is_array( $rows ) ? $rows : [],
            'error'     => $wpdb->last_error,
        ];
    }

    /** crew_code で社員情報取得 */
    private function get_emp_info_by_crew( $crew_code ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT name, employee_code, crew_code
            FROM `{$wpdb->prefix}emp_master`
            WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
            LIMIT 1
        ", $crew_code ), ARRAY_A );
        return $row ?: [ 'name' => '（未登録）', 'employee_code' => '―', 'crew_code' => $crew_code ];
    }

    /** 繰越データ取得 */
    private function get_carryover( $crew_code, $year_month ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM `{$wpdb->prefix}dr_carryover`
            WHERE `crew_code` = %s AND `year_month` = %s
        ", $crew_code, $year_month ), ARRAY_A );
    }

    /** 繰越データ保存（UPSERT） */
    private function save_carryover( $crew_code, $year_month, $data ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'dr_carryover';
        $existing = $this->get_carryover( $crew_code, $year_month );
        if ( $existing ) {
            $wpdb->update(
                $table,
                $data,
                [ 'crew_code' => $crew_code, 'year_month' => $year_month ],
                null,
                [ '%s', '%s' ]
            );
        } else {
            $wpdb->insert( $table, array_merge( $data, [
                'crew_code'  => $crew_code,
                'year_month' => $year_month,
            ] ) );
        }
    }

    /* ===============================================================
     * 日別データ生成
     * ============================================================= */
    private function get_monthly_rows( $crew_code, $year_month, $driver_name ) {
        global $wpdb;

        $start_date = $year_month . '-01';
        $end_date   = date( 'Y-m-t', strtotime( $start_date ) );
        $ymd_start  = str_replace( '-', '', $start_date );
        $ymd_end    = str_replace( '-', '', $end_date );

        // kousoku_log 取得
        $kousoku_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT * FROM `{$wpdb->prefix}kousoku_log`
            WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
              AND work_date BETWEEN %s AND %s
            ORDER BY work_date ASC
        ", $crew_code, $start_date, $end_date ), ARRAY_A );

        $kousoku_by_date = [];
        foreach ( (array) $kousoku_rows as $r ) {
            $kousoku_by_date[ $r['work_date'] ] = $r;
        }

        // tenrec_daily 取得（driver 名で絞り込み）
        $tenrec_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT ymd, entries FROM `{$wpdb->prefix}tenrec_daily`
            WHERE ymd BETWEEN %s AND %s
        ", $ymd_start, $ymd_end ), ARRAY_A );

        $tenrec_by_date = [];
        foreach ( (array) $tenrec_rows as $r ) {
            $ymd  = $r['ymd'];
            $date = substr($ymd,0,4).'-'.substr($ymd,4,2).'-'.substr($ymd,6,2);
            $entries = json_decode( $r['entries'], true );
            if ( ! is_array( $entries ) ) continue;
            foreach ( $entries as $entry ) {
                if ( trim( $entry['driver'] ?? '' ) === $driver_name ) {
                    $tenrec_by_date[ $date ] = $entry;
                    break;
                }
            }
        }

        $dow_ja = [ 'Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土' ];
        $rows   = [];
        $cursor = new DateTime( $start_date );
        $last   = new DateTime( $end_date );

        while ( $cursor <= $last ) {
            $date_str = $cursor->format('Y-m-d');
            $dow      = $dow_ja[ $cursor->format('D') ];
            $is_sun   = $cursor->format('N') == 7;
            $is_sat   = $cursor->format('N') == 6;

            $k = $kousoku_by_date[ $date_str ] ?? null;
            $t = $tenrec_by_date[ $date_str ]  ?? null;

            // 始業時刻
            $start_time = '';
            if ( $t ) $start_time = trim( $t['g1_time'] ?? '' );
            if ( $start_time === '' && $k ) $start_time = substr( $k['start_time'] ?? '', 0, 5 );

            // 終業時刻
            $end_time = '';
            if ( $t ) $end_time = self::get_last_g_time( $t );
            if ( $end_time === '' && $k ) $end_time = substr( $k['end_time'] ?? '', 0, 5 );

            // 拘束時間
            $kousoku_min = $k ? (int)( $k['kousoku_total_min'] ?? 0 ) : null;

            // drive_min / cargo_min
            $drive_min = null;
            $cargo_min = null;
            if ( $k ) {
                $drive_min = isset($k['drive_min'])  && $k['drive_min']  !== null ? (int)$k['drive_min']  : null;
                $cargo_min = isset($k['cargo_min'])  && $k['cargo_min']  !== null ? (int)$k['cargo_min']  : null;
            }

            // 労働時間
            if ( $drive_min !== null && $cargo_min === null ) {
                $labor_min = $drive_min;
            } elseif ( $drive_min !== null && $cargo_min !== null ) {
                $labor_min = $drive_min + $cargo_min;
            } else {
                $labor_min = $k !== null ? 0 : null;
            }

            // 休憩時間 = 拘束 - 労働
            $break_calc_min = null;
            if ( $kousoku_min !== null && $labor_min !== null ) {
                $break_calc_min = max( 0, $kousoku_min - $labor_min );
            }

            // 日残業 = 労働 > 480 なら 労働 - 480
            $overtime_min = null;
            if ( $labor_min !== null ) {
                $overtime_min = $labor_min > 480 ? $labor_min - 480 : 0;
            }

            // 深夜時間
            $midnight_min = $k ? (int)($k['midnight_min'] ?? 0) : null;

            // 勤怠種別デフォルト
            if ( $k !== null ) $default_kintai = '出勤';
            elseif ( $is_sun ) $default_kintai = '法定休';
            else               $default_kintai = '所定休';

            $rows[] = [
                'date'           => $date_str,
                'dow'            => $dow,
                'is_sun'         => $is_sun,
                'is_sat'         => $is_sat,
                'has_data'       => $k !== null,
                'default_kintai' => $default_kintai,
                'start_time'     => $start_time,
                'end_time'       => $end_time,
                'kousoku_min'    => $kousoku_min,
                'labor_min'      => $labor_min,
                'drive_min'      => $drive_min,
                'cargo_min'      => $cargo_min,
                'break_calc_min' => $break_calc_min,
                'overtime_min'   => $overtime_min,
                'midnight_min'   => $midnight_min,
            ];

            $cursor->modify('+1 day');
        }

        return $rows;
    }

    /* ===============================================================
     * 週次サマリー生成
     *
     * 週は日曜始まり・土曜終わり（7日固定）
     * 第1週：対象月の最初の日曜〜最初の土曜
     *         ただし月初が日曜以外の場合、前週の日曜から始まる
     *         → 前月の繰越データを加算
     * 残業繰越：最終週が土曜で終わらない場合、翌月に繰り越し
     * ============================================================= */
    private function get_weekly_summary( $crew_code, $year_month, $monthly_rows ) {
        global $wpdb;

        $start_date  = new DateTime( $year_month . '-01' );
        $end_date    = new DateTime( date( 'Y-m-t', strtotime( $year_month . '-01' ) ) );

        // --- 前月繰越データ取得 ---
        $carryover = $this->get_carryover( $crew_code, $year_month );
        $carry_labor    = $carryover ? (int)$carryover['labor_min']    : 0;
        $carry_drive    = $carryover ? (int)$carryover['drive_min']    : 0;
        $carry_cargo    = $carryover ? (int)$carryover['cargo_min']    : 0;
        $carry_kousoku  = $carryover ? (int)$carryover['kousoku_min']  : 0;
        $carry_midnight = $carryover ? (int)$carryover['midnight_min'] : 0;
        $carry_days     = $carryover ? (int)$carryover['days']         : 0;

        // --- 日別データを日付キーで索引 ---
        $rows_by_date = [];
        foreach ( $monthly_rows as $r ) {
            $rows_by_date[ $r['date'] ] = $r;
        }

        // --- 月の最初の日曜を求める ---
        // 月初の曜日 (0=Sun, 1=Mon ... 6=Sat)
        $first_dow = (int)$start_date->format('w'); // 0=Sun
        // 月初が日曜なら当日、それ以外は前の日曜へ
        $week_start = clone $start_date;
        if ( $first_dow !== 0 ) {
            $week_start->modify( '-' . $first_dow . ' days' );
        }

        $weeks      = [];
        $week_index = 1;

        while ( $week_start <= $end_date ) {
            $week_end = clone $week_start;
            $week_end->modify( '+6 days' ); // 土曜

            // 週内のうち「対象月に含まれる」日を集計
            $sum = [
                'labor_min'    => 0,
                'drive_min'    => 0,
                'cargo_min'    => 0,
                'kousoku_min'  => 0,
                'midnight_min' => 0,
                'overtime_min' => 0,
                'days'         => 0,
            ];

            // 前月繰越分を第1週に加算
            $is_first_week = ( $week_index === 1 );
            if ( $is_first_week && $carry_days > 0 ) {
                $sum['labor_min']    += $carry_labor;
                $sum['drive_min']    += $carry_drive;
                $sum['cargo_min']    += $carry_cargo;
                $sum['kousoku_min']  += $carry_kousoku;
                $sum['midnight_min'] += $carry_midnight;
                $sum['days']         += $carry_days;
            }

            $cursor = clone $week_start;
            $week_end_actual = min( $week_end, $end_date ); // 月末を超えない

            while ( $cursor <= $week_end_actual ) {
                $d = $cursor->format('Y-m-d');
                // 対象月内のみ集計
                if ( $cursor >= $start_date && $cursor <= $end_date ) {
                    $r = $rows_by_date[ $d ] ?? null;
                    if ( $r && $r['has_data'] ) {
                        $sum['labor_min']    += (int)($r['labor_min']    ?? 0);
                        $sum['drive_min']    += (int)($r['drive_min']    ?? 0);
                        $sum['cargo_min']    += (int)($r['cargo_min']    ?? 0);
                        $sum['kousoku_min']  += (int)($r['kousoku_min']  ?? 0);
                        $sum['midnight_min'] += (int)($r['midnight_min'] ?? 0);
                        $sum['overtime_min'] += (int)($r['overtime_min'] ?? 0);
                    }
                    $sum['days']++;
                }
                $cursor->modify('+1 day');
            }

            // 週が土曜で完結しているか
            $week_ends_on_sat = ( $week_end->format('N') == 6 );
            $week_complete    = ( $week_end <= $end_date ); // 週末が月末以前

            // 週残業 = 週労働 > 2400分 なら超過分
            $week_labor_total = $sum['labor_min'];
            $week_overtime    = $week_labor_total > 2400 ? $week_labor_total - 2400 : 0;

            // 確定残業 = max(日残業合計, 週残業)
            $confirmed_overtime = max( $sum['overtime_min'], $week_overtime );

            // 週が月末をまたぐ（残業繰越）
            $is_carryover = ! $week_complete;

            $label = $is_carryover ? '（残業繰越）' : ( '第' . $week_index . '週計' );

            // 表示上の開始日（対象月内に限定）
            $disp_start = $week_start < $start_date ? clone $start_date : clone $week_start;
            $disp_end   = $week_end   > $end_date   ? clone $end_date   : clone $week_end;

            $weeks[] = [
                'label'              => $label,
                'is_carryover'       => $is_carryover,
                'disp_start'         => $disp_start->format('Y/m/d'),
                'disp_end'           => $disp_end->format('Y/m/d'),
                'days'               => $sum['days'],
                'kousoku_min'        => $sum['kousoku_min'],
                'labor_min'          => $sum['labor_min'],
                'drive_min'          => $sum['drive_min'],
                'cargo_min'          => $sum['cargo_min'],
                'break_min'          => $sum['kousoku_min'] - $sum['labor_min'],
                'day_overtime_min'   => $sum['overtime_min'],
                'week_overtime_min'  => $is_carryover ? null : $week_overtime,
                'confirmed_overtime' => $is_carryover ? null : $confirmed_overtime,
                'midnight_min'       => $sum['midnight_min'],
                'carry_days'         => $is_first_week ? $carry_days : 0,
            ];

            // 繰越データを保存（翌月分として）
            if ( $is_carryover ) {
                $next_month = date( 'Y-m', strtotime( $year_month . '-01 +1 month' ) );
                $this->save_carryover( $crew_code, $next_month, [
                    'labor_min'    => $sum['labor_min'],
                    'drive_min'    => $sum['drive_min'],
                    'cargo_min'    => $sum['cargo_min'],
                    'kousoku_min'  => $sum['kousoku_min'],
                    'midnight_min' => $sum['midnight_min'],
                    'days'         => $sum['days'] - ( $is_first_week ? $carry_days : 0 ),
                ] );
            }

            if ( ! $is_carryover ) $week_index++;
            $week_start->modify('+7 days');
        }

        // --- 月間合計 ---
        $total = [
            'kousoku_min'        => array_sum( array_column( $weeks, 'kousoku_min' ) ),
            'labor_min'          => array_sum( array_column( $weeks, 'labor_min' ) ),
            'drive_min'          => array_sum( array_column( $weeks, 'drive_min' ) ),
            'cargo_min'          => array_sum( array_column( $weeks, 'cargo_min' ) ),
            'day_overtime_min'   => array_sum( array_column( $weeks, 'day_overtime_min' ) ),
            'week_overtime_min'  => array_sum( array_filter( array_column( $weeks, 'week_overtime_min' ), fn($v) => $v !== null ) ),
            'confirmed_overtime' => array_sum( array_filter( array_column( $weeks, 'confirmed_overtime' ), fn($v) => $v !== null ) ),
            'midnight_min'       => array_sum( array_column( $weeks, 'midnight_min' ) ),
            'days'               => array_sum( array_column( $weeks, 'days' ) ),
        ];
        $total['break_min'] = $total['kousoku_min'] - $total['labor_min'];

        return [ 'weeks' => $weeks, 'total' => $total ];
    }

    /* ===============================================================
     * 管理画面レンダリング
     * ============================================================= */
    public function render_page() {
        $result    = $this->get_employees_from_kousoku();
        $employees = $result['employees'];
        $db_error  = $result['error'];

        $selected_crew  = isset($_GET['dr_crew'])  ? sanitize_text_field(wp_unslash($_GET['dr_crew']))  : '';
        $selected_month = isset($_GET['dr_month']) ? sanitize_text_field(wp_unslash($_GET['dr_month'])) : date('Y-m', strtotime('first day of last month'));

        $emp_info     = null;
        $monthly_rows = [];
        $weekly       = null;

        if ( $selected_crew !== '' && $selected_month !== '' ) {
            $emp_info     = $this->get_emp_info_by_crew( $selected_crew );
            $monthly_rows = $this->get_monthly_rows( $selected_crew, $selected_month, $emp_info['name'] );
            $weekly       = $this->get_weekly_summary( $selected_crew, $selected_month, $monthly_rows );
        }

        $kintai_types = self::KINTAI_TYPES;

        include DR_PLUGIN_DIR . 'templates/main-page.php';
    }
}

new Tanpopo_DriverReport();

endif;
