<?php
/**
 * Plugin Name: 勤怠管理 | 長距離ドライバー
 * Description: 長距離ドライバーの勤怠データを収集・表示・CSV出力するプラグイン
 * Version:     1.1.8
 * Author:      有限会社たんぽぽ運送
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'DR_VERSION' ) )    define( 'DR_VERSION', '1.1.9' );
if ( ! defined( 'DR_PLUGIN_DIR' ) ) define( 'DR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'DR_PLUGIN_URL' ) ) define( 'DR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'Tanpopo_DriverReport' ) ) :

class Tanpopo_DriverReport {

    const KINTAI_TYPES = [ '出勤', '法定休', '法定振替休', '所定休', '所定振替休', '有給', '有給（未承認）', '欠勤', '緊急出動' ];

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'migrate_existing_tables' ] );
        register_activation_hook( __FILE__,  [ $this, 'activate' ] );

        // 休日マスタ AJAX
        add_action( 'wp_ajax_dr_holiday_get_rules',    [ $this, 'ajax_holiday_get_rules' ] );
        add_action( 'wp_ajax_dr_holiday_save_rule',    [ $this, 'ajax_holiday_save_rule' ] );
        add_action( 'wp_ajax_dr_holiday_delete_rule',  [ $this, 'ajax_holiday_delete_rule' ] );
        add_action( 'wp_ajax_dr_holiday_toggle_rule',  [ $this, 'ajax_holiday_toggle_rule' ] );

        // 勤怠種別保存 AJAX
        add_action( 'wp_ajax_dr_kintai_save', [ $this, 'ajax_kintai_save' ] );
    }
    /* ---------------------------------------------------------------
     * プラグイン有効化：wp_dr_carryover テーブル作成
     * ------------------------------------------------------------- */
    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // wp_dr_carryover
        $table = $wpdb->prefix . 'dr_carryover';
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `crew_code`        VARCHAR(20)      NOT NULL,
            `year_month`       CHAR(7)          NOT NULL COMMENT '繰越先の月 YYYY-MM',
            `labor_min`        INT              NOT NULL DEFAULT 0,
            `drive_min`        INT              NOT NULL DEFAULT 0,
            `cargo_min`        INT              NOT NULL DEFAULT 0,
            `kousoku_min`      INT              NOT NULL DEFAULT 0,
            `midnight_min`     INT              NOT NULL DEFAULT 0,
            `overtime_min`     INT              NOT NULL DEFAULT 0,
            `week_overtime_min` INT             NOT NULL DEFAULT 0,
            `days`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_month` (`crew_code`(20), `year_month`)
        ) {$charset};";
        dbDelta( $sql );

        // wp_dr_holiday_rules（所定休日マスタ）
        $table2 = $wpdb->prefix . 'dr_holiday_rules';
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$table2}` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `affiliation_id` INT UNSIGNED NOT NULL COMMENT 'mst_affiliation.id',
            `day_of_week`    TINYINT      NOT NULL COMMENT '0=日 1=月 2=火 3=水 4=木 5=金 6=土',
            `week_numbers`   VARCHAR(20)  NOT NULL COMMENT '対象週 例: 2,4',
            `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_affil_rule` (`affiliation_id`, `day_of_week`, `week_numbers`)
        ) {$charset};";
        dbDelta( $sql2 );

        // wp_dr_kintai_log（勤怠種別保存）
        $table3 = $wpdb->prefix . 'dr_kintai_log';
        $sql3 = "CREATE TABLE IF NOT EXISTS `{$table3}` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `crew_code`      VARCHAR(20)  NOT NULL,
            `work_date`      DATE         NOT NULL,
            `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
            `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
            `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
            `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_crew_date` (`crew_code`(20), `work_date`)
        ) {$charset};";
        dbDelta( $sql3 );
    }
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

        // dr_holiday_rules テーブルが存在しなければ作成
        $table2 = $wpdb->prefix . 'dr_holiday_rules';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table2}'" ) ) {
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $sql = "CREATE TABLE IF NOT EXISTS `{$table2}` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `affiliation_id` INT UNSIGNED NOT NULL COMMENT 'mst_affiliation.id',
                `day_of_week`    TINYINT      NOT NULL COMMENT '0=日 1=月 2=火 3=水 4=木 5=金 6=土',
                `week_numbers`   VARCHAR(20)  NOT NULL COMMENT '対象週 例: 2,4',
                `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
                `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_affil_rule` (`affiliation_id`, `day_of_week`, `week_numbers`)
            ) {$charset};";
            dbDelta( $sql );
        }

        // dr_kintai_log テーブルが存在しなければ作成
        $table3 = $wpdb->prefix . 'dr_kintai_log';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table3}'" ) ) {
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $sql = "CREATE TABLE IF NOT EXISTS `{$table3}` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `crew_code`      VARCHAR(20)  NOT NULL,
                `work_date`      DATE         NOT NULL,
                `kintai_type`    VARCHAR(20)  NOT NULL DEFAULT '',
                `furikae_label`  VARCHAR(30)  NOT NULL DEFAULT '',
                `is_manual`      TINYINT(1)   NOT NULL DEFAULT 0,
                `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_crew_date` (`crew_code`(20), `work_date`)
            ) {$charset};";
            dbDelta( $sql );
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
            'driver-report',
            '集計表示',
            '集計表示',
            'manage_options',
            'driver-report',
            [ $this, 'render_page' ]
        );
        add_submenu_page(
            'driver-report',
            '休日マスタ設定',
            '休日マスタ設定',
            'manage_options',
            'driver-report-holiday',
            [ $this, 'render_holiday_page' ]
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
                COALESCE( m.employee_code, '―'          ) AS employee_code,
                COALESCE( a.id,            0            ) AS affiliation_id,
                COALESCE( a.name,          '未所属'     ) AS affiliation_name
            FROM (
                SELECT DISTINCT crew_code
                FROM `{$wpdb->prefix}kousoku_log`
                WHERE crew_code IS NOT NULL AND crew_code <> ''
            ) k
            LEFT JOIN `{$wpdb->prefix}emp_master` m
                ON m.crew_code COLLATE utf8mb4_unicode_520_ci = k.crew_code COLLATE utf8mb4_unicode_520_ci
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a
                ON a.id = m.affiliation_id
            ORDER BY CAST( COALESCE( NULLIF( m.employee_code, '―' ), '99999' ) AS UNSIGNED ) ASC
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
            SELECT m.name, m.employee_code, m.crew_code,
                   COALESCE( a.name, '未所属' ) AS affiliation_name
            FROM `{$wpdb->prefix}emp_master` m
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a
                ON a.id = m.affiliation_id
            WHERE m.crew_code COLLATE utf8mb4_unicode_520_ci = %s
            LIMIT 1
        ", $crew_code ), ARRAY_A );
        return $row ?: [ 'name' => '（未登録）', 'employee_code' => '―', 'crew_code' => $crew_code, 'affiliation_name' => '―' ];
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

        // tenrec_daily 取得
        $tenrec_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT ymd, entries FROM `{$wpdb->prefix}tenrec_daily`
            WHERE ymd BETWEEN %s AND %s
        ", $ymd_start, $ymd_end ), ARRAY_A );

        $tenrec_by_date = [];
        foreach ( (array) $tenrec_rows as $r ) {
            $ymd     = $r['ymd'];
            $date    = substr($ymd,0,4).'-'.substr($ymd,4,2).'-'.substr($ymd,6,2);
            $entries = json_decode( $r['entries'], true );
            if ( ! is_array( $entries ) ) continue;
            foreach ( $entries as $entry ) {
                if ( trim( $entry['driver'] ?? '' ) === $driver_name ) {
                    $tenrec_by_date[ $date ] = $entry;
                    break;
                }
            }
        }

        // 社員の affiliation_id 取得
        $affiliation_id = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT affiliation_id FROM `{$wpdb->prefix}emp_master`
            WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
            LIMIT 1
        ", $crew_code ) );

        // 所定休日ルール取得
        $all_rules    = $this->get_active_rules_by_affiliation();
        $shitei_rules = $all_rules[ $affiliation_id ] ?? [];

        // DB保存済み勤怠を取得
        $saved_kintai = $this->get_saved_kintai( $crew_code, $year_month );
        $has_saved    = ! empty( $saved_kintai );

        $dow_ja = [ 'Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土' ];
        $rows   = [];
        $cursor = new DateTime( $start_date );
        $last   = new DateTime( $end_date );

        // ---- パス1：基本データを全日付分生成 ----
        while ( $cursor <= $last ) {
            $date_str = $cursor->format('Y-m-d');
            $dow      = $dow_ja[ $cursor->format('D') ];
            $dow_num  = (int) $cursor->format('w'); // 0=日〜6=土
            $is_sun   = $dow_num === 0;
            $is_sat   = $dow_num === 6;

            $k = $kousoku_by_date[ $date_str ] ?? null;
            $t = $tenrec_by_date[ $date_str ]  ?? null;

            $start_time = '';
            if ( $t ) $start_time = trim( $t['g1_time'] ?? '' );
            if ( $start_time === '' && $k ) $start_time = substr( $k['start_time'] ?? '', 0, 5 );

            $end_time = '';
            if ( $t ) $end_time = self::get_last_g_time( $t );
            if ( $end_time === '' && $k ) $end_time = substr( $k['end_time'] ?? '', 0, 5 );

            $kousoku_min = $k ? (int)( $k['kousoku_total_min'] ?? 0 ) : null;

            $drive_min = null;
            $cargo_min = null;
            if ( $k ) {
                $drive_min = isset($k['drive_min']) && $k['drive_min'] !== null ? (int)$k['drive_min'] : null;
                $cargo_min = isset($k['cargo_min']) && $k['cargo_min'] !== null ? (int)$k['cargo_min'] : null;
            }

            if ( $drive_min !== null && $cargo_min === null ) {
                $labor_min = $drive_min;
            } elseif ( $drive_min !== null && $cargo_min !== null ) {
                $labor_min = $drive_min + $cargo_min;
            } else {
                $labor_min = $k !== null ? 0 : null;
            }

            $break_calc_min = null;
            if ( $kousoku_min !== null && $labor_min !== null ) {
                $break_calc_min = max( 0, $kousoku_min - $labor_min );
            }

            $overtime_min = null;
            if ( $labor_min !== null ) {
                $overtime_min = $labor_min > 480 ? $labor_min - 480 : 0;
            }

            $midnight_min = $k ? (int)($k['midnight_min'] ?? 0) : null;

            // 所定休日フラグ判定
            $is_shitei_holiday = $this->is_shitei_holiday( $date_str, $dow_num, $shitei_rules );

            // 暫定勤怠種別（後のパス2で上書き）
            if ( $k !== null ) {
                $default_kintai = '出勤';
            } elseif ( $is_sun ) {
                $default_kintai = '法定休';       // 後で4日超チェック
            } elseif ( $is_shitei_holiday ) {
                $default_kintai = '所定休';       // 後で2日超チェック
            } else {
                $default_kintai = '';             // ---選択---
            }

            $rows[] = [
                'date'              => $date_str,
                'dow'               => $dow,
                'dow_num'           => $dow_num,
                'is_sun'            => $is_sun,
                'is_sat'            => $is_sat,
                'is_shitei_holiday' => $is_shitei_holiday,
                'has_data'          => $k !== null,
                'default_kintai'    => $default_kintai,
                'furikae_label'     => '',         // パス2で設定
                'is_manual'         => false,
                'start_time'        => $start_time,
                'end_time'          => $end_time,
                'kousoku_min'       => $kousoku_min,
                'labor_min'         => $labor_min,
                'drive_min'         => $drive_min,
                'cargo_min'         => $cargo_min,
                'break_calc_min'    => $break_calc_min,
                'overtime_min'      => $overtime_min,
                'midnight_min'      => $midnight_min,
            ];

            $cursor->modify('+1 day');
        }

        // ---- パス2：DB保存データがあれば優先適用、なければ自動判定 ----
        if ( $has_saved ) {
            foreach ( $rows as &$r ) {
                $saved = $saved_kintai[ $r['date'] ] ?? null;
                if ( $saved !== null ) {
                    $r['default_kintai'] = $saved['kintai_type'];
                    $r['furikae_label']  = $saved['furikae_label'];
                    $r['is_manual']      = (bool) $saved['is_manual'];
                }
            }
            unset( $r );
            // 警告チェックのみ実施（振替割当は行わない）
            $rows = $this->check_alerts_only( $rows );
        } else {
            $rows = $this->apply_auto_kintai( $rows );
        }

        return $rows;
    }

    /**
     * 所定休日フラグ判定
     * 指定日が所定休日ルールに該当するか
     */
    private function is_shitei_holiday( $date_str, $dow_num, $rules ) {
        if ( empty( $rules ) ) return false;
        foreach ( $rules as $rule ) {
            if ( (int)$rule['day_of_week'] !== $dow_num ) continue;
            $week_nums = array_map( 'intval', explode( ',', $rule['week_numbers'] ) );
            // その月における第N曜日を計算
            $week_of_month = $this->week_of_month_for_dow( $date_str, $dow_num );
            if ( in_array( $week_of_month, $week_nums, true ) ) return true;
        }
        return false;
    }

    /**
     * 指定日が当月における第何番目の同曜日かを返す
     */
    private function week_of_month_for_dow( $date_str, $dow_num ) {
        $month_start = substr( $date_str, 0, 7 ) . '-01';
        $count = 0;
        $cursor = new DateTime( $month_start );
        $target = new DateTime( $date_str );
        while ( $cursor <= $target ) {
            if ( (int)$cursor->format('w') === $dow_num ) $count++;
            $cursor->modify('+1 day');
        }
        return $count;
    }

    /**
     * パス2：自動勤怠種別割当
     *
     * 処理順：
     * 1. 法定休日（日曜）は日数上限なしで自動割当（4〜5日が正常範囲）
     * 2. 所定休日が2日を超えた分は '' に戻す
     * 3. 出勤した日曜 → 法定振替休を後続の空き日に割当
     * 4. 出勤した所定休日 → 所定振替休を後続の空き日に割当
     * 5. 法定カウント（法定休 + 法定振替休）が 4〜5 の範囲外なら警告
     * 6. 所定休が 2 を超えたら警告
     */
    private function apply_auto_kintai( $rows ) {

        // 日付 → index マップ
        $date_index = [];
        foreach ( $rows as $i => $r ) {
            $date_index[ $r['date'] ] = $i;
        }

        // ① 法定休は上限なし（日曜+データなし → 全て法定休のまま）
        // ※ 最終的に 法定休 + 法定振替休 の合計で 4〜5 を検証する

        // ② 所定休 2日超チェック
        $shitei_count = 0;
        foreach ( $rows as &$r ) {
            if ( $r['default_kintai'] === '所定休' ) {
                $shitei_count++;
                if ( $shitei_count > 2 ) $r['default_kintai'] = '';
            }
        }
        unset( $r );

        // 振替割当の警告リスト（画面表示用）
        $furikae_warnings = [];

        // ③ 法定振替休割当（出勤した日曜 → 後続の空き日）
        foreach ( $rows as $i => $r ) {
            if ( $r['is_sun'] && $r['has_data'] && $r['default_kintai'] === '出勤' ) {
                $assigned = false;
                for ( $j = $i + 1; $j < count( $rows ); $j++ ) {
                    if ( $rows[$j]['default_kintai'] === '' && ! $rows[$j]['has_data'] ) {
                        $rows[$j]['default_kintai'] = '法定振替休';
                        $rows[$j]['furikae_label']  = date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替';
                        $assigned = true;
                        break;
                    }
                }
                if ( ! $assigned ) {
                    $furikae_warnings[] = [
                        'type'    => 'error',
                        'message' => date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替休を割り当てられる日がありません',
                    ];
                }
            }
        }

        // ④ 所定振替休割当（出勤した所定休日 → 後続の空き日）
        foreach ( $rows as $i => $r ) {
            if ( $r['is_shitei_holiday'] && $r['has_data'] && $r['default_kintai'] === '出勤' ) {
                $assigned = false;
                for ( $j = $i + 1; $j < count( $rows ); $j++ ) {
                    if ( $rows[$j]['default_kintai'] === '' && ! $rows[$j]['has_data'] ) {
                        $rows[$j]['default_kintai'] = '所定振替休';
                        $rows[$j]['furikae_label']  = date( 'm/d', strtotime( $r['date'] ) ) . 'の振替';
                        $assigned = true;
                        break;
                    }
                }
                if ( ! $assigned ) {
                    $furikae_warnings[] = [
                        'type'    => 'error',
                        'message' => date( 'm/d', strtotime( $r['date'] ) ) . 'の振替休を割り当てられる日がありません',
                    ];
                }
            }
        }

        // ⑤ 法定カウント検証（法定休 + 法定振替休 の合計が 4〜5 の範囲外なら警告）
        $final_houtei      = 0;
        $final_houtei_furi = 0;
        $final_shitei      = 0;
        foreach ( $rows as $r ) {
            if ( $r['default_kintai'] === '法定休' )     $final_houtei++;
            if ( $r['default_kintai'] === '法定振替休' ) $final_houtei_furi++;
            if ( $r['default_kintai'] === '所定休' )     $final_shitei++;
        }
        $houtei_total = $final_houtei + $final_houtei_furi;

        if ( $houtei_total < 4 || $houtei_total > 5 ) {
            array_unshift( $furikae_warnings, [
                'type'    => 'warn',
                'message' => sprintf(
                    '法定休の合計（法定休%d日＋法定振替休%d日＝%d日）が正常範囲（4〜5日）を外れています。休日の内容を確認してください',
                    $final_houtei,
                    $final_houtei_furi,
                    $houtei_total
                ),
            ] );
        }

        // ⑥ 所定休超過警告
        if ( $final_shitei > 2 ) {
            array_unshift( $furikae_warnings, [
                'type'    => 'warn',
                'message' => '所定休が2日を超えています。所定休以外の日数を確認し休日の内容を変更してください',
            ] );
        }

        // 警告を rows に付加（テンプレートへの受け渡し用に先頭行へ格納）
        if ( ! empty( $rows ) ) {
            $rows[0]['_alerts'] = $furikae_warnings;
        }

        return $rows;
    }

    /**
     * DB保存データ表示時：振替割当は行わず警告チェックのみ実施
     */
    private function check_alerts_only( $rows ) {
        $final_houtei      = 0;
        $final_houtei_furi = 0;
        $final_shitei      = 0;
        $alerts            = [];

        foreach ( $rows as $r ) {
            if ( $r['default_kintai'] === '法定休' )     $final_houtei++;
            if ( $r['default_kintai'] === '法定振替休' ) $final_houtei_furi++;
            if ( $r['default_kintai'] === '所定休' )     $final_shitei++;
        }

        $houtei_total = $final_houtei + $final_houtei_furi;
        if ( $houtei_total < 4 || $houtei_total > 5 ) {
            $alerts[] = [
                'type'    => 'warn',
                'message' => sprintf(
                    '法定休の合計（法定休%d日＋法定振替休%d日＝%d日）が正常範囲（4〜5日）を外れています。休日の内容を確認してください',
                    $final_houtei, $final_houtei_furi, $houtei_total
                ),
            ];
        }
        if ( $final_shitei > 2 ) {
            $alerts[] = [
                'type'    => 'warn',
                'message' => '所定休が2日を超えています。所定休以外の日数を確認し休日の内容を変更してください',
            ];
        }

        if ( ! empty( $rows ) ) {
            $rows[0]['_alerts'] = $alerts;
        }
        return $rows;
    }

    /* ===============================================================
     * 週次サマリー生成
     *
     * 週は日曜始まり・土曜終わり（7日固定）
     * 前月繰越行：carry_days > 0 のときのみ先頭に追加
     * 第1週の日数：当月分のみ（繰越分は含まない）
     * 月間合計：前月繰越行を除いた当月分のみ集計
     * ============================================================= */
    private function get_weekly_summary( $crew_code, $year_month, $monthly_rows ) {

        // 月初・月末（文字列で管理）
        $month_start_str = $year_month . '-01';
        $month_end_str   = date( 'Y-m-t', strtotime( $month_start_str ) );

        // 前月繰越データ取得
        $carryover      = $this->get_carryover( $crew_code, $year_month );
        $carry_labor    = $carryover ? (int)$carryover['labor_min']    : 0;
        $carry_drive    = $carryover ? (int)$carryover['drive_min']    : 0;
        $carry_cargo    = $carryover ? (int)$carryover['cargo_min']    : 0;
        $carry_kousoku  = $carryover ? (int)$carryover['kousoku_min']  : 0;
        $carry_midnight = $carryover ? (int)$carryover['midnight_min'] : 0;
        $carry_days     = $carryover ? (int)$carryover['days']         : 0;

        // 日別データを日付キーで索引
        $rows_by_date = [];
        foreach ( $monthly_rows as $r ) {
            $rows_by_date[ $r['date'] ] = $r;
        }

        // 月初の曜日から第1週の日曜を求める (0=Sun … 6=Sat)
        $first_dow      = (int) date( 'w', strtotime( $month_start_str ) );
        $week_start_str = date( 'Y-m-d', strtotime( $month_start_str . ' -' . $first_dow . ' days' ) );

        $weeks      = [];
        $week_index = 1;

        // -------------------------------------------------------
        // 前月繰越行：carry_days > 0 のときのみ先頭に追加
        // -------------------------------------------------------
        $carry_overtime      = $carryover ? (int)$carryover['overtime_min']      : 0;
        $carry_week_overtime = $carryover ? (int)$carryover['week_overtime_min'] : 0;
        if ( $carry_days > 0 ) {
            $prev_month_end = date( 'Y-m-t', strtotime( $month_start_str . ' -1 month' ) );
            $carry_start    = date( 'Y-m-d', strtotime( $prev_month_end . ' -' . ( $carry_days - 1 ) . ' days' ) );

            $weeks[] = [
                'label'              => '（前月繰越残業）',
                'is_prev_carry'      => true,
                'is_carryover'       => false,
                'disp_start'         => date( 'Y/m/d', strtotime( $carry_start    ) ),
                'disp_end'           => date( 'Y/m/d', strtotime( $prev_month_end ) ),
                'days'               => $carry_days,
                'kousoku_min'        => $carry_kousoku,
                'labor_min'          => $carry_labor,
                'drive_min'          => $carry_drive,
                'cargo_min'          => $carry_cargo,
                'break_min'          => $carry_kousoku - $carry_labor,
                'day_overtime_min'   => $carry_overtime,
                'week_overtime_min'  => $carry_week_overtime,
                'confirmed_overtime' => 0,
                'midnight_min'       => $carry_midnight,
                'carry_days'         => 0,
            ];
        }

        // -------------------------------------------------------
        // 週ループ（文字列比較で確実に月内のみ集計）
        // -------------------------------------------------------
        while ( $week_start_str <= $month_end_str ) {

            $week_end_str = date( 'Y-m-d', strtotime( $week_start_str . ' +6 days' ) );
            $loop_end_str = ( $week_end_str <= $month_end_str ) ? $week_end_str : $month_end_str;

            $sum = [
                'labor_min'    => 0,
                'drive_min'    => 0,
                'cargo_min'    => 0,
                'kousoku_min'  => 0,
                'midnight_min' => 0,
                'overtime_min' => 0,
                'days'         => 0,  // 当月分のみ
            ];

            // 第1週：時間計算に繰越分を加算（日数には加算しない）
            $is_first_week = ( $week_index === 1 );
            if ( $is_first_week && $carry_days > 0 ) {
                $sum['labor_min']    += $carry_labor;
                $sum['drive_min']    += $carry_drive;
                $sum['cargo_min']    += $carry_cargo;
                $sum['kousoku_min']  += $carry_kousoku;
                $sum['midnight_min'] += $carry_midnight;
            }

            // 週内を1日ずつ走査
            $cursor_str = $week_start_str;
            while ( $cursor_str <= $loop_end_str ) {
                if ( $cursor_str >= $month_start_str && $cursor_str <= $month_end_str ) {
                    $r = $rows_by_date[ $cursor_str ] ?? null;
                    if ( $r && $r['has_data'] ) {
                        $sum['labor_min']    += (int)( $r['labor_min']    ?? 0 );
                        $sum['drive_min']    += (int)( $r['drive_min']    ?? 0 );
                        $sum['cargo_min']    += (int)( $r['cargo_min']    ?? 0 );
                        $sum['kousoku_min']  += (int)( $r['kousoku_min']  ?? 0 );
                        $sum['midnight_min'] += (int)( $r['midnight_min'] ?? 0 );
                        $sum['overtime_min'] += (int)( $r['overtime_min'] ?? 0 );
                    }
                    $sum['days']++;
                }
                $cursor_str = date( 'Y-m-d', strtotime( $cursor_str . ' +1 day' ) );
            }

            // 週が月末以前に完結しているか
            $week_complete = ( $week_end_str <= $month_end_str );
            $is_carryover  = ! $week_complete;

            // 週残業 = 週労働（繰越込み）> 2400分 なら超過分
            $week_overtime      = $sum['labor_min'] > 2400 ? $sum['labor_min'] - 2400 : 0;
            $confirmed_overtime = max( $sum['overtime_min'], $week_overtime );

            $label      = $is_carryover ? '（残業繰越）' : ( '第' . $week_index . '週計' );
            $disp_start = max( $week_start_str, $month_start_str );
            $disp_end   = min( $week_end_str,   $month_end_str   );
            
            $net_kousoku  = $sum['kousoku_min']  - ( $is_first_week ? $carry_kousoku  : 0 );
            $net_labor    = $sum['labor_min']    - ( $is_first_week ? $carry_labor    : 0 );
            $net_drive    = $sum['drive_min']    - ( $is_first_week ? $carry_drive    : 0 );
            $net_cargo    = $sum['cargo_min']    - ( $is_first_week ? $carry_cargo    : 0 );
            $net_midnight = $sum['midnight_min'] - ( $is_first_week ? $carry_midnight : 0 );

            $weeks[] = [
                'label'              => $label,
                'is_prev_carry'      => false,
                'is_carryover'       => $is_carryover,
                'disp_start'         => date( 'Y/m/d', strtotime( $disp_start ) ),
                'disp_end'           => date( 'Y/m/d', strtotime( $disp_end   ) ),
                'days'               => $sum['days'],
                'kousoku_min'        => $net_kousoku,
                'labor_min'          => $net_labor,
                'drive_min'          => $net_drive,
                'cargo_min'          => $net_cargo,
                'break_min'          => $net_kousoku - $net_labor,
                'day_overtime_min'   => $sum['overtime_min'],
                'week_overtime_min'  => $is_carryover ? null : $week_overtime,
                'confirmed_overtime' => $is_carryover ? null : $confirmed_overtime,
                'midnight_min'       => $net_midnight,
                'carry_days'         => $is_first_week ? $carry_days : 0,
            ];

            // 翌月繰越データを保存
            if ( $is_carryover ) {
                $next_month = date( 'Y-m', strtotime( $month_start_str . ' +1 month' ) );
                $this->save_carryover( $crew_code, $next_month, [
                    'labor_min'        => $sum['labor_min']    - ( $is_first_week ? $carry_labor    : 0 ),
                    'drive_min'        => $sum['drive_min']    - ( $is_first_week ? $carry_drive    : 0 ),
                    'cargo_min'        => $sum['cargo_min']    - ( $is_first_week ? $carry_cargo    : 0 ),
                    'kousoku_min'      => $sum['kousoku_min']  - ( $is_first_week ? $carry_kousoku  : 0 ),
                    'midnight_min'     => $sum['midnight_min'] - ( $is_first_week ? $carry_midnight : 0 ),
                    'overtime_min'     => $sum['overtime_min'],
                    'week_overtime_min'=> $week_overtime,
                    'days'             => $sum['days'],
                ] );
            }

            if ( ! $is_carryover ) $week_index++;
            $week_start_str = date( 'Y-m-d', strtotime( $week_start_str . ' +7 days' ) );
        }

        // 月間合計：前月繰越行を除いた当月分のみ
        $total_labor    = 0;
        $total_drive    = 0;
        $total_cargo    = 0;
        $total_kousoku  = 0;
        $total_midnight = 0;
        $total_day_ot   = 0;
        $total_week_ot  = 0;
        $total_conf_ot  = 0;
        $total_days     = 0;

        foreach ( $weeks as $w ) {
            if ( $w['is_prev_carry'] ) continue;
            $total_kousoku  += $w['kousoku_min'];
            $total_labor    += $w['labor_min'];
            $total_drive    += $w['drive_min'];
            $total_cargo    += $w['cargo_min'];
            $total_midnight += $w['midnight_min'];
            $total_day_ot   += $w['day_overtime_min'];
            $total_week_ot  += ( $w['week_overtime_min']  !== null ? $w['week_overtime_min']  : 0 );
            $total_conf_ot  += ( $w['confirmed_overtime'] !== null ? $w['confirmed_overtime'] : 0 );
            $total_days     += $w['days'];
        }

        $total = [
            'kousoku_min'        => $total_kousoku,
            'labor_min'          => $total_labor,
            'drive_min'          => $total_drive,
            'cargo_min'          => $total_cargo,
            'break_min'          => $total_kousoku - $total_labor,
            'midnight_min'       => $total_midnight,
            'day_overtime_min'   => $total_day_ot,
            'week_overtime_min'  => $total_week_ot,
            'confirmed_overtime' => $total_conf_ot,
            'days'               => $total_days,
        ];

        return [ 'weeks' => $weeks, 'total' => $total ];
    }

    /* ===============================================================
     * 休日マスタ DB アクセス
     * ============================================================= */

    /** 全ルール取得（所属名付き） */
    private function get_holiday_rules() {
        global $wpdb;
        return $wpdb->get_results( "
            SELECT r.*, COALESCE( a.name, '（未設定）' ) AS affiliation_name
            FROM `{$wpdb->prefix}dr_holiday_rules` r
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a ON a.id = r.affiliation_id
            ORDER BY a.sort_order ASC, r.day_of_week ASC
        ", ARRAY_A ) ?: [];
    }

    /** 有効ルールを affiliation_id キーで取得（判定用） */
    public function get_active_rules_by_affiliation() {
        global $wpdb;
        $rows = $wpdb->get_results( "
            SELECT * FROM `{$wpdb->prefix}dr_holiday_rules`
            WHERE is_active = 1
        ", ARRAY_A ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int)$r['affiliation_id'] ][] = $r;
        }
        return $map;
    }

    /* ===============================================================
     * 休日マスタ AJAX ハンドラー
     * ============================================================= */

    public function ajax_holiday_get_rules() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        wp_send_json_success( $this->get_holiday_rules() );
    }

    public function ajax_holiday_save_rule() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $table        = $wpdb->prefix . 'dr_holiday_rules';
        $id           = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $affil_id     = isset( $_POST['affiliation_id'] ) ? (int) $_POST['affiliation_id'] : 0;
        $day_of_week  = isset( $_POST['day_of_week'] )    ? (int) $_POST['day_of_week']    : 0;
        $week_numbers = isset( $_POST['week_numbers'] )   ? sanitize_text_field( wp_unslash( $_POST['week_numbers'] ) ) : '';

        if ( ! $affil_id || $week_numbers === '' ) {
            wp_send_json_error( [ 'message' => '所属と対象週は必須です' ] );
        }

        $data = [
            'affiliation_id' => $affil_id,
            'day_of_week'    => $day_of_week,
            'week_numbers'   => $week_numbers,
            'is_active'      => 1,
        ];

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
        } else {
            $wpdb->insert( $table, $data );
            $id = $wpdb->insert_id;
        }

        if ( $wpdb->last_error ) {
            wp_send_json_error( [ 'message' => $wpdb->last_error ] );
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    public function ajax_holiday_delete_rule() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $wpdb->delete( $wpdb->prefix . 'dr_holiday_rules', [ 'id' => $id ] );
        wp_send_json_success();
    }

    public function ajax_holiday_toggle_rule() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        global $wpdb;
        $id        = isset( $_POST['id'] )        ? (int) $_POST['id']        : 0;
        $is_active = isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 0;
        $wpdb->update( $wpdb->prefix . 'dr_holiday_rules', [ 'is_active' => $is_active ], [ 'id' => $id ] );
        wp_send_json_success();
    }

    /* ===============================================================
     * 勤怠種別 保存・ロード
     * ============================================================= */

    /** 月分の保存済み勤怠を取得（日付キー） */
    private function get_saved_kintai( $crew_code, $year_month ) {
        global $wpdb;
        $start = $year_month . '-01';
        $end   = date( 'Y-m-t', strtotime( $start ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "
            SELECT work_date, kintai_type, furikae_label, is_manual
            FROM `{$wpdb->prefix}dr_kintai_log`
            WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s
              AND work_date BETWEEN %s AND %s
        ", $crew_code, $start, $end ), ARRAY_A );
        $map = [];
        foreach ( (array) $rows as $r ) {
            $map[ $r['work_date'] ] = $r;
        }
        return $map;
    }

    /** 勤怠種別 AJAX 保存ハンドラー */
    public function ajax_kintai_save() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $table      = $wpdb->prefix . 'dr_kintai_log';
        $crew_code  = isset( $_POST['crew_code'] )  ? sanitize_text_field( wp_unslash( $_POST['crew_code'] ) )  : '';
        $rows_raw   = isset( $_POST['rows'] )       ? wp_unslash( $_POST['rows'] )                              : [];

        if ( ! $crew_code || ! is_array( $rows_raw ) ) {
            wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );
        }

        $saved = 0;
        foreach ( $rows_raw as $row ) {
            $work_date     = sanitize_text_field( $row['date']          ?? '' );
            $kintai_type   = sanitize_text_field( $row['kintai_type']   ?? '' );
            $furikae_label = sanitize_text_field( $row['furikae_label'] ?? '' );
            $is_manual     = (int) ( $row['is_manual'] ?? 0 );

            if ( ! $work_date ) continue;

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$table}`
                    (`crew_code`, `work_date`, `kintai_type`, `furikae_label`, `is_manual`)
                 VALUES (%s, %s, %s, %s, %d)
                 ON DUPLICATE KEY UPDATE
                    `kintai_type`   = VALUES(`kintai_type`),
                    `furikae_label` = VALUES(`furikae_label`),
                    `is_manual`     = VALUES(`is_manual`),
                    `updated_at`    = NOW()",
                $crew_code, $work_date, $kintai_type, $furikae_label, $is_manual
            ) );
            $saved++;
        }

        wp_send_json_success( [ 'saved' => $saved ] );
    }

    /* ===============================================================
     * 休日マスタ設定画面
     * ============================================================= */
    public function render_holiday_page() {
        global $wpdb;

        // 所属マスタ取得（employee-manager 公開API or 直接取得）
        if ( function_exists( 'emp_get_affiliations' ) ) {
            $affiliations = emp_get_affiliations();
        } else {
            $affiliations = $wpdb->get_results(
                "SELECT id, name FROM `{$wpdb->prefix}mst_affiliation` WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
            );
        }

        $rules      = $this->get_holiday_rules();
        $dow_labels = [ '日', '月', '火', '水', '木', '金', '土' ];

        include DR_PLUGIN_DIR . 'templates/holiday-page.php';
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