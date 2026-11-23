<?php

declare(strict_types=1);

namespace AgentCms\Core\FieldGroups;

use AgentCms\Core\Schema\ContentSchema;
use AgentCms\Core\Schema\Field;
use AgentCms\Core\Schema\FieldStorage;
use InvalidArgumentException;
use JsonException;
use TypeError;
use ValueError;

/**
 * DB に保存された 1 つの Field Group（Phase 9.5-A / ADR-0015）。
 *
 * ## 壊れた定義を例外にしない
 *
 * `SchemaRegistry` は全画面・全 API が通る場所なので、DB の 1 行が壊れているだけで
 * 製品全体が起動しなくなるのは正しくない。かといって**黙って空にすると
 * 「作ったのに出ない」の原因が誰にも分からなくなる**。
 *
 * そこで壊れた定義は
 *
 * - **項目を 1 つも足さない**（fail closed）
 * - `$invalidReason` に理由を残し、**一覧に「定義エラー」として出す**
 *
 * の 2 つを同時に満たす。silent にしないための形である。
 *
 * **DB 障害はここまで来ない**（`FieldGroupRepository` が投げる）。
 * 「壊れた定義」と「DB が読めない」を同じ扱いにしない。
 */
final readonly class FieldGroupRecord
{
    /**
     * @param  list<array<string, mixed>>  $declarations  検証済みの生の Field 宣言
     *                                                    （`SchemaRegistry` がそのまま重ねる）
     * @param  list<Field>  $fields  `$declarations` と 1 対 1。件数と型の確認に使う
     * @param  list<FieldGroupLocation>  $locations
     */
    private function __construct(
        public string $id,
        public string $key,
        public string $label,
        public int $sortOrder,
        public ?FieldGroupStatus $status,
        public string $rawStatus,
        public array $declarations,
        public array $fields,
        public array $locations,
        public ?string $invalidReason,
    ) {}

    /**
     * @param  array<string, mixed>  $row  `field_groups` の 1 行
     * @param  list<FieldGroupLocation>  $locations
     */
    public static function fromRow(array $row, array $locations): self
    {
        $rawStatus = (string) ($row['status'] ?? '');
        $status = FieldGroupStatus::tryFrom($rawStatus);

        $declarations = [];
        $fields = [];
        $reason = null;

        if ($status === null) {
            $reason = "未知の状態 [{$rawStatus}] です";
        } else {
            try {
                [$declarations, $fields] = self::parseFields($row['fields'] ?? null);
            } catch (InvalidArgumentException $e) {
                $reason = $e->getMessage();
            }
        }

        return new self(
            id: (string) ($row['id'] ?? ''),
            key: (string) ($row['key'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            sortOrder: (int) ($row['sort_order'] ?? 0),
            status: $status,
            rawStatus: $rawStatus,
            declarations: $declarations,
            fields: $fields,
            locations: $locations,
            invalidReason: $reason,
        );
    }

    /** 定義として読めているか */
    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    /**
     * この Field Group が Content Type へ項目を足すか。
     *
     * `published` かつ定義が読めていることが条件。draft / archived / 定義エラーは
     * **1 つも足さない**（ADR-0015 fail closed）。
     */
    public function contributesFields(): bool
    {
        return $this->isValid() && $this->status?->contributesFields() === true;
    }

    /**
     * 検証済みの Field 宣言を読む。
     *
     * **`Field::fromArray()` の検証をそのまま使う。** 型の一覧や必須項目の規則を
     * ここへ書き写すと、2 か所が食い違ったときにどちらが正しいか分からなくなる。
     *
     * @return array{0: list<array<string, mixed>>, 1: list<Field>}
     *
     * @throws InvalidArgumentException 定義として読めないとき
     */
    private static function parseFields(mixed $raw): array
    {
        $decoded = self::decode($raw);

        $declarations = [];
        $fields = [];
        $seen = [];

        foreach ($decoded as $index => $entry) {
            // **形を先に確かめる**（Phase 9.5-A CORRECTION）。
            // `Field::fromArray()` は形が正しい前提で意味を見るので、
            // `choices` が文字列といった入力は TypeError / 警告になり、
            // fail closed をすり抜けて全画面を 500 にする（`FieldShape` 参照）
            FieldShape::assert($entry, "fields[{$index}]");

            /** @var array<string, mixed> $entry */
            // **保存先はここで足す**（Phase 9.5-B / owner 決定 G2-(a)）。
            // overlay で足した項目は対象テーブルにカラムが無いので、型に関わらず
            // `data` JSON へ入れる。DB の宣言に storage は書かせない（`FieldShape`）
            $declaration = FieldGroupLimits::withRepeaterBounds(
                [...$entry, 'storage' => FieldStorage::Json->value],
                "fields[{$index}]",
            );

            try {
                $field = Field::fromArray($declaration);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("fields[{$index}]: {$e->getMessage()}", previous: $e);
            } catch (TypeError|ValueError $e) {
                // `FieldShape` が主の防波堤で、ここは**取りこぼし用の網**。
                // 入力に由来する 2 つだけを変換し、Error / RuntimeException などの
                // 実装の不具合は**そのまま投げる**（無差別に握りつぶさない）
                throw new InvalidArgumentException(
                    "fields[{$index}]: 定義の形が正しくありません",
                    previous: $e,
                );
            }

            // 分類の割り当て用に予約された key は Content Type 側で例外になる。
            // ここで止めないと **overlay を足した瞬間に全画面が落ちる**
            if (in_array($field->key, ContentSchema::RESERVED_FIELD_KEYS, true)) {
                throw new InvalidArgumentException(
                    "fields[{$index}]: [{$field->key}] は分類の割り当て用に予約されています",
                );
            }

            if (isset($seen[$field->key])) {
                throw new InvalidArgumentException("フィールド [{$field->key}] が重複しています");
            }

            $seen[$field->key] = true;
            $declarations[] = $declaration;
            $fields[] = $field;
        }

        // 総数は **入れ子を含めて**数える（Phase 9.5-C / ADR-0017）
        $total = FieldGroupLimits::countFields($declarations);

        if ($total > FieldGroupLimits::MAX_FIELDS) {
            throw new InvalidArgumentException(FieldGroupLimits::fieldCountMessage($total));
        }

        return [$declarations, $fields];
    }

    /**
     * @return list<mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function decode(mixed $raw): array
    {
        // ドライバによって json カラムが文字列で来るか配列で来るかが変わる
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw)) {
            try {
                $decoded = json_decode($raw, true, FieldGroupLimits::JSON_DEPTH, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException("fields の JSON を読めません: {$e->getMessage()}", previous: $e);
            }
        } else {
            throw new InvalidArgumentException('fields が空です');
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('fields は配列で指定してください');
        }

        return $decoded;
    }
}
