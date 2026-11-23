<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Plan;

/**
 * `plan` が見つけた問題の種類（Phase 9.14 / STAGE 27C・SPEC §A.3）。
 *
 * ## 理由は符号で持つ
 *
 * 文章で持つと、画面へ出す言葉を変えただけで集計が割れる。
 * 符号なら**同じ問題を同じものとして数えられる**。
 *
 * ## 止めるかどうかは種類ごとに決める
 *
 * ここを「既定は止めない」にすると、**新しい理由を足したときに決め忘れたものが
 * 通ってしまう**。取り込めないものが混ざったまま apply されるのが一番まずいので、
 * 1 つずつ書く。
 */
enum PlanIssue: string
{
    // ── 取り込み先が無い・使えない ──────────────────────────

    /**
     * 対応先の投稿タイプが無い（§C.2）。
     *
     * `--type` の指定も無く、同じ名前の有効な型も無い場合である。
     * **importer は型を作らない**（作成は確認と監査を伴う別の操作）。
     *
     * §F の「知らない post_type は取り込まず数える」と、§C.2 の
     * 「無ければ止める」は両立する。**数えたうえで止める。**
     * 数だけ出して成功にすると、入らなかった十年分に気づかない。
     * 仕様として入れないと決まっているもの（`SkippedNavMenuItem` など）とは別に数える。
     */
    case UnknownType = 'unknown_type';

    /** 投稿タイプではない（メディアライブラリやサイト設定へは入れられない） */
    case TypeNotEditorial = 'type_not_editorial';

    /** いま新しい entry を作れない（archived / 準備できていない） */
    case TypeCannotCreate = 'type_cannot_create';

    /**
     * 公開できない型。**十年分を入れても誰の目にも触れない**。
     *
     * §C.2 は「入れる型が editorial でない、または公開できない状態なら止める」。
     * 移行の方針そのものなので、ここで緩めない。
     */
    case TypeNotPublic = 'type_not_public';

    /**
     * 公開する型なのに一覧も単体も出ない。**入れても誰も辿り着けない**。
     *
     * 「公開する」と言いながらどこにも出ない、は設定の矛盾なので止める。
     */
    case TypeNotReachable = 'type_not_reachable';

    /** コメントがあるのに、その型はコメントを受けない（§C.4 の写し先が無い） */
    case TypeCommentsDisabled = 'type_comments_disabled';

    /**
     * 必須の欄に、WXR から入る値が無い（§F）。
     *
     * `CreateContent` はその記事を断るので、ここで止めないと
     * **十年分を流し始めてから 1 件目で落ちる**。§F は「止めるは plan で全部出す」である。
     *
     * §F の補いが効く欄は数えない — 空の題名は `(無題)`、空の slug は id から作るので、
     * WXR が空でも**埋まる**。数えると直しようのない理由で止まる。
     */
    case RequiredFieldMissing = 'required_field_missing';

    /**
     * 型の必須の欄に、WXR 側の対応がそもそも無い。
     *
     * Content Type Builder で作った型が、WXR に無い欄（例: 必須の「価格」）を
     * 必須にしている場合である。**その型を取り込み先にすると、どの記事も入らない。**
     *
     * **記事ごとではなく型ごとに 1 件**数える。原因は型の設計であって記事ではないので、
     * 十年分の件数で報告しても直す先が分からない。
     */
    case UnmappableRequiredField = 'unmappable_required_field';

    /**
     * 型が必須にしている分類に、その記事の割り当てが無い（§C.3・§F）。
     *
     * `ContentTaxonomyAssignments` は、作成時に required 宣言の分類が渡って来なければ
     * validation error を返す。ここで見ないと、**十年分を流し始めてから 1 件目で落ちる** —
     * `RequiredFieldMissing` と同じ形の穴が、欄ではなく分類の側に開く。
     *
     * **記事ごとに数える。** 欄と違って「どの記事にも対応が無い」とは限らない —
     * 分類が付いている記事と付いていない記事は、同じ WXR の中に普通に混ざる。
     * 「37 本の記事に分類が付いていない」が、そのまま直す単位になる。
     *
     * 見るのは**読み替えた後の受け皿**である。`--taxonomy` の前の名前で見ると、
     * 割り当てが在るのに「必須の分類が無い」になる。
     */
    case RequiredTaxonomyMissing = 'required_taxonomy_missing';

