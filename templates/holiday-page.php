<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap dr-wrap">

    <div class="dr-page-header">
        <h1 class="dr-page-title">
            <span class="dashicons dashicons-calendar-alt"></span>
            休日マスタ設定
        </h1>
        <p class="dr-page-desc">所属ごとの所定休日ルールを設定します。このルールをもとに勤怠の自動判定を行います。</p>
    </div>

    <!-- ルール追加フォーム -->
    <div class="dr-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-plus-alt"></span>
            ルールの追加・編集
        </div>
        <div class="dr-card-body">
            <div class="dr-form-row">
                <div class="dr-form-group">
                    <label class="dr-label" for="hm-affiliation">所属</label>
                    <select id="hm-affiliation" class="dr-select">
                        <option value="">― 所属を選択 ―</option>
                        <?php foreach ( $affiliations as $a ) : ?>
                        <option value="<?php echo esc_attr( $a->id ); ?>"><?php echo esc_html( $a->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="dr-form-group">
                    <label class="dr-label" for="hm-dow">所定休日（曜日）</label>
                    <select id="hm-dow" class="dr-select">
                        <?php foreach ( $dow_labels as $i => $label ) : ?>
                        <option value="<?php echo $i; ?>"><?php echo esc_html( $label ); ?>曜日</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="dr-form-group">
                    <label class="dr-label" for="hm-weeks">対象週（カンマ区切り）</label>
                    <input type="text" id="hm-weeks" class="dr-input-month" placeholder="例: 2,4" style="width:140px;">
                    <small style="color:#666;font-size:11px;">第何週かをカンマ区切りで入力（例：第2・第4週なら 2,4）</small>
                </div>
                <div class="dr-form-group dr-form-group--btn">
                    <button type="button" id="hm-btn-save" class="dr-btn dr-btn-primary">
                        <span class="dashicons dashicons-saved"></span>
                        保存
                    </button>
                    <button type="button" id="hm-btn-cancel" class="dr-btn" style="display:none;background:#aaa;color:#fff;margin-left:8px;">
                        キャンセル
                    </button>
                </div>
            </div>
            <div id="hm-message" style="margin-top:12px;font-size:13px;"></div>
        </div>
    </div>

    <!-- ルール一覧 -->
    <div class="dr-card">
        <div class="dr-card-header">
            <span class="dashicons dashicons-list-view"></span>
            登録済みルール一覧
        </div>
        <div class="dr-card-body" style="padding:0;">
            <div class="dr-table-wrap" style="border:none;border-radius:0;">
                <table class="dr-main-table" id="hm-rule-table">
                    <thead>
                        <tr>
                            <th>所属</th>
                            <th>所定休日（曜日）</th>
                            <th>対象週</th>
                            <th>状態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="hm-rule-tbody">
                    <?php if ( empty( $rules ) ) : ?>
                        <tr><td colspan="5" style="text-align:center;color:#aaa;padding:24px;">登録済みルールはありません</td></tr>
                    <?php else : ?>
                        <?php foreach ( $rules as $rule ) : ?>
                        <tr data-id="<?php echo (int)$rule['id']; ?>">
                            <td><?php echo esc_html( $rule['affiliation_name'] ); ?></td>
                            <td><?php echo esc_html( $dow_labels[ (int)$rule['day_of_week'] ] ); ?>曜日</td>
                            <td>第<?php echo esc_html( implode( '・', explode( ',', $rule['week_numbers'] ) ) ); ?>週</td>
                            <td>
                                <span class="hm-status <?php echo $rule['is_active'] ? 'hm-active' : 'hm-inactive'; ?>">
                                    <?php echo $rule['is_active'] ? '有効' : '無効'; ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="dr-btn hm-btn-edit" style="height:30px;padding:0 12px;font-size:12px;background:#2e6da4;color:#fff;"
                                    data-id="<?php echo (int)$rule['id']; ?>"
                                    data-affil="<?php echo (int)$rule['affiliation_id']; ?>"
                                    data-dow="<?php echo (int)$rule['day_of_week']; ?>"
                                    data-weeks="<?php echo esc_attr( $rule['week_numbers'] ); ?>">
                                    編集
                                </button>
                                <button type="button" class="dr-btn hm-btn-toggle" style="height:30px;padding:0 12px;font-size:12px;background:<?php echo $rule['is_active'] ? '#aaa' : '#2c5f2e'; ?>;color:#fff;margin-left:4px;"
                                    data-id="<?php echo (int)$rule['id']; ?>"
                                    data-active="<?php echo (int)$rule['is_active']; ?>">
                                    <?php echo $rule['is_active'] ? '無効化' : '有効化'; ?>
                                </button>
                                <button type="button" class="dr-btn hm-btn-delete" style="height:30px;padding:0 12px;font-size:12px;background:#d63638;color:#fff;margin-left:4px;"
                                    data-id="<?php echo (int)$rule['id']; ?>">
                                    削除
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.dr-wrap -->

<style>
.hm-active   { color: #2c5f2e; font-weight: 700; }
.hm-inactive { color: #aaa; }
</style>