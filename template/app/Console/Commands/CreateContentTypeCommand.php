<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\ActorType;
use AgentCms\Core\Application\ContentTypes\CreateContentType;
use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\ContentTypes\ContentTypeBuilderPresets;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Schema\SchemaRegistry;
use App\Console\Concerns\SpeaksJson;
use Illuminate\Console\Command;

/**
 * Template Import が持ち込む投稿タイプを作る（R-13 Stage 2）。
 *
 * ## なぜ専用のコマンドが要るのか
 *
 * 分類項目（`agentcms:import-terms`）と**同じ座**である。
 * `content.type.create` は `OperationCatalog` に載っていない = **AI へは公開しない**。
 * ここは `agentcms template apply` 専用の内部経路で、作成そのものは
 * 既存の `CreateContentType` Application Service を通る
 * （validation / policy / 監査 / 排他ロックはいっさい迂回しない）。
 *
 * ## 項目定義をここで組み立てない
 *
 * 受け取るのは **preset の id** だけで、項目は `ContentTypeBuilderPresets` が展開する。
 * CLI 側（TypeScript）が項目定義を持つと、Content Type Builder と**正本が 2 つ**になる。
 *
 * ## 巻き戻さない
 *
 * 型の作成は migration を伴う不可逆な構造変更で、**削除は作成の裏返しではない**。
 * 1 件ずつ作り、**どこまで作れたか**を返す。途中で失敗しても作れた分は残し、
 * 再実行は**既に在る型を飛ばして続きから**進む（冪等）。
 *
 * ## 確認について
 *
 * `CreateContentType` は破壊的操作として確認を要求する。
 * **人の承認はもう済んでいる** — plan（`.agentcms/manifest/template-plan.json`）に
 * 作る型が明記され、それを承認したうえで apply が走っているからである。
 * だからここでは発行された token をその場で提示して続行する。
 * 新しい確認 UI は作らない（裁定 (i)）。
 *
 * ## 入出力
 *
 * 入力は **stdin の JSON** のみ（argv に載せない）。
 *
 * ```json
 * {"types": [{"id": "works", "label": "実績", "preset": "works"}]}
 * ```
 *
 * 出力は stdout に結果 JSON 1 行。
 *
 * ```json
 * {"status":"success","created":["works"],"skipped":["news"]}
 * ```
 */
final class CreateContentTypeCommand extends Command
{
    use SpeaksJson;

    protected $signature = 'agentcms:create-content-type';

    protected $description = 'Template Import 用: 投稿タイプを作成する（既に在るものは飛ばす）';

    /** 1 回で作れる件数の上限（暴走した plan を止める） */
    private const MAX_TYPES = 10;

    /** 受け取ってよいキー */
    private const ALLOWED_TYPE_KEYS = ['id', 'label', 'preset'];

    /**
     * 監査に残る呼び出し元。**外部入力にしない**（名乗りを偽装させない）。
     */
    private const ACTOR = 'template-import';