    // ── コメント（§C.4） ────────────────────────────────────

    /**
     * `wp:comment_approved` が 4 値のどれでもない。
     *
     * **勝手に承認待ちへ寄せない。** 寄せると、公開されていたコメントが黙って消えるか、
     * 隠されていたものが黙って出るかの**どちらかが必ず起きる**。
     * しかもどちらが起きたのかは、入れた後では分からない。**他人が書いた文章である。**
     */
    case CommentStatusUnreadable = 'comment_status_unreadable';

    /**
     * 桁に入らない値がある（`author_name` 80 / `author_email` 255 / `author_url` 255）。
     *
     * §C.8.4 の 3 原則（切り詰めない・置き換えない・黙って落とさない）と同じ理由で
     * **切らずに止める**。名前は他人のものである。
     *
     * **どの欄かは内訳で出す。** 理由を欄ごとに分けると、同じ「桁に入らない」を
     * 3 か所で探すことになる。
     *
     * 桁は**文字数**で見る。varchar(80) に日本語の名前は 80 文字入る —
     * byte で見ると 26 文字で止まる。
     */
    case CommentFieldTooLong = 'comment_field_too_long';

    /**
     * 親の `wp:comment_id` がその記事の中に無い。
     *
     * **捨てない。1 段目として入れる。** 他人の文章を消す方が悪い。
     * 止めないが、返信だったものが返信でなくなるので**必ず言う**。
     */
    case CommentParentMissing = 'comment_parent_missing';

    /**
     * 2 段目以降だったので、親を 1 段目へ付け替えた（§C.4 / 9.13 OG4）。
     *
     * 9.13 は 2 段目を断るので、付け替えないと入らない。**捨てるよりよい**が、
     * 会話の形は変わるので、入れる前に件数を言う。
     */
    case CommentReplyFlattened = 'comment_reply_flattened';

    /**
     * 親を辿ると戻ってくる（1 の親が 2・2 の親が 1、あるいは自分が親）。
     *
     * 辿る処理が**止まらない**。`TermGraph` の循環と同じで、入れる前に止める。
     */
    case CommentParentCycle = 'comment_parent_cycle';

    /**
     * `wp:comment_status` が `open` でも `closed` でもない（§C.4.3）。
     *
     * **`CommentStatusUnreadable` とは別のものである。** あちらは
     * **コメント 1 件**の承認状態が読めないこと、こちらは**記事**が
     * コメントを受け付けるかどうかの設定である。対象も粒度も違う。
     *
     * **要素が無いことと、知らない値が書いてあることは別に扱う。**
     * 前者は正常な不在なので黙ってよい。後者は
     * 「こちらが読めていない何かがそこにある」であり、
     * 未知の `wp:status` と同じ作法（§A.3）で数える。
     *
     * 取り込みの動きは変わらない（行を書かない = 型に従う）ので**止めない**。
     */
    case CommentSettingUnknown = 'comment_setting_unknown';

    /**
     * 分類の**定義そのもの**が無い（§C.3）。
     *
     * `content/taxonomies/<key>.json` を作るところから始める必要がある。
     * **同期していないだけの場合と混ぜない** — 混ぜると「作れ」と言われた
     * 利用者が、既に在るファイルをもう一度作ろうとする。
     */
    case MissingTaxonomy = 'missing_taxonomy';

    /**
     * 定義はあるが DB へ同期されていない。
     *
     * 作るものは無く、`agentcms:taxonomy-sync` を走らせるだけでよい。
     */
    case TaxonomyNotSynced = 'taxonomy_not_synced';

    /** 分類が無効にされている。新しい項目を付けさせない（9.7 / STAGE 20D） */
    case TaxonomyArchived = 'taxonomy_archived';

