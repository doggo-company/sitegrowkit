<?php

// Agent CMS Core — 生成プロジェクトはこのファイルを直接編集せず .env で上書きする
return [

    // Content Schema 定義ディレクトリ（Schema First の単一情報源）。
    // env で差し替えられるのは **隔離テストのため**であり、通常は既定のままでよい
    'schema_path' => env('AGENTCMS_SCHEMA_PATH') ?: base_path('content/schemas'),

    // Project 層のモジュール（Modules\<Name> → modules/<Name>）。
    // Content Type Builder（Phase 9.4）がモデルと migration を生成する先でもある
    'modules_path' => env('AGENTCMS_MODULES_PATH') ?: base_path('modules'),

    // PageDocument の component 定義（Phase 9.1D）。project が JSON を足せば component が増える
    'component_path' => base_path('content/components'),

    // Design Token（Phase 9.1D）。値の変更に build / deploy を要求しない
    'theme_path' => base_path('content/theme.json'),

    // code-managed taxonomy の定義（Phase 9.3B）。**runtime ULID は書かない**。
    // DB への反映は `php artisan agentcms:taxonomy-sync` の明示実行だけが行う
    'taxonomy_path' => base_path('content/taxonomies'),

    // AI 向け索引（Phase 9.3B / STAGE 3C）。**consumer の正は content/schemas の
    // taxonomies[] であり、この JSON は索引**。`agentcms:taxonomy-check` が
    // 両者の乖離を read-only で検出する（自動修正はしない）。
    // 見るのは **実行中プロジェクト自身**の .agentcms（kit の canonical ではない）
    'taxonomy_registry_path' => base_path('.agentcms/registry/taxonomies.json'),

    // 分類の参照調査（Phase 9.3B / STAGE 2）に使える時間（ミリ秒）。
    // **件数ではなく時間で上限を持つ**。「版が多いから恒久的に削除不能」を作らないため。
    // 超過時は「参照あり」ではなく「確定できなかった」として削除を止める（§I.3）
    'taxonomy_reference_budget_ms' => (int) env('AGENTCMS_TAXONOMY_REFERENCE_BUDGET_MS', 5000),

    /*
     * 監査ログの保持（Phase WP-BASELINE / STAGE #18A）。
     *
     * IP と User-Agent は個人情報なので先に匿名化し、行そのものはより長く残す。
     * **0 以下にするとその段階は何もしない**（既存ログを不用意に消さない）。
     * 整理は `php artisan agentcms:audit-prune` の明示実行だけが行う。
     */
    'audit' => [
        'retention' => [
            'anonymize_after_days' => (int) env('AGENTCMS_AUDIT_ANONYMIZE_AFTER_DAYS', 90),
            'delete_after_days' => (int) env('AGENTCMS_AUDIT_DELETE_AFTER_DAYS', 365),
        ],
    ],

    /*
     * ページの自動保存で増えた版を、いくつ残すか（Phase 9.6 / SPEC §4.11・Owner Gate V8）。
     *
     * 取り消しの履歴は画面を閉じると消えるので、版は取り消しのために在るのではなく
     * **事故のあとに戻れる最後の手段**である。ここが決めているのは
     * **「事故で失ってよい作業量」**であって、操作の快適さではない。
     *
     * 間引くのは `agentcms:page-version-prune` だけで、保存の経路では走らない。
     */
    'pages' => [
        'autosave_keep' => (int) env('AGENTCMS_PAGE_AUTOSAVE_KEEP', 50),
    ],

    /*
     * 記事の自動保存（Phase 9.15 / STAGE 28B・28C・SPEC §C.4）。
     *
     * **決めているのは「事故で失ってよい作業量 = 10 分」**であって、件数ではない。
     * 20 = 30 秒間隔で 10 分ぶん。**間隔を変えたら、ここも変える。**
     *
     * ページ側の 50 を写さないのは、**引き金が違えば増え方が違う**からである
     * （ページは変更のたび、記事は間隔ごと）。同じ数に意味が無い。
     */
    'content' => [
        'autosave_keep' => (int) env('AGENTCMS_CONTENT_AUTOSAVE_KEEP', 20),
    ],

    /*
     * 404 の記録（Phase 9.10 / P0-5・SPEC §F）。
     *
     * 既定で記録する（OG11）。記録が無いと「何が壊れているか」が分からない。
     * 個人情報は保持期間で段階的に消す（監査ログと同じ作法）。
     * **自動では走らない。** `php artisan agentcms:redirect-prune` の明示実行だけが整理する。
     */
    'not_found' => [
        'enabled' => (bool) env('AGENTCMS_NOT_FOUND_LOG', true),
        // bot は存在しない path を無限に作れる。表の大きさを予測可能にする（OG9）
        'max_rows' => (int) env('AGENTCMS_NOT_FOUND_MAX_ROWS', 10000),
        'retention' => [
            // referrer / user-agent / ip_hash を null にする
            'anonymize_after_days' => (int) env('AGENTCMS_NOT_FOUND_ANONYMIZE_AFTER_DAYS', 30),
            // 直したものは早く消す
            'delete_resolved_after_days' => (int) env('AGENTCMS_NOT_FOUND_DELETE_RESOLVED_AFTER_DAYS', 90),
            'delete_after_days' => (int) env('AGENTCMS_NOT_FOUND_DELETE_AFTER_DAYS', 365),
        ],
    ],

    /*
     * backup（Phase 9.11 / P0-6・SPEC §F）。
     *
     * **保存先は設定で変えられない。** `storage/app/private/backups` 固定である
     * （public 配下に落ちると URL 推測だけで全データが持ち去られる — SPEC §G.1）。
     *
     * **自動では走らない**のが既定。`schedule` に時刻を入れたときだけ、毎日その時刻に
     * `agentcms:backup-auto` が取る（サーバーの cron で schedule:run が動いていること）。
     * `offsite` を設定すると、取った zip の写しを別の置き場所へ送る（手元の置き場所は変わらない）。
     */
    'backup' => [
        'keep' => (int) env('AGENTCMS_BACKUP_KEEP', 5),
        // 日数での整理。0 は何もしない（既存の backup を不用意に消さない）
        'keep_days' => (int) env('AGENTCMS_BACKUP_KEEP_DAYS', 0),
        /*
         * 画面から始めた取得の「止まった」判定（2026-09-09・OG3 改訂）。
         *
         * 別プロセスが `run_start_timeout` 秒たっても始まらない、
         * または `run_stale_after` 秒 heartbeat が無ければ失敗として画面に出す。
         * **無限の「処理中」を作らないための上限**であり、取得そのものの上限ではない。
         */
        'run_start_timeout' => (int) env('AGENTCMS_BACKUP_RUN_START_TIMEOUT', 120),
        'run_stale_after' => (int) env('AGENTCMS_BACKUP_RUN_STALE_AFTER', 600),
        // 毎日の自動取得の時刻（HH:MM・アプリの時刻帯）。空なら自動では取らない
        'schedule' => (string) env('AGENTCMS_BACKUP_SCHEDULE', ''),
        /*
         * 別の置き場所（offsite）。`disk` は config/filesystems.php の disk 名。空なら送らない。
         * 1 つの置き場所を複数サイトで使うときは、`prefix` をサイトごとに変える
         * （空なら APP_URL の host から作る。localhost のままなら送らない）。`keep` は外に残す世代で、手元の `keep` とは別に数える。
         */
        /*
         * 自動 backup が失敗したときに知らせるメールの宛先（カンマ区切りで複数）。空なら送らない。
         * 送るのはサイトのメール設定（MAIL_*）で。失敗は宛先が空でも、状態の表示
         * （`agentcms:backup-offsite --list` が exit 1）と管理画面のバックアップ画面に出る。
         */
        'alert_mail' => (string) env('AGENTCMS_BACKUP_ALERT_MAIL', ''),
        'offsite' => [
            'disk' => (string) env('AGENTCMS_BACKUP_OFFSITE_DISK', ''),
            'prefix' => (string) env('AGENTCMS_BACKUP_OFFSITE_PREFIX', ''),
            'keep' => (int) env('AGENTCMS_BACKUP_OFFSITE_KEEP', 14),
        ],
    ],

    /*
     * フォーム（Phase 9.12 / P0-8・SPEC §F.3・§G.2）。
     *
     * **受信そのものは既定で消さない**（OG11）。監査ログや 404 の記録と違い、
     * これは顧客とのやり取りそのものである。消す判断は運用が持つ。
     * 整理は `php artisan agentcms:form-prune` の明示実行だけが行う。
     */
    'forms' => [
        // 同じ相手・同じフォームからの 1 分あたりの送信数。0 以下で無効
        'rate_limit_per_minute' => (int) env('AGENTCMS_FORM_RATE_LIMIT', 5),
        'retention' => [
            // ip_hash / user_agent を null にする
            'anonymize_after_days' => (int) env('AGENTCMS_FORM_ANONYMIZE_AFTER_DAYS', 90),
            // **既定は 0 = 何もしない。** 受信を勝手に消さない
            'delete_after_days' => (int) env('AGENTCMS_FORM_DELETE_AFTER_DAYS', 0),
            // 弾いた件数（本文を持たない集計）だけは既定で整理する
            'delete_blocks_after_days' => (int) env('AGENTCMS_FORM_DELETE_BLOCKS_AFTER_DAYS', 365),
        ],
    ],

    /*
     * コメント（Phase 9.13 / P0-9・SPEC §C.5・§F.2・§G.2）。
     *
     * **既定を強い側に置く。** 自動承認しない・余計な個人情報を求めない。
     * 型ごとに使うかどうかは `content_types.comments_enabled`（9.7）が決める。
     * 整理は `php artisan agentcms:comment-prune` の明示実行だけが行う。
     */
    'comments' => [
        // 投稿した瞬間に公開する。**既定は false**（気づかないうちに何かが載らない）
        'auto_approve' => (bool) env('AGENTCMS_COMMENT_AUTO_APPROVE', false),
        // メールアドレスを必須にする。**既定は false**（要らない個人情報を集めない）
        'require_email' => (bool) env('AGENTCMS_COMMENT_REQUIRE_EMAIL', false),
        // 同じ相手・同じ投稿タイプへの 1 分あたりの投稿数。0 以下で無効
        'rate_limit_per_minute' => (int) env('AGENTCMS_COMMENT_RATE_LIMIT', 3),
        'retention' => [
            // ip_hash / user_agent を null にする
            'anonymize_after_days' => (int) env('AGENTCMS_COMMENT_ANONYMIZE_AFTER_DAYS', 90),
            // スパムを貯める理由が無い
            'delete_spam_after_days' => (int) env('AGENTCMS_COMMENT_DELETE_SPAM_AFTER_DAYS', 90),
            // **既定は 0 = 何もしない。** 他人の文章を勝手に消さない
            'delete_trash_after_days' => (int) env('AGENTCMS_COMMENT_DELETE_TRASH_AFTER_DAYS', 0),
        ],
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
        'siteverify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    // 公開サイト CSP の追加許可オリジン（カンマ区切り）。
    // テンプレート import 等で外部依存（Google Fonts / CDN script 等）を使う場合のみ設定する。
    // 既定は空 = Phase 1 からの挙動（外部許可は Turnstile のみ）を変えない。ADR-0010 §5
    'csp' => [
        'script_src' => array_filter(explode(',', (string) env('AGENTCMS_CSP_SCRIPT_SRC', ''))),
        'style_src' => array_filter(explode(',', (string) env('AGENTCMS_CSP_STYLE_SRC', ''))),
        'font_src' => array_filter(explode(',', (string) env('AGENTCMS_CSP_FONT_SRC', ''))),
        'img_src' => array_filter(explode(',', (string) env('AGENTCMS_CSP_IMG_SRC', ''))),
        'connect_src' => array_filter(explode(',', (string) env('AGENTCMS_CSP_CONNECT_SRC', ''))),
        'frame_src' => array_filter(explode(',', (string) env('AGENTCMS_CSP_FRAME_SRC', ''))),
    ],

    'uploads' => [
        // 実行可能領域外（storage/app/public/uploads）へ保存し、ファイル名は再生成する
        'disk' => 'public',
        'dir' => 'uploads',
        // SVG は能動的コンテンツを含み得るため、**保存前に必ず SvgSanitizer を通す**
        // （拡張子・申告 MIME だけでは許可しない。Phase 8.5-F / F-UX5）
        // GIF は Phase 9.5-D で追加（D13）。静止・animated とも **原本のみ**で配信し、
        // variant を作らない（GD で再エンコードするとアニメーションが失われる）
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'],
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'],
        'max_kilobytes' => 20480,
        // 幅・高さの上限（px）。超過・破損画像は ImageProcessor が拒否する
        'max_dimension' => 6000,
    ],

    /*
     * 一般ファイル（Phase 9.5-D / ADR-0018）。
     *
     * **画像とは別の経路**である。allowlist・保存先・配信のいずれも共有しない。
     * 一般ファイルは web から直接到達できない disk へ置き、controller 経由でのみ配信する
     * （直リンク = ブラウザが内容を解釈する経路、を作らない）。
     */
    'files' => [
        'disk' => 'local',
        'dir' => 'files',
        // 拡張子 => 許可する内容 MIME。**この表が正本**（SPEC §3.8）
        'allowed' => [
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            // finfo は CSV を text/plain と言うことがある
            'csv' => ['text/csv', 'text/plain'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
            'pptx' => [
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
            ],
        ],
        'max_kilobytes' => 20480,
        // OOXML を **展開せずに** 判定するための上限（zip bomb / traversal の拒否）
        'ooxml' => [
            'max_entries' => 1000,
            'max_uncompressed_bytes' => 200 * 1024 * 1024,
            'max_compression_ratio' => 100,
        ],
    ],

    /*
     * 画像バリエーション（Phase 9.5-D / ADR-0018）。
     *
     * **原本は必ず残す。** variant は「あれば速い」もので、無くても成立する。
     * 生成できなくても upload は成功させ、原本で配信する。
     *
     * 数値は .env で上書きできる。`.env` は文字列なので、数でない値や危険な値
     * （予約 0 など）が入り得る。`VariantCapability` が読むときに
     * **許容範囲へ丸める**（設定の誤りで upload が失敗するほうが害が大きい）。
     */
    'variants' => [
        'enabled' => env('AGENTCMS_VARIANTS_ENABLED', true),
        // 1〜100 に丸められる
        'avif_quality' => env('AGENTCMS_VARIANT_AVIF_QUALITY', 50),
        'webp_quality' => env('AGENTCMS_VARIANT_WEBP_QUALITY', 80),
        // 生成を試みる画素数の上限。**upload の可否には影響しない**（超えたら variant だけ skip）
        'max_pixels' => env('AGENTCMS_VARIANT_MAX_PIXELS', 20_000_000),
        // 見積りの外に空けておくメモリ（他の処理のため）。下限 8MB へ丸められる
        'memory_reserve_bytes' => env('AGENTCMS_VARIANT_MEMORY_RESERVE_BYTES', 32 * 1024 * 1024),
        // レスポンシブ配信の幅候補（px・カンマ区切り）。原本より小さい幅だけを作る（拡大しない）。
        // 同寸の AVIF / WebP はこれとは別に必ず試みる。空文字にすると幅候補を作らない。
        // 数でない値は無視、16〜8192 の外は捨て、多くても 6 つまで
        'widths' => env('AGENTCMS_VARIANT_WIDTHS', '640,1280'),
    ],

    'mail' => [
        'admin_to' => env('MAIL_ADMIN_ADDRESS', 'admin@example.test'),
        // staging では全メールをこの宛先へ強制リダイレクトする（実顧客への誤送信防止）。
        // 未設定時は admin_to へ。どちらも無ければ log mailer へフォールバックする
        'staging_redirect_to' => env('MAIL_STAGING_REDIRECT_TO', ''),
    ],

    // 管理者シード用。password に共有既定値は置かない（全利用者同一 password の禁止 — Phase 7）。
    // agentcms create / dev が project ごとに安全なランダム値を生成して .env に設定する
    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@example.test'),
        'password' => env('ADMIN_PASSWORD', ''),
    ],
];
