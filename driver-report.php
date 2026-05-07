<?php
/**
 * Plugin Name: 勤怠管理 | 長距離ドライバー
 * Description: 長距離ドライバーの勤怠データを収集・表示・CSV出力するプラグイン
 * Version:     1.0.5
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'DR_VERSION' ) )    define( 'DR_VERSION',    '1.0.5' );
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
     * ドライバー名一覧取得
     * wp_tenrec_daily.entries（JSON）から driver フィールドを収集
     * ------------------------------------------------------------- */
    private function get_drivers() {
        global $wpdb;

        $table = $wpdb->prefix . 'tenrec_daily';
        $rows  = $wpdb->get_col(
            "SELECT entries FROM `{$table}`
             WHERE entries IS NOT NULL AND entries <> '' AND entries <> '[]'"
        );

        $db_error = $wpdb->last_error;
        if ( ! is_array( $rows ) ) $rows = [];

        $drivers = [];
        foreach ( $rows as $json ) {
            $entries = json_decode( $json, true );
            if ( ! is_array( $entries ) ) continue;
            foreach ( $entries as $entry ) {
                $driver = trim( $entry['driver'] ?? '' );
                if ( $driver !== '' ) $drivers[ $driver ] = true;
            }
        }

        $drivers = array_keys( $drivers );
        sort( $drivers, SORT_STRING | SORT_FLAG_CASE );

        return [ 'drivers' => $drivers, 'error' => $db_error ];
    }

    /* ---------------------------------------------------------------
     * driver 名 → wp_emp_master から employee_code / crew_code 取得
     * ------------------------------------------------------------- */
    private function get_emp_info( $driver_name ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT employee_code, crew_code
             FROM {$wpdb->prefix}emp_master
             WHERE name = %s
             LIMIT 1",
            $driver_name
        ), ARRAY_A );

        return $row ?: [ 'employee_code' => '―', 'crew_code' => '―' ];
    }

    /* ---------------------------------------------------------------
     * 管理画面レンダリング
     * ------------------------------------------------------------- */
    public function render_page() {
        global $wpdb;

        // ドライバー名一覧
        $result   = $this->get_drivers();
        $drivers  = $result['drivers'];
        $db_error = $result['error'];

        // フォーム送信値
        $selected_driver = isset( $_GET['dr_driver'] ) ? sanitize_text_field( wp_unslash( $_GET['dr_driver'] ) ) : '';
        $selected_month  = isset( $_GET['dr_month']  ) ? sanitize_text_field( wp_unslash( $_GET['dr_month']  ) ) : date( 'Y-m', strtotime( 'first day of last month' ) );

        // 集計結果
        $emp_info = null;
        if ( $selected_driver !== '' && $selected_month !== '' ) {
            $emp_info = $this->get_emp_info( $selected_driver );
        }

        include DR_PLUGIN_DIR . 'templates/main-page.php';
    }
}

new Tanpopo_DriverReport();

endif;