    /**
     * 分類は使えるが、**その項目**が保管されている（§C.3）。
     *
     * `ContentTaxonomyAssignments` は**新しく付ける**保管項目を 1 件ずつ拒む
     * （既に付いているものはそのまま残す）。取り込みの割り当ては必ず新規なので、
     * ここで止めないと**記事が 1 本ずつ検証エラーになる**。
     * §F の「止めるは plan の段階で全部出す」に反する。
     *
     * `TaxonomyArchived` とは**直す先が違う**ので分ける。
     * 分類の側はその分類を使えるようにする（または対応先を変える）、
     * 項目の側はその項目を戻すか、割り当て先を変える。
     *
     * 数えるのは**その項目を指した記事**である。同じ項目を 500 本が指していれば 500 件で、
     * 「何本の記事が分類を失うか」が出る。
     * **誰も指していない保管項目では止めない** — 記事は 1 本も失われないのに
     * 移行だけが止まり、しかも直す先が無い。
     */
    case TermArchived = 'term_archived';

    /**
     * 定義の持ち主と DB の持ち主が食い違う。
     *
     * どちらが正なのか決められないまま項目を足すと、
     * 次の同期で**足したものが消える**か、定義の側が上書きされる。
     */
    case TaxonomyManagedByMismatch = 'taxonomy_managed_by_mismatch';

    /**
     * 定義と DB で階層の有無が違う。
     *
     * 「親から先に作る」（§C.3）の意味が両者で変わるので、
     * どちらに合わせても片方の期待を裏切る。
     */
    case TaxonomyHierarchyMismatch = 'taxonomy_hierarchy_mismatch';

    /**
     * 取り込み先の型が、その分類を宣言していない。
     *
     * **黙って落とすと、分類の付いていない記事が十年分できる。**
     * 割り当ては書かれているのに、入れる先が無い状態である。
     */
    case TaxonomyNotDeclaredForType = 'taxonomy_not_declared_for_type';

    // ── 分類の形が壊れている ────────────────────────────────

    /**
     * 割り当てに対応する定義が `<channel>` 直下に無い。
     *
     * **その分類に定義が 1 件も無いときはここに来ない。** その場合は
     * `FlatTaxonomyTermCreated` になる（`FlatTermDefinitionSource`・
     * WP 一周 2026-09-04）。ここが立つのは、**同じ分類に他の定義はあるのに、
     * この割り当てだけ対応が無い**場合である。書き出しが壊れている・
     * 途中で切れているなど、平らに救ってよい形とは違う可能性が高いので、
     * 従来どおり止める。
     */
    case TermDefinitionMissing = 'term_definition_missing';

    /**
     * 分類に定義が **1 件も無かった**ので、記事側の `<category domain nicename>`
     * から**平らな項目**を作った（`FlatTermDefinitionSource`・WP 一周 2026-09-04）。
     *
     * ## 決定の経緯（2026-09-04・メンテナ）
     *
     * 以前は「`<wp:category>` が無ければ止めて、書き出し直しを求める」だった
     * （§C.3 の (a)）。だが実物の書き出し（沼津の案件・87 記事・4 分類）は
     * `<wp:category>` を**1 件も持たない形**で存在し、それでも記事側には
     * 名前が入っている（`nicename` 属性・CDATA）。無いのは**親子関係だけ**である。
     *
     * 「書き出し直してください」は、**サイトが既に閉じている案件・
     * 前の制作会社が鍵を持っている案件では実行できない**。
     * そして引っ越しがいちばん要るのは、まさにその状況の人である。
     * だから**親子は作らない・平らな項目として作る**を既定にした。
     * 旧い判断（(a) をそのまま止める）を消さず、ここに理由を添えて残す。
     *
     * **止めない。** だが黙っても作らない — 何件・どの分類を平らにしたかを必ず言う。
     */
    case FlatTaxonomyTermCreated = 'flat_taxonomy_term_created';

    /** 親として名指しされた slug の定義が無い */
    case TermParentMissing = 'term_parent_missing';

