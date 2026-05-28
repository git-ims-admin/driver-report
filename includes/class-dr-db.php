<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DR_DB — DB操作専用クラス（静的メソッドのみ）
 */
class DR_DB {

    /* ---------------------------------------------------------------
     * 社員一覧（kousoku_log ベース）
     * ------------------------------------------------------------- */
    public static function get_employees_from_kousoku() {
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

    /* ---------------------------------------------------------------
     * 社員情報（crew_code 指定）
     * ------------------------------------------------------------- */
    public static function get_emp_info_by_crew( $crew_code ) {
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
        return $row ?: [
            'name'             => '（未登録）',
            'employee_code'    => '―',
            'crew_code'        => $crew_code,
            'affiliation_name' => '―',
        ];
    }

    /* ---------------------------------------------------------------
     * affiliation_id 取得（crew_code 指定）
     * ------------------------------------------------------------- */
    public static function get_affiliation_id_by_crew( $crew_code ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT affiliation_id FROM `{$wpdb->prefix}emp_master`
            WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1
        ", $crew_code ) );
    }

    /* ---------------------------------------------------------------
     * 前月繰越データ取得
     * ------------------------------------------------------------- */
    public static function get_carryover( $crew_code, $year_month ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM `{$wpdb->prefix}dr_carryover`
            WHERE `crew_code` = %s AND `year_month` = %s
        ", $crew_code, $year_month ), ARRAY_A );
    }

    /* ---------------------------------------------------------------
     * 前月繰越データ保存（UPSERT）
     * ------------------------------------------------------------- */
    public static function save_carryover( $crew_code, $year_month, $data ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'dr_carryover';
        $existing = self::get_carryover( $crew_code, $year_month );
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

    /* ---------------------------------------------------------------
     * 保存済み勤怠取得（月単位・日付キー）
     * ------------------------------------------------------------- */
    public static function get_saved_kintai( $crew_code, $year_month ) {
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

    /* ---------------------------------------------------------------
     * 全休日ルール取得（所属名付き）
     * ------------------------------------------------------------- */
    public static function get_holiday_rules() {
        global $wpdb;
        return $wpdb->get_results( "
            SELECT r.*, COALESCE( a.name, '（未設定）' ) AS affiliation_name
            FROM `{$wpdb->prefix}dr_holiday_rules` r
            LEFT JOIN `{$wpdb->prefix}mst_affiliation` a ON a.id = r.affiliation_id
            ORDER BY a.sort_order ASC, r.day_of_week ASC
        ", ARRAY_A ) ?: [];
    }

    /* ---------------------------------------------------------------
     * 有効な休日ルールを affiliation_id キーで取得（判定用）
     * ------------------------------------------------------------- */
    public static function get_active_rules_by_affiliation() {
        global $wpdb;
        $rows = $wpdb->get_results( "
            SELECT * FROM `{$wpdb->prefix}dr_holiday_rules`
            WHERE is_active = 1
        ", ARRAY_A ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r['affiliation_id'] ][] = $r;
        }
        return $map;
    }

    /* ---------------------------------------------------------------
     * 有給サマリ取得（paid-leave-manager テーブル参照）
     * 戻り値: [ 'consumed' => float, 'remaining' => float, 'has_data' => bool ]
     * ------------------------------------------------------------- */
    public static function get_paidleave_summary( $crew_code, $year_month ) {
        global $wpdb;

        // crew_code → employee_code
        $employee_code = $wpdb->get_var( $wpdb->prepare(
            "SELECT employee_code FROM `{$wpdb->prefix}emp_master`
             WHERE crew_code COLLATE utf8mb4_unicode_520_ci = %s LIMIT 1",
            $crew_code
        ) );

        if ( ! $employee_code || $employee_code === '―' ) {
            return [ 'consumed' => 0, 'remaining' => 0, 'has_data' => false ];
        }

        // paid-leave-manager テーブルが存在するか確認
        $tbl_cons = $wpdb->prefix . 'paidleave_consumptions';
        $tbl_grant = $wpdb->prefix . 'paidleave_grants';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$tbl_cons}'" ) ) {
            return [ 'consumed' => 0, 'remaining' => 0, 'has_data' => false ];
        }

        // 当月消化日数
        $start    = $year_month . '-01';
        $end      = date( 'Y-m-t', strtotime( $start ) );
        $consumed = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( SUM(consumed_days), 0 )
             FROM `{$tbl_cons}`
             WHERE employee_code = %s AND consumed_date BETWEEN %s AND %s",
            $employee_code, $start, $end
        ) );

        // 修正後
        // ① 月末時点で有効な付与ID一覧
        //    （grant_date <= 月末 かつ expiry_date >= 月末 ＝ まだ失効していない）
        $valid_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM `{$tbl_grant}`
            WHERE employee_code = %s
            AND grant_date  <= %s
            AND expiry_date >= %s",
            $employee_code, $month_end, $month_end
        ) );

        if ( empty( $valid_ids ) ) {
            return [ 'consumed' => $consumed, 'remaining' => 0, 'has_data' => true ];
        }

        $ids_in = implode( ',', array_map( 'intval', $valid_ids ) );

        // ② 有効付与の付与日数合計
        $total_granted = (float) $wpdb->get_var(
            "SELECT COALESCE( SUM(granted_days), 0 ) FROM `{$tbl_grant}` WHERE id IN ({$ids_in})"
        );

        // ③ 月末までの消化日数合計（有効付与分のみ）
        $consumed_to_month_end = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( SUM(consumed_days), 0 )
            FROM `{$tbl_cons}`
            WHERE grant_id IN ({$ids_in}) AND consumed_date <= %s",
            $month_end
        ) );

        $remaining = max( 0, $total_granted - $consumed_to_month_end );

        return [ 'consumed' => $consumed, 'remaining' => $remaining, 'has_data' => true ];
    }
}
