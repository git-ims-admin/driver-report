<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DR_Ajax — AJAXハンドラー専用クラス（静的メソッドのみ）
 */
class DR_Ajax {

    /* ---------------------------------------------------------------
     * 休日ルール一覧取得
     * ------------------------------------------------------------- */
    public static function holiday_get_rules() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        wp_send_json_success( DR_DB::get_holiday_rules() );
    }

    /* ---------------------------------------------------------------
     * 休日ルール保存（新規 / 更新）
     * ------------------------------------------------------------- */
    public static function holiday_save_rule() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $table        = $wpdb->prefix . 'dr_holiday_rules';
        $id           = isset( $_POST['id'] )             ? (int) $_POST['id']                                          : 0;
        $affil_id     = isset( $_POST['affiliation_id'] ) ? (int) $_POST['affiliation_id']                              : 0;
        $day_of_week  = isset( $_POST['day_of_week'] )    ? (int) $_POST['day_of_week']                                 : 0;
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

    /* ---------------------------------------------------------------
     * 休日ルール削除
     * ------------------------------------------------------------- */
    public static function holiday_delete_rule() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $wpdb->delete( $wpdb->prefix . 'dr_holiday_rules', [ 'id' => $id ] );
        wp_send_json_success();
    }

    /* ---------------------------------------------------------------
     * 休日ルール有効/無効切替
     * ------------------------------------------------------------- */
    public static function holiday_toggle_rule() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        global $wpdb;
        $id        = isset( $_POST['id'] )        ? (int) $_POST['id']        : 0;
        $is_active = isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 0;
        $wpdb->update( $wpdb->prefix . 'dr_holiday_rules', [ 'is_active' => $is_active ], [ 'id' => $id ] );
        wp_send_json_success();
    }

    /* ---------------------------------------------------------------
     * 勤怠種別 AJAX 保存
     * ------------------------------------------------------------- */
    public static function kintai_save() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        global $wpdb;
        $table     = $wpdb->prefix . 'dr_kintai_log';
        $crew_code = isset( $_POST['crew_code'] ) ? sanitize_text_field( wp_unslash( $_POST['crew_code'] ) ) : '';
        $rows_raw  = isset( $_POST['rows'] )      ? wp_unslash( $_POST['rows'] )                            : [];

        if ( ! $crew_code || ! is_array( $rows_raw ) ) {
            wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );
        }

        $saved = 0;
        foreach ( $rows_raw as $row ) {
            $work_date     = sanitize_text_field( $row['date']          ?? '' );
            $kintai_type   = sanitize_text_field( $row['kintai_type']   ?? '' );
            $furikae_label = sanitize_text_field( $row['furikae_label'] ?? '' );
            $is_manual     = (int) ( $row['is_manual']   ?? 0 );
            $hayatai_min   = (int) ( $row['hayatai_min'] ?? 0 );
            $note          = sanitize_text_field( $row['note'] ?? '' );

            if ( ! $work_date ) continue;

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$table}`
                    (`crew_code`, `work_date`, `kintai_type`, `furikae_label`, `is_manual`, `hayatai_min`, `note`)
                 VALUES (%s, %s, %s, %s, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    `kintai_type`   = VALUES(`kintai_type`),
                    `furikae_label` = VALUES(`furikae_label`),
                    `is_manual`     = VALUES(`is_manual`),
                    `hayatai_min`   = VALUES(`hayatai_min`),
                    `note`          = VALUES(`note`),
                    `updated_at`    = NOW()",
                $crew_code, $work_date, $kintai_type, $furikae_label, $is_manual, $hayatai_min, $note
            ) );
            $saved++;
        }

        wp_send_json_success( [ 'saved' => $saved ] );
    }

    /* ---------------------------------------------------------------
     * 月間サマリ取得
     * ------------------------------------------------------------- */
    public static function get_monthly_summary() {
        check_ajax_referer( 'dr_holiday_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $crew_code  = sanitize_text_field( wp_unslash( $_POST['crew_code']  ?? '' ) );
        $year_month = sanitize_text_field( wp_unslash( $_POST['year_month'] ?? '' ) );

        if ( ! $crew_code || ! $year_month ) {
            wp_send_json_error( [ 'message' => 'パラメータが不正です' ] );
        }

        $emp_info     = DR_DB::get_emp_info_by_crew( $crew_code );
        $monthly_rows = DR_Compute::get_monthly_rows( $crew_code, $year_month, $emp_info['name'] );
        $weekly       = DR_Compute::get_weekly_summary( $crew_code, $year_month, $monthly_rows );
        $summary      = DR_Compute::get_monthly_summary( $monthly_rows, $weekly, $crew_code, $year_month );

        // format_min 済みの表示値も合わせて返す
        $summary['labor_str']    = DR_Compute::format_min( $summary['labor_min'] );
        $summary['hayatai_str']  = $summary['hayatai_min'] > 0 ? DR_Compute::format_min( $summary['hayatai_min'] ) : '';
        $summary['overtime_str'] = DR_Compute::format_min( $summary['overtime_min'] );

        wp_send_json_success( $summary );
    }
}