    /** 自分を親にしている */
    case TermParentSelf = 'term_parent_self';

    /** 親を辿ると元に戻る。**辿り続けると止まらない** */
    case TermParentCycle = 'term_parent_cycle';

    /**
     * 同じ分類に同じ slug があり、**中身が食い違う**。
     *
     * term_id も名前も親も違うなら、同じ受け皿の同じ項目だとは決められない。
     * どちらを採っても、採らなかった方の記事の分類が変わる。
     */
    case TermDefinitionConflict = 'term_definition_conflict';

    /**
     * 同じ `wp:term_id` が 2 回出てくる。
     *
     * **中身が完全に同じでも止める。** 台帳は `external_id` で 1 件ずつ覚えるので、
     * 同じ id の 2 定義を「同じものだから」と通すと、**2 件目は台帳から不可視**になる。
     * 取り消しでも再実行でも、その 1 件だけ扱えなくなる。
     */
    case TermIdDuplicate = 'term_id_duplicate';

    /**
     * `wp:term_id` は違うが、分類・slug・名前・親が**すべて一致する**。
     *
     * 受け皿の上では同じ項目を指しているので、1 つ作って使い回せる。
     * 件数として報告する。
     */
    case TermDefinitionRepeated = 'term_definition_repeated';

    /** 定義が多すぎて、循環まで確かめ切れない */
    case TooManyTermDefinitions = 'too_many_term_definitions';

    /**
     * WXR の綴りがそのままでは保存できないので、こちらで当てた。
     *
     * `Str::slug('お知らせ')` は空文字を返す。日本語のサイトではこれが普通で、
     * ここで止めると**日本語の WXR はどれも取り込めない**。
     * だが URL は元と変わるので、**黙って当てない**。
     * 後から Admin で変えられることも合わせて言う。
     */
    case TermSlugGenerated = 'term_slug_generated';

    /**
     * 項目の名前が `taxonomy_terms.name` の桁（120）に収まらない。
     *
     * **切り詰めて入れない。** 切り詰めた名前は元へ戻せず、
     * どこが削られたのかも分からないまま十年分残る。
     */
    case TermNameTooLong = 'term_name_too_long';

    /**
     * 同じ受け皿の中で、保存する綴りがぶつかる。
     *
     * 当てる綴りは term_id の下 8 桁から作るので、`12345678` と
     * `10012345678` は同じ綴りになる。名前から作る場合も、違う名前が
     * 同じ綴りになることがある。どちらも**片方が消える**。
     */
    case TermTargetSlugConflict = 'term_target_slug_conflict';

    /**
     * 決めた綴りが、それでもなお保存できる形になっていない。
     *
     * `TermSlug::generate()` は必ず保存できる綴りを返す約束なので、
     * ここは通らない。**約束が破れたときに黙って壊れない**ようにする。
     */
    case TermStorageSlugInvalid = 'term_storage_slug_invalid';

    // ── 台帳と現実が合わない ────────────────────────────────

    /**
     * 台帳の記録から、どれを指しているのか決められない。
     *
     * 別の `target_type` に入っている（`--type` を変えて実行し直した）、
     * 目指す先と別の先の両方にある、同じ鍵の行が 2 つある、のいずれかである。
     *
     * 直すには**指定を戻すか、先の batch を取り消す**。
     * 「対象が消えている」（`LedgerTargetMissing`）とは**次にやることが違う**ので、
     * 1 つの理由にまとめない。まとめると、片方の手順でもう片方を直そうとする。
     */
    case LedgerConflict = 'ledger_conflict';

    /**
     * 台帳の記録が指す対象が、いまゴミ箱に入っている。
     *
     * **自動でどちらへも倒さない。** 取り消しの跡かもしれないし、
     * 人がゴミ箱へ入れたのかもしれない。台帳からは見分けられない。
     *
     * 飛ばせば、十年分がゴミ箱に入ったまま「取り込み成功」と報告される。
     * 作り直せば、**消したはずのものが戻る**（そもそも `uq_import_external` が
     * 二重作成を弾く）。どちらの自動判断も誤るので、**人へ返す**。
     */
    case LedgerTargetTrashed = 'ledger_target_trashed';

