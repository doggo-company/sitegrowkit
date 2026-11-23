<?php

declare(strict_types=1);

namespace AgentCms\Core\Wxr\Apply;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Contracts\MediaIntake;
use AgentCms\Core\Wxr\Ledger\ImportKind;
use AgentCms\Core\Wxr\Ledger\LedgerKey;
use AgentCms\Core\Wxr\Ledger\LedgerReconciler;
use AgentCms\Core\Wxr\Media\MediaFailure;
use AgentCms\Core\Wxr\Media\RemoteFile;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemTarget;
use AgentCms\Core\Wxr\Plan\PlanAction;
use AgentCms\Core\Wxr\Plan\PlanFindings;
use AgentCms\Core\Wxr\WxrError;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Support\Facades\DB;

/**
 * メディアを入れる（Phase 9.14 / STAGE 27E-3・SPEC §C.6・§E.1）。
 *
 * ## 記事より先に走る
 *
 * 本文の URL を置き換えるには、**記事を作る前に**こちら側の URL が要る。
 * 記事を作った後で本文を差し替えると `UpdateContent` を通り、投稿の行の
 * `updated_at` が動く — すると取り消しの (a) が「人が触った」と読み、
 * **取り込んだ直後の記事が 1 件も取り消せなくなる**（§D.3）。
 * だから走査を 1 周増やして、**置換済みの本文を最初から書く**。
 *
 * ## 保存の口は contract 越し
 *
 * `MediaIntake`（ADR-0020）を通る。Core は `modules/` を名指しできないためで、
 * 実装は既存の upload 経路（`UploadValidator` / `FileInspector` / `SvgSanitizer`）を通る —
 * **通る検査は 1 つも変わらない**。
 *
 * ## 口が無ければ、断る
 *
 * bind されていない環境で黙って添付を飛ばすと、「成功したが画像が 1 枚も無い移行」が
 * 残る。十年分の記事に画像が無いことに気づくのは数日後である。**止める**（§A.3）。
 *
 * ## 1 件の失敗で本編を止めない
 *
 * 取れなかったものは符号で数えて続ける（§C.6・§A.3）。**取り込めた URL だけ**を
 * 置換に渡すので、取れなかった URL は本文に元のまま残る —
 * 壊れたリンクを別の壊れたリンクに変えない。
 *
 * ## 取得は transaction の外
 *
 * ネットワークを transaction の中に入れない。相手が黙ると、行を掴んだまま待つ。
 */
final class MediaImporter
{
    public function __construct(
        private readonly ItemTarget $targets,
        private readonly LedgerReconciler $ledger,
        private readonly RemoteFile $remote,
        /** bind されていなければ `null`（`MediaIntake` の実装は project 側にある） */
        private readonly ?MediaIntake $intake = null,
    ) {}

    /**
     * 入れて、**何が起きたか**と、**置き換えられる URL の対応**を返す。
     *
     * 1 件は必ず 1 つの数えに入る（作った・既に在る・取れなかった理由のどれか）。
     *
     * @return array{counts: array<string, int>, urls: array<string, string>}
     */
    public function import(
        WxrReader $reader,
        ImportOptions $options,
        string $batchId,
        OperationContext $context,
    ): array {
        $counts = [];
        $urls = [];

        foreach ($reader->items() as $item) {
            $target = $this->targets->of($item, $options);

            if ($target === null || $target['kind'] !== ImportKind::Media) {
                // `--media` が無ければ添付はここへ来ない = **外へ 1 度も繋がない**
                continue;
            }

            if ($this->intake === null) {
                /*
                 * **添付が在るのに預け先が無い。** ここで初めて気づくのは、
                 * `--media` を付けた実行で添付が 1 件も無ければ止める必要がないからである。
                 */
                throw new WxrError(
                    'メディアを取り込む口がこの環境にありません（MediaIntake が bind されていません）。'
                    ."\n".'--media を外して実行するか、Media モジュールを有効にしてください。',
                );
            }

            $source = $item->attachmentUrl;

            if ($source === null || $source === '') {
                // 取得先が書かれていない添付。取りに行く先が無いので数えて飛ばす
                $counts[MediaFailure::Fetch->value] = ($counts[MediaFailure::Fetch->value] ?? 0) + 1;

                continue;
            }

            $key = new LedgerKey(ImportKind::Media, ItemTarget::MEDIA_TARGET, $item->postId);
            $verdict = $this->ledger->reconcile([$key], new PlanFindings)[0];
            $action = $verdict->action(false);

            if ($action !== PlanAction::Create) {
                /*
                 * 既に入っている。**取りに行かない**（同じファイルを二度落とさない）。
                 * 本文の置換には使えるので、こちら側の URL は引き直す。
                 */
                /*
                 * 既に入っている。**取りに行かない**（同じファイルを二度落とさない）。
                 * URL は引き直す — 後から足された記事の本文も置換できるようにするため。
                 */
                $counts[$action->value] = ($counts[$action->value] ?? 0) + 1;

                $targetId = $verdict->usableTargetId();
                $existing = $targetId === null ? null : $this->intake->urlOf($targetId);

                if ($existing !== null) {
                    $urls[$source] = $existing;
                }

                continue;
            }

            [$fetched, $failure] = $this->remote->fetch($source);

            if ($fetched === null) {
                $counts[($failure ?? MediaFailure::Fetch)->value] = ($counts[($failure ?? MediaFailure::Fetch)->value] ?? 0) + 1;

                continue;
            }

            try {
                $url = $this->store($fetched->path, $fetched->filename, $item->postId, $batchId, $context);
            } finally {
                $fetched->discard();
            }

            if ($url === null) {
                // 取れたが、upload 経路が受け付けなかった（拡張子と中身の食い違い・上限など）
                $counts[MediaFailure::Rejected->value] = ($counts[MediaFailure::Rejected->value] ?? 0) + 1;

                continue;
            }

            $counts[PlanAction::Create->value] = ($counts[PlanAction::Create->value] ?? 0) + 1;
            $urls[$source] = $url;
        }

        return ['counts' => $counts, 'urls' => $urls];
    }

    /**
     * 保存して台帳へ書く。**台帳の記録は同じ transaction で。**
     *
     * 分けると、その間に落ちたときに台帳に無いメディアが残り、取り消せなくなる。
     * 保存そのもの（ファイルの書き出し）は `UploadMedia` の中で起きるので、
     * ここが守れるのは「行と行」の整合である。
     */
    private function store(
        string $path,
        string $filename,
        string $externalId,
        string $batchId,
        OperationContext $context,
    ): ?string {
        return DB::transaction(function () use ($path, $filename, $externalId, $batchId, $context): ?string {
            $stored = $this->intake?->store($path, $filename, $context);

            if ($stored === null) {
                return null;
            }

            DB::table('import_records')->insert([
                'batch_id' => $batchId,
                'external_id' => $externalId,
                'kind' => ImportKind::Media->value,
                'target_type' => ItemTarget::MEDIA_TARGET,
                'target_id' => $stored['id'],
                // **こちらが作ったので所有する**（取り消しで消してよい）
                'created_by_import' => true,
                'created_at' => now(),
            ]);

            return $stored['url'];
        });
    }
}