    public function handle(CreateContentType $create, SchemaRegistry $schemas): int
    {
        $raw = $this->readStdin();
        $input = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($input) || array_diff(array_keys($input), ['types']) !== []) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => '標準入力の JSON を読み取れませんでした',
            ], failure: true);
        }

        $types = $input['types'] ?? null;

        if (! is_array($types) || ! array_is_list($types)) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => 'types は配列（並び）で渡してください',
            ], failure: true);
        }

        if ($types === []) {
            return $this->emit(['status' => 'success', 'created' => [], 'skipped' => []], failure: false);
        }

        if (count($types) > self::MAX_TYPES) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => '一度に作成できる投稿タイプ数の上限（'.self::MAX_TYPES.'）を超えています',
            ], failure: true);
        }

        $created = [];
        $skipped = [];

        foreach ($types as $type) {
            if (! is_array($type) || array_diff(array_keys($type), self::ALLOWED_TYPE_KEYS) !== []) {
                return $this->emit([
                    'status' => 'validation_error',
                    'message' => '受け付けないキーがあります',
                    'created' => $created,
                    'skipped' => $skipped,
                ], failure: true);
            }

            $id = is_string($type['id'] ?? null) ? $type['id'] : '';

            if ($id === '') {
                return $this->emit([
                    'status' => 'validation_error',
                    'message' => 'id を指定してください',
                    'created' => $created,
                    'skipped' => $skipped,
                ], failure: true);
            }

            /*
             * **既に在るなら作らない。** 再実行が続きから進むのはこの 1 行による。
             *
             * ただし**公開は試す**。前の実行が「作ったが公開で落ちた」場合、
             * ここで飛ばすだけだと**二度と公開されない**（型は在るのに 404 のまま）。
             * 公開側は「設定の行が無いときだけ」触るので、人の判断は奪わない。
             */
            if ($schemas->has($id)) {
                $skipped[] = $id;

                $publishError = $this->publish($id);

                if ($publishError !== null) {
                    return $this->emit([
                        'status' => 'conflict',
                        'message' => "投稿タイプ [{$id}] を公開できませんでした: {$publishError}",
                        'created' => $created,
                        'skipped' => $skipped,
                    ], failure: true);
                }

                continue;
            }

            $draft = $this->draftFor($id, $type);

            if ($draft === null) {
                return $this->emit([
                    'status' => 'validation_error',
                    'message' => "投稿タイプ [{$id}] の構成（preset）が見つかりません",
                    'created' => $created,
                    'skipped' => $skipped,
                ], failure: true);
            }

            $result = $create->execute($draft, OperationContext::cli(self::ACTOR));

            /*
             * 承認は plan の段で済んでいる。発行された token をその場で提示する。
             *
             * 2 度目の context は**呼び出し元が同じ**でなければならない
             * （確認の指紋が `actorKey()` を含む）。`cli()` は毎回同じ
             * actorType / interface / actorLabel を作るので、token だけを足せばよい。
             */
            if ($result->status === OperationStatus::ConfirmationRequired) {
                $result = $create->execute($draft, new OperationContext(
                    actorType: ActorType::System,
                    interface: OperationInterface::Cli,
                    actorLabel: self::ACTOR,
                    confirmationToken: $result->confirmation?->token,
                ));
            }

            if ($result->status !== OperationStatus::Success) {
                return $this->emit([
                    'status' => $result->status->value,
                    'message' => (string) ($result->message ?? "投稿タイプ [{$id}] を作成できませんでした"),
                    /*
                     * **直し方の分からない断りにしない**（指摘 32 族 / R-13 7 件目）。
                     *
                     * `Result::validationError` は「入力内容を確認してください」しか
                     * message に持たない。**何が悪かったか**は `errors` にしか無いので、
                     * ここで落とすと利用者は原因を知る手段が無くなる。
                     */
                    'errors' => $result->errors,
                    // **どこまで作れたか**を必ず返す（巻き戻さないので、続きから進むのに要る）
                    'created' => $created,
                    'skipped' => $skipped,
                ], failure: true);
            }

            $created[] = $id;

            $publishError = $this->publish($id);

            if ($publishError !== null) {
                return $this->emit([
                    'status' => 'conflict',
                    'message' => "投稿タイプ [{$id}] は作成しましたが、公開できませんでした: {$publishError}",
                    'created' => $created,
                    'skipped' => $skipped,
                ], failure: true);
            }
        }

        return $this->emit([
            'status' => 'success',
            'created' => $created,
            'skipped' => $skipped,
        ], failure: false);
    }

    /**
     * 作った型を**そのまま公開する**（R-13 10 件目）。
     *
     * ## なぜ作成と公開を 1 つにするのか
     *
     * 管理画面では「作る」と「公開する」を分けている — 人は作ってから中身を整え、
     * 準備ができてから公開したいからである。**その分け方は変えない。**
     *
     * import は違う。**plan を承認した時点で「この型で公開面を作る」意思が表明されている**。
     * ここで公開まで行かないと、取り込んだページは `fallback` に拾われず
     * **`/works` も `/works/{slug}` も 404 のまま**になる（実案件で実測）。
     *
     * ## 人が決めた設定は奪わない
     *
     * 触るのは **`content_types` に行がまだ無い型だけ**。行が在るということは
     * 誰かが設定を保存したということで、`public: false` はその人の判断である。
     */
    private function publish(string $id): ?string
    {
        /*
         * **作った直後の型は、まだ「無い」ことになっている。**
         *
         * `SchemaRegistry` も設定の読み手も 1 要求ぶんをメモ化しているので、
         * 同じ要求の中で作った型は見えない（`UpdateContentType` が
         * 「投稿タイプが見つかりません」を返す）。忘れさせてから引き直す。
         */
        app()->forgetScopedInstances();

        $settings = app(ContentTypeSettingsWriter::class);
        $update = app(UpdateContentType::class);

        if ($settings->currentLockVersion($id) !== 0) {
            return null; // 既に誰かが設定している。**奪わない**
        }

        $result = $update->execute([
            'id' => $id,
            'lock_version' => 0,
            'registration' => ['public' => true],
            // 公開 URL の入口。`ContentPermalink::typeForBase()` はここを見る
            'public_slug' => $id,
        ], OperationContext::cli(self::ACTOR));

        if ($result->status === OperationStatus::Success) {
            return null;
        }

        $detail = [];

        foreach ($result->errors as $field => $messages) {
            $detail[] = $field.': '.implode(' / ', $messages);
        }

        return trim(((string) $result->message).' '.implode(' ', $detail));
    }

    /**
     * preset を展開して draft の入力を組み立てる。
     *
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>|null
     */
    private function draftFor(string $id, array $type): ?array
    {
        $presetId = is_string($type['preset'] ?? null) ? $type['preset'] : 'post';
        $preset = null;

        foreach (ContentTypeBuilderPresets::presets() as $one) {
            if ($one['id'] === $presetId) {
                $preset = $one;

                break;
            }
        }

        if ($preset === null) {
            return null;
        }

        $label = is_string($type['label'] ?? null) && trim($type['label']) !== ''
            ? trim($type['label'])
            : $preset['label'];

        return [
            'id' => $id,
            'label' => $label,
            'fields' => $preset['fields'],
            'list' => $preset['list'],
            'search' => $preset['search'],
        ];
    }
}