    /**
     * 台帳には記録があるのに、その対象の行がもう無い。
     *
     * 直すには**台帳を片付ける**（先の batch を取り消す）。指定を戻しても直らない。
     *
     * 黙って作り直すと、**消したはずのものが戻る**。無視すると、
     * 台帳が現実と合わないまま増えていく。
     *
     * ここで言う「無い」は**行が物理的に無い**ことである。ゴミ箱にある投稿を
     * どう読むかは Owner Gate（status mapping）の決定待ちで、ここには含まれない。
     */
    case LedgerTargetMissing = 'ledger_target_missing';

    /**
     * `wp:status` が識別子の形でないので、元の値を残せない（§C.8.4）。
     *
     * **切り詰めない・置き換えない・黙って落とさない。**
     * 切り詰めた値は元へ戻せず、置き換えれば別の値を捏造し、
     * 黙って落とせばその 1 件だけ出自が消える。3 つとも取れない。
     *
     * `wp_posts.post_status` は varchar(20) で登録済みの状態の slug しか入らないので、
     * ここが立つのは**手で書き換えたか壊れた WXR** である。
     */
    case SourceStatusUnreadable = 'source_status_unreadable';

    /** 外部 id が台帳の桁に収まらない（`import_records.external_id` は 64 byte） */
    case ExternalIdTooLong = 'external_id_too_long';

    /** 取り込み先の型名が台帳の桁に収まらない（`target_type` は 64 byte） */
    case TargetTypeTooLong = 'target_type_too_long';

    /**
     * 既にある対象の id が台帳の桁に収まらない（`target_id` は 64 byte）。
     *
     * 台帳へ書けない対象は、**取り消しのときに見つけられない**。
     * apply の途中で 1 件ずつ落ちるより、始める前に言う。
     */
    case TargetIdTooLong = 'target_id_too_long';

    // ── 途中でファイルが変わった ────────────────────────────

    /**
     * plan の最中に元のファイルが差し替わった。
     *
     * 中身の指紋と item の読み取りは別々に開くので、その間に差し替えられると
     * **「指紋は前のファイル・中身は後のファイル」の plan** ができてしまう。
     * それを apply へ渡せば、照合は通るのに入るものが違う。
     */
    case SourceChangedDuringPlan = 'source_changed_during_plan';

    // ── 報告するが止めない ──────────────────────────────────

    /** `--type` に書いたのに、その post_type が WXR に 1 件も無い */
    case UnusedTypeMapping = 'unused_type_mapping';

    /** `--taxonomy` に書いたのに、その分類が WXR に 1 件も無い */
    case UnusedTaxonomyMapping = 'unused_taxonomy_mapping';

    /**
     * 指定は §A.2 により無視された。`UnusedTypeMapping`（指定した型が WXR に
     * 1 件も無い）とは別で、こちらは**実在したが指定が効かなかった**。
     *
     * `--type=page:news` のように、入れないと決まっている型への対応を書いた場合である。
     * §A.2 は利用者の指定より強いので、指定は効かない。
     * だが**効かなかったことを言わない**と、利用者は入ったつもりで待つ。
     * `skipped_nav_menu_item` は「入れなかった」であって、指定の話ではない。
     */
    case ExcludedTypeMapping = 'excluded_type_mapping';

    /*
     * ここから下は「**仕様として入れないと決まっている**もの」である。
     * 対応先が無くて入れられないもの（`UnknownType`）とは別物なので、
     * 同じ数え方にしない。**数は必ず出す** — 黙って消えたと思わせない（§A.3）。
     */

    /** メニュー項目。この Phase では入れない（§A.2） */
    case SkippedNavMenuItem = 'skipped_nav_menu_item';

    // ── ページ（release-20260909 / wxr-pages）────────────

