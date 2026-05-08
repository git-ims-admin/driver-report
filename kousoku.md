# 拘束時間管理 データベース構造定義

> 対象スクリプト：`import_kousoku.php`
> WordPress テーブルプレフィックス `wp_` を前提とする。
> **最終更新：2026年5月**

---

## 1. テーブル関連図

```
employee-manager
────────────────────────
wp_emp_master
  crew_code ◄─────────── wp_kousoku_log.crew_code
                          （FK制約なし・スクリプト側で整合性チェック）
```

---

## 2. `wp_kousoku_log`（拘束時間管理ログ）

xlsxファイル（拘束時間管理表）から毎日AM6時にCronで自動インポートされる日次ログテーブル。

### 基本情報

| 項目 | 内容 |
|------|------|
| エンジン | InnoDB |
| 文字コード | utf8mb4 |
| 主キー | `id` |
| ユニークキー | `crew_code` + `work_date` |
| インポート方式 | UPSERT（追記・冪等） |

### カラム定義

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | INT | NOT NULL | AUTO_INCREMENT | 主キー |
| `crew_code` | VARCHAR(20) | NOT NULL | — | 乗務員コード（= wp_emp_master.crew_code） |
| `work_date` | DATE | NOT NULL | — | 日付 |
| `start_time` | TIME | NOT NULL | — | 始業時刻 |
| `end_time` | TIME | NOT NULL | — | 終業時刻 |
| `end_next_day` | TINYINT | NOT NULL | 0 | 終業翌日フラグ（0=当日, 1=翌日） |
| `drive_min` | SMALLINT | NOT NULL | 0 | 運転時間（分） |
| `drive_overlap_min` | SMALLINT | NULL | NULL | 重複運転時間（分） |
| `cargo_min` | SMALLINT | NULL | NULL | 荷役時間（分） |
| `cargo_overlap_min` | SMALLINT | NULL | NULL | 重複荷役時間（分） |
| `break_min` | SMALLINT | NULL | NULL | 休憩時間（分） |
| `break_overlap_min` | SMALLINT | NULL | NULL | 重複休憩時間（分） |
| `kousoku_subtotal_min` | SMALLINT | NOT NULL | 0 | 拘束時間小計（分） |
| `kousoku_overlap_min` | SMALLINT | NULL | NULL | 重複拘束時間小計（分） |
| `kousoku_total_min` | SMALLINT | NOT NULL | 0 | 拘束時間合計（分）※最大32h |
| `kousoku_cumul_min` | INT | NOT NULL | 0 | 拘束時間累計（分）※最大78h超のためINT |
| `drive_avg_before_min` | SMALLINT | NULL | NULL | 前運転平均（分） |
| `drive_avg_after_min` | SMALLINT | NOT NULL | 0 | 後運転平均（分） |
| `rest_min` | SMALLINT | NULL | NULL | 休息時間（分） |
| `actual_work_min` | SMALLINT | NOT NULL | 0 | 実働時間（分） |
| `overtime_min` | SMALLINT | NULL | NULL | 時間外時間（分） |
| `midnight_min` | SMALLINT | NULL | NULL | 深夜時間（分） |
| `overtime_midnight_min` | SMALLINT | NULL | NULL | 時間外深夜時間（分） |
| `remarks1` | TEXT | NULL | NULL | 摘要1（出発/帰着ルート等） |
| `remarks2` | TEXT | NULL | NULL | 摘要2 |
| `created_at` | DATETIME | NOT NULL | CURRENT_TIMESTAMP | 登録日時 |
| `updated_at` | DATETIME | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新日時 |

### インデックス

| インデックス名 | カラム | 種類 |
|---|---|---|
| PRIMARY | `id` | PRIMARY KEY |
| `uq_crew_date` | `crew_code`, `work_date` | UNIQUE |
| `idx_work_date` | `work_date` | INDEX |
| `idx_crew_code` | `crew_code` | INDEX |

---

## 3. 時間カラムの設計方針

全時間カラムは **分単位の整数** で保存する。

| 理由 | 内容 |
|------|------|
| 24時間超対応 | 拘束時間合計（最大32h）・拘束時間累計（最大78h）はTIME型に収まらない |
| 計算容易性 | 分単位の整数同士の加減算がそのまま使える |
| 型の使い分け | 累計のみ `INT`、それ以外は `SMALLINT`（最大32767分 = 約546h） |

