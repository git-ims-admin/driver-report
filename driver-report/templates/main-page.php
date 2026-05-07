<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap dr-wrap">

    <!-- ページヘッダー -->
    <div class="dr-page-header">
        <h1 class="dr-page-title">
            <span class="dashicons dashicons-car"></span>
            勤怠管理 | 長距離ドライバー
        </h1>
        <p class="dr-page-desc">点呼記録をもとに、ドライバーごとの月次勤怠データを確認できます。</p>
    </div>

    <!-- 検索フォーム -->
    <div class="dr-card dr-search-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-search"></span>
            集計条件の選択
        </div>
        <div class="dr-card-body">
            <div class="dr-form-row">

                <!-- 社員選択 -->
                <div class="dr-form-group">
                    <label class="dr-label" for="dr-select-driver">ドライバー名</label>
                    <select id="dr-select-driver" class="dr-select">
                        <option value="">― 読み込み中… ―</option>
                    </select>
                </div>

                <!-- 対象月 -->
                <div class="dr-form-group">
                    <label class="dr-label" for="dr-select-month">対象月</label>
                    <input
                        type="month"
                        id="dr-select-month"
                        class="dr-input-month"
                        value=""
                    >
                </div>

                <!-- 集計ボタン -->
                <div class="dr-form-group dr-form-group--btn">
                    <button type="button" id="dr-btn-open" class="dr-btn dr-btn-primary" disabled>
                        <span class="dashicons dashicons-chart-bar"></span>
                        集計表を開く
                    </button>
                </div>

            </div><!-- /.dr-form-row -->

            <!-- エラーメッセージ表示エリア -->
            <div id="dr-load-error" class="dr-notice dr-notice-error" style="display:none;"></div>
        </div><!-- /.dr-card-body -->
    </div><!-- /.dr-search-card -->

    <!-- 集計結果エリア（初期は非表示） -->
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

</div><!-- /.dr-wrap -->