    /**
     * ページの本文が `richtext` 部品の上限（`PageFields::BODY_MAX_BYTES`）を超える。
     *
     * **止める。** 切り詰めて入れると本文が黙って欠ける。
     * 分けて入れる仕組みは持たない（部品の上限は画面の編集と同じ守り）。
     * 元の WXR でそのページを分けるか、記事として入れ直すかは人が決める。
     */
    case PageBodyTooLarge = 'page_body_too_large';

    /**
     * ページの題名が `pages.title` の上限（200 文字）を超える。**止める。**
     * 切り詰めると題名が黙って変わる。
     */
    case PageTitleTooLong = 'page_title_too_long';

    /**
     * ページの本文を PageDocument として検証できなかった。**止める。**
     * `bySource` に検証が言った 1 つ目の場所を載せる（本文そのものは載せない）。
     */
    case PageBodyUnrepresentable = 'page_body_unrepresentable';

    /**
     * ページの URL（slug）が元と変わる。**止めない。**
     *
     * こちらの URL は小文字英数とハイフンだけなので、日本語や大文字の
     * `post_name` はそのまま使えない。既にある URL とぶつかる場合も同じ。
     * 題名から作り直す（`CreatePage::draftSlug()` と同じ経路）。
     * 元の URL は台帳にも WXR にも残るので、redirect は人が張れる。
     */
    case PageSlugGenerated = 'page_slug_generated';

    /**
     * ページの URL（slug）が、既存の画面（`/about` `/contact` などの route・
     * 投稿タイプの公開 slug）に取られている。**止めない。**
     *
     * DB には入るのに、その URL では既存の画面が出て本文が読めない — 黙って
     * 上書きも、黙って読めないままにもしない。`-2` 付きの別の URL で入れ、
     * `bySource` に元の綴りを載せる。既存の画面を Page に置き換えるかは人が決める。
     */
    case PageSlugReserved = 'page_slug_reserved';

    /**
     * ページの親子関係は再現しない。**止めない。**
     *
     * こちらの Page は階層（`wp:post_parent`・URL の `/parent/child`）を持たない。
     * 子ページは平らに入り、URL は slug 単独になる。件数と id を出すので、
     * 親子の並びが要るサイトは人が判断できる。
     */
    case PageParentFlattened = 'page_parent_flattened';

    /**
     * 本文が空のページを、公開ではなく下書きで入れる。**止めない。**
     *
     * こちらは空のページを公開できない（`PublishPage` の PB 規則）。
     * 公開状態を保つために空の部品を捏造するより、下書きにして言う。
     */
    case PageEmptyKeptDraft = 'page_empty_kept_draft';

    /**
     * 予約（`wp:status = future`）のページなのに公開日時が読めない。**止める。**
     * 「いま」で公開すると、まだ出すつもりの無かったページが公開サイトに出る。
     */
    case PageScheduleUnreadable = 'page_schedule_unreadable';

    /**
     * ページの本文に shortcode（`[gallery]` など）が残る。**止めない。**
     *
     * こちらは shortcode を展開できない。本文から消すと黙って欠けるので、
     * 文字列のまま入れて件数と id を出す。人が編集画面で見つけて直せる。
     */
    case PageShortcodeKept = 'page_shortcode_kept';

    /**
     * ページの抜粋が SEO の description（200 文字）を超えるので入れない。**止めない。**
     * 切り詰めると description が黙って変わる。本文は入る。
     */
    case PageSeoDescriptionDropped = 'page_seo_description_dropped';

    /**
     * ページに付いていたコメントは入れない。**止めない。**
     *
     * こちらの Page はコメントを持たない。他人の文章なので黙って落とさず、
     * コメント 1 件ごとに数える（`external_id` は `wp:comment_id`）。
     */
    case PageCommentsDropped = 'page_comments_dropped';

    /**
     * ページに付いていた分類の割り当ては入れない。**止めない。**
     *
     * こちらの Page は分類を持たない。割り当て 1 件ごとに数え、`bySource` に分類名を出す。
     */
    case PageTermsDropped = 'page_terms_dropped';

