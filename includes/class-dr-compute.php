<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DR_Compute — 勤怠計算ロジック専用クラス（静的メソッドのみ）
 */
class DR_Compute {

    /* ---------------------------------------------------------------
     * 分 → H:MM 変換（null / '' は空文字返却）
     * ------------------------------------------------------------- */
    public static function format_min( $min ) {
        if ( $min === null || $min === '' ) return '';
        $min = (int) $min;
        if ( $min < 0 ) {
            return '-' . floor( abs($min) / 60 ) . ':' . str_pad( abs($min) % 60, 2, '0', STR_PAD_LEFT );
        }
        return floor( $min / 60 ) . ':' . str_pad( $min % 60, 2, '0', STR_PAD_LEFT );
    }

    /* ---------------------------------------------------------------
     * tenrec entries の最終終業時刻取得
     * ------------------------------------------------------------- */
    private static function get_last_g_time( $entry ) {
        foreach ( [ 'g7_time', 'g5_time', 'g3_time' ] as $key ) {
            $val = trim( $entry[ $key ] ?? '' );
            if ( $val !== '' ) return $val;
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * 月別日次データ生成
     * ------------------------------------------------------------- */
    public static function get_monthly_rows( $crew_code, $year_month, $driver_name ) {
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

        // 所定休日ルール取得
        $affiliation_id = DR_DB::get_affiliation_id_by_crew( $crew_code );
        $all_rules      = DR_DB::get_active_rules_by_affiliation();
        $shitei_rules   = $all_rules[ $affiliation_id ] ?? [];

        // DB保存済み勤怠を取得
        $saved_kintai = DR_DB::get_saved_kintai( $crew_code, $year_month );
        $has_saved    = ! empty( $saved_kintai );

        $dow_ja = [ 'Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土' ];
        $rows   = [];
        $cursor = new DateTime( $start_date );
        $last   = new DateTime( $end_date );

        // ---- パス1：基本データを全日付分生成 ----
        while ( $cursor <= $last ) {
            $date_str = $cursor->format('Y-m-d');
            $dow      = $dow_ja[ $cursor->format('D') ];
            $dow_num  = (int) $cursor->format('w');
            $is_sun   = $dow_num === 0;
            $is_sat   = $dow_num === 6;

            $k = $kousoku_by_date[ $date_str ] ?? null;
            $t = $tenrec_by_date[ $date_str ]  ?? null;

            $start_time = '';
            if ( $t ) $start_time = trim( $t['g1_time'] ?? '' );
            if ( $start_time === '' && $k ) $start_time = substr( $k['start_time'] ?? '', 0, 5 );

            $end_time_raw = '';
            if ( $t ) $end_time_raw = self::get_last_g_time( $t );
            if ( $end_time_raw === '' && $k ) $end_time_raw = substr( $k['end_time'] ?? '', 0, 5 );

            $end_time = $end_time_raw;
            if ( $start_time !== '' && $end_time_raw !== '' ) {
                list( $sh, $sm ) = array_map( 'intval', explode( ':', $start_time ) );
                list( $eh, $em ) = array_map( 'intval', explode( ':', $end_time_raw ) );
                $start_total = $sh * 60 + $sm;
                $end_total   = $eh * 60 + $em;
                if ( $end_total <= $start_total ) {
                    $end_time  = ( $eh + 24 ) . ':' . str_pad( $em, 2, '0', STR_PAD_LEFT );
                    $end_total += 1440;
                }
                $kousoku_min = $end_total - $start_total;
            } else {
                $kousoku_min = null;
            }

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

            $is_shitei_holiday = self::is_shitei_holiday( $date_str, $dow_num, $shitei_rules );

            if ( $k !== null ) {
                $default_kintai = '出勤';
            } elseif ( $is_sun ) {
                $default_kintai = '法定休';
            } elseif ( $is_shitei_holiday ) {
                $default_kintai = '所定休';
            } else {
                $default_kintai = '';
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
                'furikae_label'     => '',
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
            $rows = self::check_alerts_only( $rows );
        } else {
            $rows = self::apply_auto_kintai( $rows );
        }

        // ---- パス3：休日勤務フラグ判定 ----
        $furikae_covered = [];
        foreach ( $rows as $r ) {
            if ( $r['default_kintai'] === '法定振替休' && ! empty( $r['furikae_label'] ) ) {
                $furikae_covered[] = $r['furikae_label'];
            }
        }
        foreach ( $rows as &$r ) {
            $r['kyuujitsu_kinmu'] = false;
            if ( $r['is_sun'] && $r['default_kintai'] === '出勤' ) {
                $expected = date( 'm/d', strtotime( $r['date'] ) ) . '(日)の振替';
                if ( ! in_array( $expected, $furikae_covered, true ) ) {
                    $r['kyuujitsu_kinmu'] = true;
                }
            }
        }
        unset( $r );

        return $rows;
    }

    /* ---------------------------------------------------------------
     * 所定休日フラグ判定
     * ------------------------------------------------------------- */
    private static function is_shitei_holiday( $date_str, $dow_num, $rules ) {
        if ( empty( $rules ) ) return false;
        foreach ( $rules as $rule ) {
            if ( (int)$rule['day_of_week'] !== $dow_num ) continue;
            $week_nums     = array_map( 'intval', explode( ',', $rule['week_numbers'] ) );
            $week_of_month = self::week_of_month_for_dow( $date_str, $dow_num );
            if ( in_array( $week_of_month, $week_nums, true ) ) return true;
        }
        return false;
    }

    /* ---------------------------------------------------------------
     * 指定日が当月における第何番目の同曜日かを返す
     * ------------------------------------------------------------- */
    private static function week_of_month_for_dow( $date_str, $dow_num ) {
        $month_start = substr( $date_str, 0, 7 ) . '-01';
        $count  = 0;
        $cursor = new DateTime( $month_start );
        $target = new DateTime( $date_str );
        while ( $cursor <= $target ) {
            if ( (int)$cursor->format('w') === $dow_num ) $count++;
            $cursor->modify('+1 day');
        }
        return $count;
    }

    /* ---------------------------------------------------------------
     * パス2：自動勤怠種別割当
     * ------------------------------------------------------------- */
    public static function apply_auto_kintai( $rows ) {
        $furikae_warnings = [];

        // ② 所定休 2日超チェック
        $shitei_count = 0;
        foreach ( $rows as &$r ) {
            if ( $r['default_kintai'] === '所定休' ) {
                $shitei_count++;
                if ( $shitei_count > 2 ) $r['default_kintai'] = '';
            }
        }
        unset( $r );

        // ③ 法定振替休割当
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

        // ④ 所定振替休割当
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

        // ⑤ 法定カウント検証
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
                    $final_houtei, $final_houtei_furi, $houtei_total
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

        if ( ! empty( $rows ) ) {
            $rows[0]['_alerts'] = $furikae_warnings;
        }
        return $rows;
    }

    /* ---------------------------------------------------------------
     * DB保存データ表示時：警告チェックのみ（振替割当は行わない）
     * ------------------------------------------------------------- */
    public static function check_alerts_only( $rows ) {
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

    /* ---------------------------------------------------------------
     * 週次サマリー生成
     * ------------------------------------------------------------- */
    public static function get_weekly_summary( $crew_code, $year_month, $monthly_rows ) {
        $month_start_str = $year_month . '-01';
        $month_end_str   = date( 'Y-m-t', strtotime( $month_start_str ) );

        $carryover      = DR_DB::get_carryover( $crew_code, $year_month );
        $carry_labor    = $carryover ? (int)$carryover['labor_min']    : 0;
        $carry_drive    = $carryover ? (int)$carryover['drive_min']    : 0;
        $carry_cargo    = $carryover ? (int)$carryover['cargo_min']    : 0;
        $carry_kousoku  = $carryover ? (int)$carryover['kousoku_min']  : 0;
        $carry_midnight = $carryover ? (int)$carryover['midnight_min'] : 0;
        $carry_days     = $carryover ? (int)$carryover['days']         : 0;
        $carry_overtime      = $carryover ? (int)$carryover['overtime_min']      : 0;
        $carry_week_overtime = $carryover ? (int)$carryover['week_overtime_min'] : 0;

        $rows_by_date = [];
        foreach ( $monthly_rows as $r ) {
            $rows_by_date[ $r['date'] ] = $r;
        }

        $first_dow      = (int) date( 'w', strtotime( $month_start_str ) );
        $week_start_str = date( 'Y-m-d', strtotime( $month_start_str . ' -' . $first_dow . ' days' ) );

        $weeks      = [];
        $week_index = 1;

        // 前月繰越行
        if ( $carry_days > 0 ) {
            $prev_month_end = date( 'Y-m-t', strtotime( $month_start_str . ' -1 month' ) );
            $carry_start    = date( 'Y-m-d', strtotime( $prev_month_end . ' -' . ( $carry_days - 1 ) . ' days' ) );
            $weeks[] = [
                'label'              => '（前月繰越残業）',
                'is_prev_carry'      => true,
                'is_carryover'       => false,
                'disp_start'         => date( 'Y/m/d', strtotime( $carry_start ) ),
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
                'days'         => 0,
            ];

            $is_first_week = ( $week_index === 1 );
            if ( $is_first_week && $carry_days > 0 ) {
                $sum['labor_min']    += $carry_labor;
                $sum['drive_min']    += $carry_drive;
                $sum['cargo_min']    += $carry_cargo;
                $sum['kousoku_min']  += $carry_kousoku;
                $sum['midnight_min'] += $carry_midnight;
            }

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
                        if ( ! ( $r['kyuujitsu_kinmu'] ?? false ) ) {
                            $sum['overtime_min'] += (int)( $r['overtime_min'] ?? 0 );
                        }
                    }
                    $sum['days']++;
                }
                $cursor_str = date( 'Y-m-d', strtotime( $cursor_str . ' +1 day' ) );
            }

            $week_complete = ( $week_end_str <= $month_end_str );
            $is_carryover  = ! $week_complete;

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

            if ( $is_carryover ) {
                $next_month = date( 'Y-m', strtotime( $month_start_str . ' +1 month' ) );
                DR_DB::save_carryover( $crew_code, $next_month, [
                    'labor_min'         => $sum['labor_min']    - ( $is_first_week ? $carry_labor    : 0 ),
                    'drive_min'         => $sum['drive_min']    - ( $is_first_week ? $carry_drive    : 0 ),
                    'cargo_min'         => $sum['cargo_min']    - ( $is_first_week ? $carry_cargo    : 0 ),
                    'kousoku_min'       => $sum['kousoku_min']  - ( $is_first_week ? $carry_kousoku  : 0 ),
                    'midnight_min'      => $sum['midnight_min'] - ( $is_first_week ? $carry_midnight : 0 ),
                    'overtime_min'      => $sum['overtime_min'],
                    'week_overtime_min' => $week_overtime,
                    'days'              => $sum['days'],
                ] );
            }

            if ( ! $is_carryover ) $week_index++;
            $week_start_str = date( 'Y-m-d', strtotime( $week_start_str . ' +7 days' ) );
        }

        // 月間合計（前月繰越行を除く）
        $total = array_fill_keys(
            [ 'kousoku_min','labor_min','drive_min','cargo_min','midnight_min',
              'day_overtime_min','week_overtime_min','confirmed_overtime','days' ], 0
        );
        foreach ( $weeks as $w ) {
            if ( $w['is_prev_carry'] ) continue;
            $total['kousoku_min']        += $w['kousoku_min'];
            $total['labor_min']          += $w['labor_min'];
            $total['drive_min']          += $w['drive_min'];
            $total['cargo_min']          += $w['cargo_min'];
            $total['midnight_min']       += $w['midnight_min'];
            $total['day_overtime_min']   += $w['day_overtime_min'];
            $total['week_overtime_min']  += ( $w['week_overtime_min']  !== null ? $w['week_overtime_min']  : 0 );
            $total['confirmed_overtime'] += ( $w['confirmed_overtime'] !== null ? $w['confirmed_overtime'] : 0 );
            $total['days']               += $w['days'];
        }
        $total['break_min'] = $total['kousoku_min'] - $total['labor_min'];

        return [ 'weeks' => $weeks, 'total' => $total ];
    }

    /* ---------------------------------------------------------------
     * 月間サマリ生成
     * ------------------------------------------------------------- */
    public static function get_monthly_summary( $monthly_rows, $weekly, $crew_code, $year_month ) {
        $attendance   = 0;  // 出勤 + 緊急出動
        $absent       = 0;  // 欠勤
        $holiday_work = 0;  // 休日出勤（振替取得不可の日曜出勤）
        $furikae_min  = 0;  // 振替時間カラム合計（法定振替休・所定振替休 の labor_min）

        foreach ( $monthly_rows as $r ) {
            $kt = $r['default_kintai'] ?? '';
            if ( in_array( $kt, [ '出勤', '緊急出動' ], true ) ) $attendance++;
            if ( $kt === '欠勤' ) $absent++;
            if ( $r['kyuujitsu_kinmu'] ?? false ) $holiday_work++;
            if ( in_array( $kt, [ '法定振替休', '所定振替休' ], true ) && $r['labor_min'] !== null ) {
                $furikae_min += (int) $r['labor_min'];
            }
        }

        $paidleave = DR_DB::get_paidleave_summary( $crew_code, $year_month );

        return [
            'attendance'     => $attendance,
            'absent'         => $absent,
            'holiday_work'   => $holiday_work,
            'paid_consumed'  => $paidleave['consumed'],
            'paid_remaining' => $paidleave['remaining'],
            'paid_has_data'  => $paidleave['has_data'],
            'labor_min'      => $weekly ? $weekly['total']['labor_min']      : null,
            'furikae_min'    => $furikae_min,
            'overtime_min'   => $weekly ? $weekly['total']['confirmed_overtime'] : null,
        ];
    }
}