### 表示時の変換例（SQL）

```sql
-- 分 → H:MM 形式で表示
SELECT
    crew_code,
    work_date,
    CONCAT(FLOOR(drive_min / 60), ':', LPAD(drive_min % 60, 2, '0'))
        AS 運転時間,
    CONCAT(FLOOR(actual_work_min / 60), ':', LPAD(actual_work_min % 60, 2, '0'))
        AS 実働時間,
    CONCAT(FLOOR(kousoku_total_min / 60), ':', LPAD(kousoku_total_min % 60, 2, '0'))
        AS 拘束時間合計
FROM wp_kousoku_log
WHERE work_date BETWEEN '2026-05-01' AND '2026-05-31'
ORDER BY crew_code, work_date;
```

---

## 4. xlsxカラムとDBカラムの対応表

| xlsx列 | xlsx列名 | DBカラム | 備考 |
|--------|----------|----------|------|
| A | 乗務員コード | `crew_code` | VARCHAR変換 |
| B | 氏名 | —（取り込まない） | wp_emp_master.name を参照 |
| C | 日付 | `work_date` | Excelシリアル値→DATE変換 |
| D | 始業時刻 | `start_time` | 小数→TIME変換 |
| E | 終業時刻 | `end_time` | 小数→TIME変換・日跨ぎ判定 |
| F | 運転時間 | `drive_min` | 小数→分変換 |
| G | 重複運転時間 | `drive_overlap_min` | 71%がNULL |
| H | 荷役時間 | `cargo_min` | 19%がNULL |
| I | 重複荷役時間 | `cargo_overlap_min` | 91%がNULL |
| J | 休憩時間 | `break_min` | 11%がNULL |
| K | 重複休憩時間 | `break_overlap_min` | 90%がNULL |
| L | 拘束時間小計 | `kousoku_subtotal_min` | — |
| M | 重複拘束時間小計 | `kousoku_overlap_min` | 71%がNULL |
| N | 拘束時間合計 | `kousoku_total_min` | 最大32h |
| O | 拘束時間累計 | `kousoku_cumul_min` | 最大78h超→INT |
| P | 前運転平均 | `drive_avg_before_min` | 74%がNULL |
| Q | 後運転平均 | `drive_avg_after_min` | — |
| R | 休息時間 | `rest_min` | 1%がNULL |
| S | 実働時間 | `actual_work_min` | — |
| T | 時間外時間 | `overtime_min` | 53%がNULL |
| U | 深夜時間 | `midnight_min` | 82%がNULL |
| V | 時間外深夜時間 | `overtime_midnight_min` | 63%がNULL |
| W | 摘要1 | `remarks1` | 出発/帰着ルート等 |
| X | 摘要2 | `remarks2` | 帰着情報等 |

---

## 5. インポートスクリプト仕様

| 項目 | 内容 |
|------|------|
| ファイル名 | `import_kousoku.php` |
| 設置場所 | `/home/xs969605/xs969605.xsrv.jp/script/` |
| 対象xlsxパス | `/home/xs969605/xs969605.xsrv.jp/warehouse/拘束時間管理表.xlsx` |
| ログ出力先 | `/home/xs969605/xs969605.xsrv.jp/script/kousoku_import.log` |
| ライブラリ | PhpSpreadsheet（Composer管理） |
| Cron実行時刻 | 毎日 AM6:00 |
| Cronコマンド | `/usr/bin/php /home/xs969605/xs969605.xsrv.jp/script/import_kousoku.php` |

### インポートの動作仕様

| 条件 | 動作 |
|------|------|
| 始業時刻が空の行 | スキップ（休日/非乗務日） |
| crew_codeがwp_emp_masterに未登録 | WARNログ出力してスキップ |
| crew_code + work_dateが既存 | UPDATE（上書き） |
| crew_code + work_dateが新規 | INSERT |
| 1行のエラー | ERRORログ出力して次行へ継続 |

### 日跨ぎ勤務の扱い

終業時刻が始業時刻より小さい場合（例：始業6:59→終業2:09）を日跨ぎと判定し `end_next_day = 1` を設定する。TIME型は当日の時刻のみ保存し、翌日フラグで補完する。

---

*最終更新：2026年5月*
*対象：import_kousoku.php / wp_kousoku_log*