    /** 添付。`--media` を付けたときだけ取りに行く（§C.6） */
    case SkippedAttachment = 'skipped_attachment';

    /**
     * shortcode（`[...]`）を含む記事。
     *
     * **取り込みはする**（文字として残す — §C.5）。
     * だが移行先で動かないので、**移行した後ではなく前に**言う。
     */
    case ShortcodePresent = 'shortcode_present';

    /** 入れ先の無い meta（§C.7 の対象外） */
    case MetaDropped = 'meta_dropped';

    /** 本文が大きい。取り込みはするが報告する（`LARGE_BODY_BYTES`） */
    case LargeBody = 'large_body';

    /**
     * 公開されていた以外の状態が下書きへ落ちた（§C.8.5）。
     *
     * **取り込みはする。** こちらの状態は 2 つしかないので、畳むこと自体は
     * 決まった動きである。だが十年分を入れると下書きが大量に増えるので、
     * **入れる前に**件数と元の状態ごとの内訳を出す。
     * 「3,000 件を下書きにしました」だけでは、それがもともと非公開だったのか
     * ゴミ箱だったのかが分からない。
     */
    case StatusDowngradedToDraft = 'status_downgraded_to_draft';

    /** この理由が 1 件でもあれば、plan の hash を出さない */
    public function blocksPlan(): bool
    {
        return match ($this) {
            /*
             * **書き間違いの可能性が高いので言うが、取り込み自体は成立する。**
             * 指定が余っているだけで、入るものが変わるわけではない。
             */
            self::UnusedTypeMapping,
            self::UnusedTaxonomyMapping,
            /*
             * **指定が効かなかっただけ**で、取り込みは成立する。
             * だが黙って無視はしない（§A.3）。
             */
            self::ExcludedTypeMapping,
            /*
             * **入れないと決まっているもの**（§A.2 / §C.6）。
             * 対応先が無くて入れられないのとは違うので、止めない。
             * ただし件数は必ず出す — 黙って消えたと思わせない。
             */
            self::SkippedNavMenuItem,
            self::SkippedAttachment,
            /*
             * ページは入る。**だが元と変わるところは必ず言う**。
             */
            self::PageSlugGenerated,
            self::PageSlugReserved,
            self::PageParentFlattened,
            self::PageEmptyKeptDraft,
            self::PageShortcodeKept,
            self::PageSeoDescriptionDropped,
            self::PageCommentsDropped,
            self::PageTermsDropped,
            /*
             * 取り込みはするが、**移行した後ではなく前に**言うもの。
             */
            self::ShortcodePresent,
            self::MetaDropped,
            self::LargeBody,
            /*
             * 畳むことは決まった動きなので止めない。**だが必ず言う**（§C.8.5）。
             */
            self::StatusDowngradedToDraft,
            /*
             * **完全に一致する定義が 2 つ**あるだけなら、作るのは 1 つで足りる。
             * 食い違う場合（`TermDefinitionConflict`）は別の理由で、そちらは止める。
             */
            self::TermDefinitionRepeated,
            /*
             * **日本語のサイトでは、当てるのが普通の経路である。**
             * ここで止めると日本語の WXR はどれも取り込めない。
             * URL が元と変わることは報告し、止めはしない。
             */
            self::TermSlugGenerated,
            /*
             * **分類に定義が無かったので、平らな項目として作った。**
             * 親子を捏造するよりよい（§C.3 の追補・2026-09-04）。
             * 親子が分からないことは報告し、止めはしない。
             */
            self::FlatTaxonomyTermCreated,
            /*
             * コメントは入る。**だが形が変わるので必ず言う**（§C.4）。
             * 親が居ないものも 2 段目以降も、捨てずに 1 段目として入れる。
             */
            self::CommentParentMissing,
            self::CommentReplyFlattened,
            /*
             * 読めない値が書いてあるが、**取り込みの動きは変わらない**（§C.4.3）。
             * 行を書かないので、型の設定に従うだけである。
             */
            self::CommentSettingUnknown => false,
            default => true,
        };
    }
}
