<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap dr-wrap">

    <div class="dr-page-header">
        <h1 class="dr-page-title">
            <span class="dashicons dashicons-car"></span>
            勤怠管理 | 長距離ドライバー
        </h1>
        <p class="dr-page-desc">点呼記録をもとに、ドライバーごとの月次勤怠データを確認できます。</p>
    </div>

    <?php if ( ! empty( $db_error ) ) : ?>
    <div class="dr-notice dr-notice-error">
        <strong>DBエラー：</strong><?php echo esc_html( $db_error ); ?>
    </div>
    <?php endif; ?>

    <?php if ( empty( $db_error ) && empty( $drivers ) ) : ?>
    <div class="dr-notice dr-notice-warn">
        点呼記録にドライバーデータが見つかりませんでした。（テーブル：<?php echo esc_html( $wpdb->prefix . 'roll_call_entries' ); ?>）
    </div>
    <?php endif; ?>

    <div class="dr-card dr-search-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-search"></span>
            集計条件の選択
        </div>
        <div class="dr-card-body">
            <div class="dr-form-row">

                <div class="dr-form-group">
                    <label class="dr-label" for="dr-select-driver">ドライバー名</label>
                    <select id="dr-select-driver" class="dr-select">
                        <option value="">― 社員を選択 ―</option>
                        <?php foreach ( $drivers as $driver ) : ?>
                            <option value="<?php echo esc_attr( $driver ); ?>">
                                <?php echo esc_html( $driver ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dr-form-group">
                    <label class="dr-label" for="dr-select-month">対象月</label>
                    <input
                        type="month"
                        id="dr-select-month"
                        class="dr-input-month"
                        value="<?php echo esc_attr( $default_month ); ?>"
                    >
                </div>

                <div class="dr-form-group dr-form-group--btn">
                    <button type="button" id="dr-btn-open" class="dr-btn dr-btn-primary" disabled>
                        <span class="dashicons dashicons-chart-bar"></span>
                        集計表を開く
                    </button>
                </div>

            </div>
        </div>
    </div>

    <div id="dr-result-area" class="dr-result-area" style="display:none;">
        <div class="dr-card">
            <div class="dr-card-header" id="dr-result-title">
                <span class="dashicons dashicons-id-alt"></span>
                集計結果
            </div>
            <div class="dr-card-body" id="dr-result-body">
                <p class="dr-placeholder">（集計表はここに表示されます）</p>
            </div>
        </div>
    </div>

</div>
