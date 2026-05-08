<?php if ( ! defined( 'ABSPATH' ) ) exit;

$page_url = admin_url( 'admin.php?page=driver-report' );
?>

<div class="wrap dr-wrap">

    <div class="dr-page-header">
        <h1 class="dr-page-title">
            <span class="dashicons dashicons-car"></span>
            勤怠管理 | 長距離ドライバー
        </h1>
        <p class="dr-page-desc">拘束時間管理データをもとに、ドライバーごとの月次勤怠データを確認できます。</p>
    </div>

    <?php if ( ! empty( $db_error ) ) : ?>
    <div class="dr-notice dr-notice-error">
        <strong>DBエラー：</strong><?php echo esc_html( $db_error ); ?>
    </div>
    <?php endif; ?>

    <?php if ( empty( $db_error ) && empty( $employees ) ) : ?>
    <div class="dr-notice dr-notice-warn">
        拘束時間管理データ（wp_kousoku_log）に乗務員コードが見つかりませんでした。インポートが完了しているか確認してください。
    </div>
    <?php endif; ?>

    <!-- 検索フォーム（GET 送信） -->
    <div class="dr-card dr-search-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-search"></span>
            集計条件の選択
        </div>
        <div class="dr-card-body">
            <form method="GET" action="<?php echo esc_url( $page_url ); ?>">
                <input type="hidden" name="page" value="driver-report">
                <div class="dr-form-row">

                    <!-- 社員選択 -->
                    <div class="dr-form-group">
                        <label class="dr-label" for="dr-select-crew">社員名</label>
                        <select id="dr-select-crew" name="dr_crew" class="dr-select">
                            <option value="">― 社員を選択 ―</option>
                            <?php foreach ( $employees as $emp ) : ?>
                                <option value="<?php echo esc_attr( $emp['crew_code'] ); ?>"
                                    <?php selected( $selected_crew, $emp['crew_code'] ); ?>>
                                    <?php echo esc_html( $emp['name'] ); ?>
                                    <?php if ( $emp['employee_code'] !== '―' ) : ?>
                                        （<?php echo esc_html( $emp['employee_code'] ); ?>）
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 対象月 -->
                    <div class="dr-form-group">
                        <label class="dr-label" for="dr-select-month">対象月</label>
                        <input
                            type="month"
                            id="dr-select-month"
                            name="dr_month"
                            class="dr-input-month"
                            value="<?php echo esc_attr( $selected_month ); ?>"
                        >
                    </div>

                    <!-- 集計ボタン -->
                    <div class="dr-form-group dr-form-group--btn">
                        <button type="submit" id="dr-btn-open" class="dr-btn dr-btn-primary"
                            <?php echo ( $selected_crew === '' ) ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-chart-bar"></span>
                            集計表を開く
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <?php if ( $emp_info !== null ) : ?>
    <!-- 集計結果エリア -->
    <div class="dr-card dr-result-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-id-alt"></span>
            <?php echo esc_html( $selected_month ); ?>　<?php echo esc_html( $emp_info['name'] ); ?> さんの集計表
        </div>
        <div class="dr-card-body">

            <!-- 社員情報ヘッダーテーブル -->
            <table class="dr-info-table">
                <thead>
                    <tr>
                        <th>社員名</th>
                        <th>社員No.</th>
                        <th>乗務員コード</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $emp_info['name'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['employee_code'] ); ?></td>
                        <td><?php echo esc_html( $emp_info['crew_code'] ); ?></td>
                    </tr>
                </tbody>
            </table>

        </div>
    </div>
    <?php endif; ?>

</div>
