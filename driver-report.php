<?php
/**
 * Plugin Name: 勤怠管理 | 長距離ドライバー
 * Description: 長距離ドライバーの勤怠データを収集・表示・CSV出力するプラグイン
 * Version:     1.3.0
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'DR_VERSION' ) )    define( 'DR_VERSION',    '1.3.0' );
if ( ! defined( 'DR_PLUGIN_DIR' ) ) define( 'DR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'DR_PLUGIN_URL' ) ) define( 'DR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// --- サブクラスの読み込み ---
require_once DR_PLUGIN_DIR . 'includes/class-dr-db.php';
require_once DR_PLUGIN_DIR . 'includes/class-dr-compute.php';
require_once DR_PLUGIN_DIR . 'includes/class-dr-ajax.php';

if ( ! class_exists( 'Tanpopo_DriverReport' ) ) :

class Tanpopo_DriverReport {

    const KINTAI_TYPES = [ '出勤', '法定休', '法定振替休', '所定休', '所定振替休', '有給', '欠勤', '緊急出動' ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'migrate_existing_tables' ] );
        register_activation_hook( __FILE__,  [ $this, 'activate' ] );

        // AJAX フック登録（DR_Ajax 静的メソッドへ委譲）
        add_action( 'wp_ajax_dr_holiday_get_rules',   [ 'DR_Ajax', 'holiday_get_rules' ] );
        add_action( 'wp_ajax_dr_holiday_save_rule',   [ 'DR_Ajax', 'holiday_save_rule' ] );
        add_action( 'wp_ajax_dr_holiday_delete_rule', [ 'DR_Ajax', 'holiday_delete_rule' ] );
        add_action( 'wp_ajax_dr_holiday_toggle_rule', [ 'DR_Ajax', 'holiday_toggle_rule' ] );
        add_action( 'wp_ajax_dr_kintai_save',         [ 'DR_Ajax', 'kintai_save' ] );
    }

    /* ---------------------------------------------------------------
     * format_min() プロキシ（テンプレートからの呼び出し互換用）
     * ------------------------------------------------------------- */
    public static function format_min( $min ) {
        return DR_Compute::format_min( $min );
    }

    /* ---------------------------------------------------------------
     * プラグイン有効化：テーブル作成
     * ------------------------------------------------------------- */
    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // wp_dr_carryover
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}dr_carryover` (
            `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `crew_code`         VARCHAR(20)      NOT NULL,
            `year_month`        CHAR(7)          NOT NULL COMMENT '繰越先の月 YYYY-MM',
            `labor_min`         INT              NOT NULL DEFAULT 0,
            `drive_min`         INT              NOT NULL DEFAULT 0,
            `cargo_min`         INT              NOT NULL DEFAULT 0,
            `kousoku_min`       INT              NOT NULL DEFAULT 0,
            `midnight_min`      INT              NOT NULL DEFAULT 0,
            `overtime_min`      INT              NOT NULL DEFAULT 0,
            `week_overtime_min` INT              NOT NULL DEFAULT 0,
            `days`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_month` (`crew_code`(20), `year_month`)
        ) {$charset};" );

        // wp_dr_holiday_rules
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}dr_holiday_rules` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `affiliation_id` INT UNSIGNED NOT NULL COMMENT 'mst_affiliation.id',
            `day_of_week`    TINYINT      NOT NULL COMMENT '0=日 1=月 2=火 3=水 4=木 5=金 6=土',
            `week_numbers`   VARCHAR(20)  NOT NULL COMMENT '対象週 例: 2,4',
            `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_affil_rule` (`affiliation_id`, `day_of_week`, `week_numbers`)
        ) {$charset};" );

        // wp_dr_kintai_log
        dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}dr_kintai_log` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `crew_code`      VARCHAR(20)  NOT NULL,
            `work_date`      DATE         NOT NULL,
            `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
            `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
            `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_date` (`crew_code`(20), `work_date`)
        ) {$charset};" );
    }

    /* ---------------------------------------------------------------
     * スキーママイグレーション（admin_init で毎回チェック）
     * ------------------------------------------------------------- */
    public function migrate_existing_tables() {
        global $wpdb;

        // dr_carryover カラム追加
        $table = $wpdb->prefix . 'dr_carryover';
        $cols  = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
        if ( ! in_array( 'overtime_min', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `overtime_min` INT NOT NULL DEFAULT 0 AFTER `midnight_min`" );
        }
        if ( ! in_array( 'week_overtime_min', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `week_overtime_min` INT NOT NULL DEFAULT 0 AFTER `overtime_min`" );
        }

        // dr_holiday_rules テーブル補完
        $table2 = $wpdb->prefix . 'dr_holiday_rules';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table2}'" ) ) {
            $this->activate();
        }

        // dr_kintai_log テーブル補完
        $table3 = $wpdb->prefix . 'dr_kintai_log';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table3}'" ) ) {
            $this->activate();
        }
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
        add_submenu_page(
            'driver-report', '集計表示',   '集計表示',
            'manage_options', 'driver-report', [ $this, 'render_page' ]
        );
        add_submenu_page(
            'driver-report', '休日マスタ設定', '休日マスタ設定',
            'manage_options', 'driver-report-holiday', [ $this, 'render_holiday_page' ]
        );
    }

    /* ---------------------------------------------------------------
     * アセット読み込み
     * ------------------------------------------------------------- */
    public function enqueue_assets() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
        if ( ! in_array( $page, [ 'driver-report', 'driver-report-holiday' ], true ) ) return;
        wp_enqueue_style( 'dr-admin', DR_PLUGIN_URL . 'assets/css/admin.css', [], DR_VERSION );
        wp_enqueue_script( 'dr-admin', DR_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], DR_VERSION, true );
        wp_localize_script( 'dr-admin', 'drData', [
            'defaultMonth' => date( 'Y-m', strtotime( 'first day of last month' ) ),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'dr_holiday_nonce' ),
        ] );
    }

    /* ---------------------------------------------------------------
     * 集計表示画面レンダリング
     * ------------------------------------------------------------- */
    public function render_page() {
        $result    = DR_DB::get_employees_from_kousoku();
        $employees = $result['employees'];
        $db_error  = $result['error'];

        $selected_crew  = isset( $_GET['dr_crew'] )  ? sanitize_text_field( wp_unslash( $_GET['dr_crew'] ) )  : '';
        $selected_month = isset( $_GET['dr_month'] ) ? sanitize_text_field( wp_unslash( $_GET['dr_month'] ) ) : date( 'Y-m', strtotime( 'first day of last month' ) );

        $emp_info        = null;
        $monthly_rows    = [];
        $weekly          = null;
        $monthly_summary = null;

        if ( $selected_crew !== '' && $selected_month !== '' ) {
            $emp_info     = DR_DB::get_emp_info_by_crew( $selected_crew );
            $monthly_rows = DR_Compute::get_monthly_rows( $selected_crew, $selected_month, $emp_info['name'] );
            $weekly       = DR_Compute::get_weekly_summary( $selected_crew, $selected_month, $monthly_rows );
            if ( ! empty( $monthly_rows ) ) {
                $monthly_summary = DR_Compute::get_monthly_summary( $monthly_rows, $weekly, $selected_crew, $selected_month );
            }
        }

        $kintai_types = self::KINTAI_TYPES;

        include DR_PLUGIN_DIR . 'templates/main-page.php';
    }

    /* ---------------------------------------------------------------
     * 休日マスタ設定画面レンダリング
     * ------------------------------------------------------------- */
    public function render_holiday_page() {
        global $wpdb;

        if ( function_exists( 'emp_get_affiliations' ) ) {
            $affiliations = emp_get_affiliations();
        } else {
            $affiliations = $wpdb->get_results(
                "SELECT id, name FROM `{$wpdb->prefix}mst_affiliation` WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
            );
        }

        $rules      = DR_DB::get_holiday_rules();
        $dow_labels = [ '日', '月', '火', '水', '木', '金', '土' ];

        include DR_PLUGIN_DIR . 'templates/holiday-page.php';
    }
}

new Tanpopo_DriverReport();

endif;
