<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Application\Taxonomy\CreateTerm;
use App\Console\Concerns\SpeaksJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Template Import の分類項目をまとめて作る（Phase 9.3B / #23・SPEC §U.3）。
 *
 * ## なぜ専用のコマンドが要るのか
 *
 * import は「この分類にこれらの項目を足す」という **1 つのまとまった意思決定**で、
 * 途中まで作られた状態は誰の役にも立たない。
 * 1 件ずつ作って失敗したら消す（補償）のは、
 *
 * - 削除は確認と参照調査（RESTRICT）を伴う別の意味の操作で、作成の裏返しではない
 * - 作った直後に別の参照が付けば、消すこと自体が正しくなくなる
 * - 補償そのものが失敗したときに、もう戻す手段が無い
 *
 * ため使えない。**外側の 1 トランザクション**で全件を実行し、
 * 1 件でも成功しなければ項目も監査も 1 行残さず巻き戻す。
 *
 * ## AI へは公開しない
 *
 * これは CLI（`agentcms template apply`）専用の内部経路で、
 * `OperationCatalog` にも MCP tool にも API にも追加しない。
 * 作成そのものは既存の `CreateTerm` Application Service を通り、
 * validation / policy / 監査はいっさい迂回しない。
 *
 * ## 入出力
 *
 * 入力は **stdin の JSON** のみ（argv に載せない）。
 *
 * ```json
 * {"terms": [{"taxonomyKey": "news_category", "name": "お知らせ", "slug": "news"}]}
 * ```
 *
 * 監査に残る呼び出し元は固定（`template-import`）で、入力から受け取らない。
 *
 * 出力は stdout に結果 JSON 1 行。
 */
final class ImportTermsCommand extends Command
{
    use SpeaksJson;

    protected $signature = 'agentcms:import-terms';

    protected $description = 'Template Import 用: 分類項目をまとめて作成する（全件成功か、1 件も作らないか）';

    /** 1 回で作れる件数の上限（暴走した plan を止める） */
    private const MAX_TERMS = 200;

    /** 受け取ってよいキー。未知キーは CreateTerm 側でも拒否されるが、入口でも絞る */
    private const ALLOWED_TERM_KEYS = ['taxonomyKey', 'name', 'slug'];

    /** payload の最上位で受け取ってよいキー */
    private const ALLOWED_ROOT_KEYS = ['terms'];

    /**
     * 監査に残る呼び出し元。
     *
     * **外部入力にしない。** 呼び出し側が名乗った文字列をそのまま監査へ書くと、
     * 「誰がやったか」の記録を入力で偽装できてしまう。この経路は
     * `agentcms template apply` 専用なので、固定でよい。
     */
    private const ACTOR = 'template-import';

    public function handle(CreateTerm $create): int
    {
        $raw = $this->readStdin();
        $input = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($input)) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => '標準入力の JSON を読み取れませんでした',
            ], failure: true);
        }

        $unknownRoot = array_diff(array_keys($input), self::ALLOWED_ROOT_KEYS);

        if ($unknownRoot !== []) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => '受け付けないキーがあります: '.implode(', ', $unknownRoot),
            ], failure: true);
        }

        $terms = $input['terms'] ?? null;

        if (! is_array($terms) || ! array_is_list($terms)) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => 'terms は配列（並び）で渡してください',
            ], failure: true);
        }

        if ($terms === []) {
            return $this->emit(['status' => 'success', 'created' => []], failure: false);
        }

        if (count($terms) > self::MAX_TERMS) {
            return $this->emit([
                'status' => 'validation_error',
                'message' => '一度に作成できる項目数の上限（'.self::MAX_TERMS.'）を超えています',
            ], failure: true);
        }

        foreach ($terms as $i => $term) {
            if (! is_array($term)) {
                return $this->emit([
                    'status' => 'validation_error',
                    'message' => "terms[{$i}] の形式が不正です",
                ], failure: true);
            }

            $unknown = array_diff(array_keys($term), self::ALLOWED_TERM_KEYS);

            if ($unknown !== []) {
                return $this->emit([
                    'status' => 'validation_error',
                    'message' => "terms[{$i}] に受け付けないキーがあります: ".implode(', ', $unknown),
                ], failure: true);
            }
        }

        $context = OperationContext::cli(self::ACTOR);

        try {
            $created = $this->createAll($create, $terms, $context);
        } catch (BatchAborted $e) {
            // 巻き戻し済み。**部分適用は残っていない**
            return $this->emit([
                'status' => $e->status,
                'message' => $e->getMessage(),
                'errors' => $e->errors,
                'index' => $e->index,
                'created' => [],
            ], failure: true);
        } catch (Throwable $e) {
            report($e);

            return $this->emit([
                'status' => 'conflict',
                'message' => '分類項目を作成できませんでした（変更はすべて取り消しました）',
                'created' => [],
            ], failure: true);
        }

        return $this->emit(['status' => 'success', 'created' => $created], failure: false);
    }

    /**
     * **1 つのトランザクションの中で**全件作る。
     *
     * `CreateTerm` は内部でも transaction を張るが、Laravel は入れ子を
     * savepoint として同じ接続で扱うので、外側を rollback すれば
     * 作成された行も `DatabaseAuditRecorder` が書いた監査行も一緒に消える。
     * （この前提は `ImportTermsCommandTest` で実データを見て確かめている）
     *
     * @param  list<mixed>  $terms
     * @return list<array<string, mixed>>
     *
     * @throws BatchAborted
     */
    private function createAll(CreateTerm $create, array $terms, OperationContext $context): array
    {
        return DB::transaction(function () use ($create, $terms, $context): array {
            $created = [];

            foreach ($terms as $i => $term) {
                /** @var array<string, mixed> $term */
                $result = $create->execute($term, $context);

                if ($result->status !== OperationStatus::Success) {
                    // 例外で抜けることが巻き戻しの合図。ここで return してはいけない
                    throw new BatchAborted(
                        (string) ($result->message ?? '分類項目を作成できませんでした'),
                        $result->status->value,
                        $result->errors,
                        (int) $i,
                    );
                }

                $created[] = $this->presentCreated($result);
            }

            return $created;
        });
    }

    /** @return array<string, mixed> */
    private function presentCreated(Result $result): array
    {
        $term = $result->data['term'] ?? [];

        return [
            'id' => is_array($term) ? ($term['id'] ?? null) : null,
            'slug' => is_array($term) ? ($term['slug'] ?? null) : null,
            'name' => is_array($term) ? ($term['name'] ?? null) : null,
            'taxonomy' => $result->meta['taxonomyKey'] ?? null,
        ];
    }
}

/**
 * batch を打ち切るための例外。
 *
 * **戻り値ではなく例外**にしているのは、`DB::transaction()` が
 * 例外でしか巻き戻さないため。return で抜けると commit されてしまう。
 */
final class BatchAborted extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        public readonly string $status,
        public readonly array $errors,
        public readonly int $index,
    ) {
        parent::__construct($message);
    }
}
