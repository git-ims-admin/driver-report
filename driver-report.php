<?php
/**
 * Plugin Name: 勤怠管理 | 長距離ドライバー
 * Description: 長距離ドライバーの勤怠データを収集・表示・CSV出力するプラグイン
 * Version:     1.0.6
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'DR_VERSION' ) )    define( 'DR_VERSION',    '1.0.6' );
if ( ! defined( 'DR_PLUGIN_DIR' ) ) define( 'DR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'DR_PLUGIN_URL' ) ) define( 'DR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'Tanpopo_DriverReport' ) ) :

class Tanpopo_DriverReport {

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
     * wp_kousoku_log に存在する crew_code を取得し、
     * wp_emp_master と JOIN して社員情報を返す
     *
     * @return array [
     *   'employees' => [ [ 'crew_code', 'name', 'employee_code' ], ... ],
     *   'error'     => string
     * ]
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
            LEFT JOIN `{$emp}` m ON m.crew_code = k.crew_code
            ORDER BY k.crew_code ASC
        ", ARRAY_A );

        $db_error = $wpdb->last_error;

        if ( ! is_array( $rows ) ) $rows = [];

        return [
            'employees' => $rows,
            'error'     => $db_error,
        ];
    }

    /* ---------------------------------------------------------------
     * 選択した crew_code の社員情報を取得
     * ------------------------------------------------------------- */
    private function get_emp_info_by_crew( $crew_code ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT
                name,
                employee_code,
                crew_code
            FROM {$wpdb->prefix}emp_master
            WHERE crew_code = %s
            LIMIT 1
        ", $crew_code ), ARRAY_A );

        // emp_master に未登録の場合でも crew_code だけ返す
        if ( ! $row ) {
            return [
                'name'          => '（未登録）',
                'employee_code' => '―',
                'crew_code'     => $crew_code,
            ];
        }

        return $row;
    }

    /* ---------------------------------------------------------------
     * 管理画面レンダリング
     * ------------------------------------------------------------- */
    public function render_page() {

        // 社員一覧取得
        $result    = $this->get_employees_from_kousoku();
        $employees = $result['employees'];
        $db_error  = $result['error'];

        // フォーム送信値
        $selected_crew  = isset( $_GET['dr_crew']  ) ? sanitize_text_field( wp_unslash( $_GET['dr_crew']  ) ) : '';
        $selected_month = isset( $_GET['dr_month'] ) ? sanitize_text_field( wp_unslash( $_GET['dr_month'] ) ) : date( 'Y-m', strtotime( 'first day of last month' ) );

        // 集計結果
        $emp_info = null;
        if ( $selected_crew !== '' && $selected_month !== '' ) {
            $emp_info = $this->get_emp_info_by_crew( $selected_crew );
        }

        include DR_PLUGIN_DIR . 'templates/main-page.php';
    }
}

new Tanpopo_DriverReport();

endif;
