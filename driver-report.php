<?php
/**
 * Plugin Name: 勤怠管理 | 長距離ドライバー
 * Description: 長距離ドライバーの勤怠データを収集・表示・CSV出力するプラグイン
 * Version:     1.0.8
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'DR_VERSION' ) )    define( 'DR_VERSION',    '1.0.8' );
if ( ! defined( 'DR_PLUGIN_DIR' ) ) define( 'DR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'DR_PLUGIN_URL' ) ) define( 'DR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'Tanpopo_DriverReport' ) ) :

class Tanpopo_DriverReport {

    /** 勤怠種別の選択肢 */
    const KINTAI_TYPES = [ '出勤', '法定休', '所定休', '年休', '振替休', '振替出勤', '緊急出勤' ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

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

    public function enqueue_assets() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
        if ( $page !== 'driver-report' ) return;

        wp_enqueue_style( 'dr-admin', DR_PLUGIN_URL . 'assets/css/admin.css', [], DR_VERSION );
        wp_enqueue_script( 'dr-admin', DR_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], DR_VERSION, true );
        wp_localize_script( 'dr-admin', 'drData', [
            'defaultMonth' => date( 'Y-m', strtotime( 'first day of last month' ) ),
        ] );
    }

    /* ---------------------------------------------------------------
     * 分 → H:MM 形式に変換
     * ------------------------------------------------------------- */
    public static function format_min( $min ) {
        if ( $min === null || $min === '' ) return '';
        $min = (int) $min;
        if ( $min < 0 ) return '';
        return floor( $min / 60 ) . ':' . str_pad( $min % 60, 2, '0', STR_PAD_LEFT );
    }

    /* ---------------------------------------------------------------
     * TIME文字列 (HH:MM:SS or HH:MM) → 分に変換
     * ------------------------------------------------------------- */
    private static function time_to_min( $time ) {
        if ( empty( $time ) ) return null;
        $parts = explode( ':', $time );
        return (int)$parts[0] * 60 + (int)( $parts[1] ?? 0 );
    }

    /* ---------------------------------------------------------------
     * wp_kousoku_log に存在する crew_code から社員一覧を取得
     * ------------------------------------------------------------- */
    private function get_employees_from_kousoku() {
        global $wpdb;

        $kousoku = $wpdb->prefix . 'kousoku_log';
        $emp     = $wpdb->prefix . 'emp_master';

        $rows = $wpdb->get_results( "
            SELECT
                k.crew_code,
                COALESCE( m.name,          '（未登録）' ) AS name,
                COALESCE( m.employee_code, '―'          ) AS employee_code
            FROM (
                SELECT DISTINCT crew_code
                FROM `{$kousoku}`
                WHERE crew_code IS NOT NULL AND crew_code <> ''
            ) k
            LEFT JOIN `{$emp}` m
                ON m.crew_code COLLATE utf8mb4_unicode_520_ci = k.crew_code COLLATE utf8mb4_unicode_520_ci
            ORDER BY k.crew_code ASC
        ", ARRAY_A );

        return [
            'employees' => is_array( $rows ) ? $rows : [],
            'error'     => $wpdb->last_error,
        ];
    }

    /* ---------------------------------------------------------------
     * crew_code で社員情報を取得
     * ------------------------------------------------------------- */
    private function get_emp_info_by_crew( $crew_code ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT name, employee_code, crew_code
            FROM {$wpdb->prefix}emp_master
            WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
            LIMIT 1
        ", $crew_code ), ARRAY_A );

        return $row ?: [
            'name'          => '（未登録）',
            'employee_code' => '―',
            'crew_code'     => $crew_code,
        ];
    }

    /* ---------------------------------------------------------------
     * tenrec entries の gX から「最終終業時刻」を取得
     * 1業務: g3_time / 2業務: g5_time / 3業務: g7_time
     * ------------------------------------------------------------- */
    private static function get_last_g_time( $entry ) {
        foreach ( [ 'g7_time', 'g5_time', 'g3_time' ] as $key ) {
            $val = trim( $entry[ $key ] ?? '' );
            if ( $val !== '' ) return $val;
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * 月次勤怠データを生成
     * 対象月の全日分をループし、kousoku_log + tenrec_daily を合成
     * ------------------------------------------------------------- */
    private function get_monthly_rows( $crew_code, $year_month ) {
        global $wpdb;

        $emp         = $this->get_emp_info_by_crew( $crew_code );
        $driver_name = $emp['name'];

        // --- 日付範囲 ---
        $start_date = $year_month . '-01';
        $end_date   = date( 'Y-m-t', strtotime( $start_date ) );
        $ymd_start  = str_replace( '-', '', $start_date );
        $ymd_end    = str_replace( '-', '', $end_date );

        // --- wp_kousoku_log 取得（crew_code + 月範囲） ---
        $kousoku_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT *
            FROM {$wpdb->prefix}kousoku_log
            WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
              AND work_date BETWEEN %s AND %s
            ORDER BY work_date ASC
        ", $crew_code, $start_date, $end_date ), ARRAY_A );

        $kousoku_by_date = [];
        foreach ( (array) $kousoku_rows as $r ) {
            $kousoku_by_date[ $r['work_date'] ] = $r;
        }

        // --- wp_tenrec_daily 取得（月範囲の entries を JSON パース） ---
        $tenrec_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT ymd, entries
            FROM {$wpdb->prefix}tenrec_daily
            WHERE ymd BETWEEN %s AND %s
        ", $ymd_start, $ymd_end ), ARRAY_A );

        $tenrec_by_date = [];
        foreach ( (array) $tenrec_rows as $r ) {
            $ymd  = $r['ymd'];
            $date = substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 );
            $entries = json_decode( $r['entries'], true );
            if ( ! is_array( $entries ) ) continue;
            foreach ( $entries as $entry ) {
                if ( trim( $entry['driver'] ?? '' ) === $driver_name ) {
                    $tenrec_by_date[ $date ] = $entry;
                    break;
                }
            }
        }

        // --- 日本語曜日 ---
        $dow_ja = [ 'Sun'=>'日', 'Mon'=>'月', 'Tue'=>'火', 'Wed'=>'水', 'Thu'=>'木', 'Fri'=>'金', 'Sat'=>'土' ];

        // --- 1日ずつ生成 ---
        $rows   = [];
        $cursor = new DateTime( $start_date );
        $last   = new DateTime( $end_date );

        while ( $cursor <= $last ) {
            $date_str = $cursor->format( 'Y-m-d' );
            $dow      = $dow_ja[ $cursor->format( 'D' ) ];
            $is_sun   = $cursor->format( 'N' ) == 7;
            $is_sat   = $cursor->format( 'N' ) == 6;

            $k = $kousoku_by_date[ $date_str ] ?? null;
            $t = $tenrec_by_date[ $date_str ]  ?? null;

            // --- 始業時刻：tenrec g1_time を優先、なければ kousoku start_time ---
            $start_time = '';
            if ( $t ) {
                $start_time = trim( $t['g1_time'] ?? '' );
            }
            if ( $start_time === '' && $k ) {
                // TIME型 (HH:MM:SS) → HH:MM
                $start_time = substr( $k['start_time'] ?? '', 0, 5 );
            }

            // --- 終業時刻：tenrec 最終 gX_time を優先、なければ kousoku end_time ---
            $end_time = '';
            if ( $t ) {
                $end_time = self::get_last_g_time( $t );
            }
            if ( $end_time === '' && $k ) {
                $end_time = substr( $k['end_time'] ?? '', 0, 5 );
            }

            // --- 拘束時間（分）---
            // kousoku_total_min を使用（日跨ぎ対応済みの値）
            $kousoku_min = $k ? (int)( $k['kousoku_total_min'] ?? 0 ) : null;

            // 拘束時間が0かつ kousoku レコードなし → null 扱い
            if ( $k === null ) $kousoku_min = null;

            // --- drive_min / cargo_min ---
            $drive_min = null;
            $cargo_min = null;
            if ( $k ) {
                $drive_min = isset( $k['drive_min'] ) && $k['drive_min'] !== null
                    ? (int) $k['drive_min'] : null;
                $cargo_min = isset( $k['cargo_min'] ) && $k['cargo_min'] !== null
                    ? (int) $k['cargo_min'] : null;
            }

            // --- 労働時間（分）= 運転＋積卸、どちらも null なら 0 ---
            if ( $drive_min !== null && $cargo_min === null ) {
                $labor_min = $drive_min;
            } elseif ( $drive_min !== null && $cargo_min !== null ) {
                $labor_min = $drive_min + $cargo_min;
            } else {
                $labor_min = ( $k !== null ) ? 0 : null;
            }

            // --- 休憩時間（分）= 拘束 - 労働 ---
            $break_calc_min = null;
            if ( $kousoku_min !== null && $labor_min !== null ) {
                $break_calc_min = $kousoku_min - $labor_min;
                if ( $break_calc_min < 0 ) $break_calc_min = 0;
            }

            // --- 日残業（分）= 労働 > 480 なら 労働 - 480 ---
            $overtime_min = null;
            if ( $labor_min !== null ) {
                $overtime_min = $labor_min > 480 ? $labor_min - 480 : 0;
            }

            // --- 深夜時間 ---
            $midnight_min = null;
            if ( $k ) {
                $midnight_min = isset( $k['midnight_min'] ) && $k['midnight_min'] !== null
                    ? (int) $k['midnight_min'] : 0;
            }

            // --- 勤怠種別 デフォルト ---
            if ( $k !== null ) {
                $default_kintai = '出勤';
            } elseif ( $is_sun ) {
                $default_kintai = '法定休';
            } elseif ( $is_sat ) {
                $default_kintai = '所定休';
            } else {
                $default_kintai = '所定休';
            }

            $rows[] = [
                'date'            => $date_str,
                'dow'             => $dow,
                'is_sun'          => $is_sun,
                'is_sat'          => $is_sat,
                'has_data'        => $k !== null,
                'default_kintai'  => $default_kintai,
                'start_time'      => $start_time,
                'end_time'        => $end_time,
                'kousoku_min'     => $kousoku_min,
                'labor_min'       => $labor_min,
                'drive_min'       => $drive_min,
                'cargo_min'       => $cargo_min,
                'break_calc_min'  => $break_calc_min,
                'overtime_min'    => $overtime_min,
                'midnight_min'    => $midnight_min,
            ];

            $cursor->modify( '+1 day' );
        }

        return $rows;
    }

    /* ---------------------------------------------------------------
     * 管理画面レンダリング
     * ------------------------------------------------------------- */
    public function render_page() {
        $result    = $this->get_employees_from_kousoku();
        $employees = $result['employees'];
        $db_error  = $result['error'];

        $selected_crew  = isset( $_GET['dr_crew']  ) ? sanitize_text_field( wp_unslash( $_GET['dr_crew']  ) ) : '';
        $selected_month = isset( $_GET['dr_month'] ) ? sanitize_text_field( wp_unslash( $_GET['dr_month'] ) ) : date( 'Y-m', strtotime( 'first day of last month' ) );

        $emp_info    = null;
        $monthly_rows = [];

        if ( $selected_crew !== '' && $selected_month !== '' ) {
            $emp_info     = $this->get_emp_info_by_crew( $selected_crew );
            $monthly_rows = $this->get_monthly_rows( $selected_crew, $selected_month );
        }

        $kintai_types = self::KINTAI_TYPES;

        include DR_PLUGIN_DIR . 'templates/main-page.php';
    }
}

new Tanpopo_DriverReport();

endif;
