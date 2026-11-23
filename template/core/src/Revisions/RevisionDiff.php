<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

use AgentCms\Core\Schema\ContentSchema;

/**
 * 「その版へ戻すと何が起きるか」を、戻す前に言葉にする（Phase 9.8 / SPEC §F.2.2）。
 *
 * ## hash では説明にならない
 *
 * fingerprint が違うことは分かっても、**何がどう違うか**は言えない。
 * 「schema が変わっています」とだけ出して確認を押させるのは、確認になっていない。
 * だから保存済みの定義といまの定義を突き合わせ、**失われる項目を数え上げる**。
 *
 * ## 黙って部分復元しない
 *
 * 復元できない項目が 1 つでもあるなら、**何が失われるかを列挙してから**確認を取る。
 * 押したあとで「実はあの項目は戻っていません」は、取り返しがつかない。
 */
final class RevisionDiff
{
    /**
     * @param  list<array<string, mixed>>  $storedManifest  版の時点の定義
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     restorable: list<string>,
     *     lost: list<array{key: string, reason: string}>,
     *     added: list<string>,
     *     identical: bool,
     * }
     */
    public static function between(array $storedManifest, ContentSchema $schema, array $snapshot): array
    {
        $current = SchemaManifest::canonical($schema);

        $storedByIdentity = self::byIdentity($storedManifest);
        $currentByIdentity = self::byIdentity($current);

        $restorable = [];
        $lost = [];

        foreach ($storedByIdentity as $identity => $field) {
            $now = $currentByIdentity[$identity] ?? null;

            if ($now === null) {
                /*
                 * その項目は schema から消えている。
                 *
                 * `identity` は `id ?? key` なので、**ID を持たない型で key を変えた**場合も
                 * ここに来る（別の項目として扱われる）。実測どおりの制約なので隠さず出す。
                 */
                $lost[] = ['key' => (string) $field['key'], 'reason' => '項目が削除されています'];

                continue;
            }

            if ($now['type'] !== $field['type']) {
                // 型が変わると値の意味が変わる。戻さない
                $lost[] = [
                    'key' => (string) $now['key'],
                    'reason' => "項目の種類が変わっています（{$field['type']} → {$now['type']}）",
                ];

                continue;
            }

            $restorable[] = (string) $now['key'];
        }

        $added = [];

        foreach ($currentByIdentity as $identity => $field) {
            if (! isset($storedByIdentity[$identity])) {
                // 版に無い項目。**現在の値をそのまま残す**（空で上書きしない）
                $added[] = (string) $field['key'];
            }
        }

        sort($restorable);
        sort($added);

        return [
            'restorable' => $restorable,
            'lost' => $lost,
            'added' => $added,
            'identical' => $lost === [] && $added === [] && SchemaManifest::equivalent($storedManifest, $current),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private static function byIdentity(array $manifest): array
    {
        $indexed = [];

        foreach ($manifest as $field) {
            $indexed[(string) $field['identity']] = $field;
        }

        return $indexed;
    }
}
