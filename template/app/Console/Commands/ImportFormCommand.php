<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentCms\Core\Application\FieldGroups\CreateFieldGroup;
use AgentCms\Core\Application\Forms\GetForm;
use AgentCms\Core\Application\Forms\SaveForm;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\FieldGroups\FieldGroupDraft;
use AgentCms\Core\Forms\FormFields;
use AgentCms\Core\Forms\FormKey;
use App\Console\Concerns\SpeaksJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Template Import が取り込んだフォームを CMS のフォームとして起こす（R-12 Stage 2 / D）。
 *
 * ## なぜ要るのか
 *
 * 取り込んだ `/contact` を CMS のフォームへ切り替えるとき、**フォームの実体が無いと
 * 切り替えた瞬間に画面が空になる**。原本の HTML には項目の名前・型・表示名が在るので、
 * それを 1 回だけデータにする。
 *
 * ## 既に在るなら触らない
 *
 * 同じ識別子のフォームが在れば **何もしない**（`action: kept`）。
 * **運用者が組み替えたものを import が上書きしてよい理由が無い**
 * （`MenuSeeder` と同じ作法）。再 apply しても項目が戻らないのはそのためである。
 *
 * ## 作る順序
 *
 * **束が先、フォームが後。** 束の割当先（`kind: form`）は**フォームの存在を検査しない**
 * （検査するのは `content_type` だけ — `FieldGroupDraft::readLocations()`）ので、
 * 束 → フォームの順なら 1 往復で済む。逆順にすると、フォームを作ってから
 * `field_group_id` を入れ直す 2 回目の書き込みが要る。
 *
 * ## 通らないものは黙って直さない
 *
 * 型が公開フォームで使えない・識別子が機械名として使えない、のどちらも
 * **その場で止める**（どの項目が原因かを言う）。**勝手に別の型へ寄せたり
 * 識別子を作り変えたりしない** — 取り込みは「原本にこう書いてあった」を運ぶ役で、
 * 直し方を決めるのは呼び出し側（plan）である。
 *
 * ## AI へは公開しない
 *
 * `agentcms:import-terms` と同じく CLI 専用の内部経路で、`OperationCatalog` にも
 * MCP tool にも API にも足さない。作成そのものは既存の Application Service を通り、
 * validation / policy / 監査を迂回しない。
 *
 * ## 入出力
 *
 * 入力は **stdin の JSON** のみ（argv に載せない）。
 *
 * ```json
 * {"form": {"key": "contact", "name": "お問い合わせ", "status": "draft",
 *           "fields": [{"key": "name", "type": "text", "label": "お名前", "required": true}]}}
 * ```
 *
 * 出力は stdout に結果 JSON 1 行。
 */
final class ImportFormCommand extends Command
{
    use SpeaksJson;

    protected $signature = 'agentcms:import-form';

    protected $description = 'Template Import 用: 取り込んだフォームを CMS のフォームとして作る（既に在れば触らない）';

    /** 1 つのフォームに入れられる項目の上限（暴走した plan を止める） */
    private const MAX_FIELDS = 100;

    private const ALLOWED_ROOT_KEYS = ['form'];

    private const ALLOWED_FORM_KEYS = ['key', 'name', 'status', 'fields'];

    private const ALLOWED_FIELD_KEYS = ['key', 'type', 'label', 'required'];

    /** 作ってよい公開状態。**archived は作らない**（作った瞬間に隠すのは意味が無い） */
    private const ALLOWED_STATUSES = ['draft', 'published'];

    /**
     * 監査に残る呼び出し元。
     *
     * **外部入力にしない**（`ImportTermsCommand` と同じ理由 — 入力で偽装できてしまう）。
     */
    private const ACTOR = 'template-import';

    public function handle(CreateFieldGroup $createGroup): int
    {
        $raw = $this->readStdin();
        $input = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($input)) {
            return $this->refuse('標準入力の JSON を読み取れませんでした');
        }

        $unknownRoot = array_diff(array_keys($input), self::ALLOWED_ROOT_KEYS);

        if ($unknownRoot !== []) {
            return $this->refuse('受け付けないキーがあります: '.implode(', ', $unknownRoot));
        }

        $form = $input['form'] ?? null;

        if (! is_array($form) || array_is_list($form)) {
            return $this->refuse('form はオブジェクトで渡してください');
        }

        $unknownForm = array_diff(array_keys($form), self::ALLOWED_FORM_KEYS);

        if ($unknownForm !== []) {
            return $this->refuse('form に受け付けないキーがあります: '.implode(', ', $unknownForm));
        }

        $key = is_string($form['key'] ?? null) ? trim($form['key']) : '';
        $name = is_string($form['name'] ?? null) ? trim($form['name']) : '';
        $status = (string) ($form['status'] ?? 'draft');

