<?php
/**
 * Plugin Name: 勤怠管理 | 長距離ドライバー
 * Description: 長距離ドライバーの勤怠データを収集・表示・CSV出力するプラグイン
 * Version:     1.0.2
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DR_VERSION',    '1.0.2' );
define( 'DR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

class Tanpopo_DriverReport {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_dr_get_drivers', [ $this, 'ajax_get_drivers' ] );
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
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'dr_nonce' ),
            'defaultMonth' => date( 'Y-m', strtotime( 'first day of last month' ) ),
        ] );
    }

    public function render_page() {
        include DR_PLUGIN_DIR . 'templates/main-page.php';
    }

    /* ---------------------------------------------------------------
     * AJAX：ドライバー名一覧取得
     * ------------------------------------------------------------- */
    public function ajax_get_drivers() {
        try {
            // nonce 検証
            if ( ! check_ajax_referer( 'dr_nonce', 'nonce', false ) ) {
                wp_send_json_error( [ 'message' => 'nonce検証に失敗しました。ページを再読み込みしてください。' ] );
                return;
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => '権限がありません。' ] );
                return;
            }

            global $wpdb;

            $table   = $wpdb->prefix . 'roll_call_entries';
            $drivers = $wpdb->get_col(
                "SELECT DISTINCT driver
                 FROM `{$table}`
                 WHERE driver IS NOT NULL AND driver <> ''
                 ORDER BY driver ASC"
            );

            if ( $wpdb->last_error ) {
                wp_send_json_error( [ 'message' => 'DBエラー：' . $wpdb->last_error ] );
                return;
            }

            wp_send_json_success( [ 'drivers' => $drivers ? $drivers : [] ] );

        } catch ( Exception $e ) {
            wp_send_json_error( [ 'message' => '例外エラー：' . $e->getMessage() ] );
        }
    }
}

new Tanpopo_DriverReport();