        if ($key === '' || $name === '') {
            return $this->refuse('form の key と name は必須です');
        }

        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            return $this->refuse('form の status は '.implode(' / ', self::ALLOWED_STATUSES).' のいずれかです');
        }

        /*
         * **識別子はここで見る。**
         *
         * 見ないと、先に作る束のほうが落ちて「項目の束を作成できませんでした」という
         * **原因の分からないエラー**になる（束の key は form の key から作るため）。
         * 判定は `FormKey::PATTERN` を**借りる** — 書き写すと同じ規則が 2 か所になる。
         */
        if (preg_match(FormKey::PATTERN, $key) !== 1) {
            return $this->refuse("form の key [{$key}] は識別子として使えません"
                .'（英小文字で始まり、英小文字・数字・_・- のみ / 2〜64 文字）');
        }

        $fields = $this->readFields($form['fields'] ?? null);

        if (is_string($fields)) {
            return $this->refuse($fields);
        }

        /*
         * **既に在れば触らない。**
         *
         * 運用者が項目を足した・改名したあとで再 apply されても、
         * その手を無かったことにしない（B-31 で編集できるようにしたばかりである）。
         */
        if (GetForm::find($key) !== null) {
            return $this->emit([
                'status' => 'success',
                'action' => 'kept',
                'key' => $key,
                'message' => "フォーム [{$key}] は既に在るので触りませんでした",
            ], failure: false);
        }

        $context = OperationContext::cli(self::ACTOR);

        try {
            $created = $this->create($createGroup, $key, $name, $status, $fields, $context);
        } catch (ImportFormAborted $e) {
            // 巻き戻し済み。**束だけ在ってフォームが無い、は残らない**
            return $this->emit([
                'status' => $e->status,
                'action' => 'none',
                'message' => $e->getMessage(),
                'errors' => $e->errors,
                // どちらで落ちたか（0 = 項目の束 / 1 = フォーム）
                'step' => $e->step,
            ], failure: true);
        } catch (Throwable $e) {
            report($e);

            return $this->emit([
                'status' => 'conflict',
                'action' => 'none',
                'message' => 'フォームを作成できませんでした（変更はすべて取り消しました）',
            ], failure: true);
        }

        return $this->emit($created, failure: false);
    }

    /**
     * 項目の読み取り。**通らないものは黙って直さず、理由を返す。**
     *
     * @return list<array<string, mixed>>|string 文字列はエラー文
     */
    private function readFields(mixed $raw): array|string
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            return 'form の fields は 1 件以上の配列（並び）で渡してください';
        }

        if (count($raw) > self::MAX_FIELDS) {
            return '一度に作れる項目数の上限（'.self::MAX_FIELDS.'）を超えています';
        }

        $fields = [];
        $seen = [];

        foreach ($raw as $i => $field) {
            if (! is_array($field) || array_is_list($field)) {
                return "fields[{$i}] の形式が不正です";
            }

            $unknown = array_diff(array_keys($field), self::ALLOWED_FIELD_KEYS);

            if ($unknown !== []) {
                return "fields[{$i}] に受け付けないキーがあります: ".implode(', ', $unknown);
            }

            $key = is_string($field['key'] ?? null) ? trim($field['key']) : '';
            $type = is_string($field['type'] ?? null) ? trim($field['type']) : '';
            $label = is_string($field['label'] ?? null) ? trim($field['label']) : '';

            if ($key === '' || $label === '') {
                return "fields[{$i}] の key と label は必須です";
            }

            /*
             * **機械名として使えない識別子は、ここで止める。**
             * `your-name` のような HTML の name をそのまま持ち込ませない —
             * 直し方（改名するか捨てるか）を決めるのは plan 側である。
             */
            if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                return "fields[{$i}] の key [{$key}] は項目の識別子として使えません"
                    .'（英小文字で始まり、英小文字・数字・_ のみ）';
            }

            if (isset($seen[$key])) {
                return "fields[{$i}] の key [{$key}] が重複しています";
            }

            // **公開フォームで使える型だけ。**勝手に別の型へ寄せない
            if (! in_array($type, FormFields::PUBLIC_TYPES, true)) {
                return "fields[{$i}]（{$label}）の型 [{$type}] は公開フォームでは使えません"
                    .'（'.implode(' / ', FormFields::PUBLIC_TYPES).'）';
            }

            $seen[$key] = true;
            $fields[] = [
                'key' => $key,
                'type' => $type,
                'label' => $label,
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        return $fields;
    }

    /**
     * **1 つのトランザクションで束とフォームを作る。**
     *
     * 片方だけ残ると「項目はあるのにフォームが無い」「フォームはあるのに項目が空」になり、
     * どちらも運用者からは直せない（識別子はあとから変えられない）。
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     *
     * @throws ImportFormAborted
     */
    private function create(
        CreateFieldGroup $createGroup,
        string $key,
        string $name,
        string $status,
        array $fields,
        OperationContext $context,
    ): array {
        return DB::transaction(function () use (
            $createGroup, $key, $name, $status, $fields, $context
        ): array {
            // 束が先。割当先（kind: form）はフォームの存在を検査しない
            $groupResult = $createGroup->execute([
                'key' => $this->groupKey($key),
                'label' => $this->groupLabel($name),
                'locations' => [['kind' => 'form', 'value' => $key]],
                'fields' => $fields,
            ], $context);

            if ($groupResult->status !== OperationStatus::Success) {
                throw new ImportFormAborted(
                    (string) ($groupResult->message ?? '項目の束を作成できませんでした'),
                    $groupResult->status->value,
                    $groupResult->errors,
                    0,
                );
            }

            $group = $groupResult->data['fieldGroup'] ?? [];
            $groupId = is_array($group) ? (string) ($group['id'] ?? '') : '';

            if ($groupId === '') {
                throw new ImportFormAborted('作成した項目の束の id を取得できませんでした', 'conflict', [], 0);
            }

            /*
             * 🔴 **束を作ったあとに、読み手を作り直す。**
             *
             * `FieldGroupRepository` は **1 instance につき 1 度しか読まない**（memoization）。
             * `CreateFieldGroup` は key の空きを見るために**書き込む前に**全件を読むので、
             * その instance は「新しい束が無かった頃」を覚えたままになる。
             * 同じ instance を持つ `SaveForm` に公開を頼むと、
             * **「その入力項目の束はありません」で断られる**（実測。これで 1 度落ちた）。
             *
             * scoped を捨てて `SaveForm` を作り直せば、**同じ接続**で読み直すので
             * トランザクションの中の行もちゃんと見える。
             */
            app()->forgetScopedInstances();

            $formResult = app(SaveForm::class)->execute([
                'key' => $key,
                'name' => $name,
                'status' => $status,
                'field_group_id' => $groupId,
            ], $context);

            if ($formResult->status !== OperationStatus::Success) {
                throw new ImportFormAborted(
                    (string) ($formResult->message ?? 'フォームを作成できませんでした'),
                    $formResult->status->value,
                    $formResult->errors,
                    1,
                );
            }

            return [
                'status' => 'success',
                'action' => 'created',
                'key' => $key,
                'fieldGroupId' => $groupId,
                'fields' => count($fields),
                'formStatus' => $status,
            ];
        });
    }

    /**
     * 束の識別子。**上限（64）に収める。**
     *
     * form の key は最大 63 文字なので、`form_` を足すと 68 文字になり得る。
     * 切り詰めた結果が既存の束とぶつかったら `assertKeyIsFree()` が落とし、
     * トランザクションごと巻き戻る（**黙って別の束へ入れない**）。
     */
    private function groupKey(string $formKey): string
    {
        return mb_substr('form_'.str_replace('-', '_', $formKey), 0, FieldGroupDraft::KEY_MAX);
    }

    /**
     * 束の表示名。**上限（100）に収める。**
     *
     * `forms.name` は 120 文字まで在り得るので、そのまま足すと超える。
     * **これは利用者が書いた文言ではなく、こちらが組み立てている内部名**なので、
     * 「黙って直さない」の対象ではない（利用者の入力は 1 文字も変えていない）。
     */
    private function groupLabel(string $formName): string
    {
        $suffix = 'の項目';

        return mb_substr($formName, 0, FieldGroupDraft::LABEL_MAX - mb_strlen($suffix)).$suffix;
    }

    /**
     * 受け付けない入力を JSON で断る。
     *
     * **`fail()` という名前は使えない** — `Illuminate\\Console\\Command` が
     * 同名の public メソッドを持っており、private で上書きすると
     * **クラスを読み込んだ瞬間に fatal になる**（phpstan が実際にそこで落ちた）。
     */
}

/**
 * この import を打ち切るための例外。
 *
 * **`ImportTermsCommand` の `BatchAborted` を使い回さない。** あちらは同じ
 * namespace だが**別ファイルの中で宣言されている**ので、PSR-4 の autoload では
 * 単独で解決できない（あのファイルが先に読まれたときだけ動く）。
 * **たまたま動く依存**を作らない。
 *
 * 戻り値ではなく例外なのは `DB::transaction()` が例外でしか巻き戻さないためである
 * （return で抜けると commit される）。
 */
final class ImportFormAborted extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        public readonly string $status,
        public readonly array $errors,
        public readonly int $step,
    ) {
        parent::__construct($message);
    }
}
