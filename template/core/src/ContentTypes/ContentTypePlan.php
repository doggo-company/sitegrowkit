<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

/**
 * 「適用したら何が起きるか」の確定した計画（Phase 9.4 / STAGE #17A）。
 *
 * Preview → Confirm → Apply の **Preview の中身**であり、
 * 確認トークンが束縛される引数でもある。計画を作る時点では
 * ファイルも DB も一切変更していない。
 */
final readonly class ContentTypePlan
{
    /** @param  list<string>  $notes */
    public function __construct(
        public ContentTypeDraft $draft,
        public string $schemaFile,
        public string $modelFile,
        public string $migrationFile,
        public string $migrationTimestamp,
        public string $adminUrl,
        public array $notes,
    ) {}

    public function table(): string
    {
        return $this->draft->identifier->table();
    }

    /** @return class-string */
    public function modelClass(): string
    {
        return $this->draft->identifier->modelClass();
    }

    /**
     * 確認トークンを束縛する引数。
     *
     * **入力の同一性を表す最小の値**にする。タイムスタンプや採番済みの
     * field ID のような「実行ごとに変わる値」を混ぜると、
     * 同じ内容を確認して適用しただけで token が一致しなくなる
     * （`ContentTypeDraft::semanticFingerprint()` を参照）。
     *
     * 逆にフィールド構成が 1 文字でも変われば指紋は変わるので、
     * **別の対象へ確認を使い回すことはできない**。
     *
     * @return array<string, mixed>
     */
    public function confirmationArguments(): array
    {
        return [
            'id' => $this->draft->identifier->value,
            'fingerprint' => $this->draft->semanticFingerprint(),
        ];
    }

    /**
     * 管理画面のプレビューへ渡す表現。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->draft->identifier->value,
            'label' => $this->draft->label,
            'table' => $this->table(),
            'modelClass' => $this->modelClass(),
            'adminUrl' => $this->adminUrl,
            'files' => [
                'schema' => $this->schemaFile,
                'model' => $this->modelFile,
                // migration のファイル名の先頭は **適用した時刻**で決まる。
                // 確認画面で確定時刻を見せると、実際に作られる名前と食い違う。
                // 何が作られるかは伝えつつ、確定していない部分は伏せる
                'migration' => str_replace(
                    $this->migrationTimestamp,
                    '<生成時刻>',
                    $this->migrationFile,
                ),
            ],
            'fields' => array_map(
                static fn (FieldDraft $f): array => $f->toPreviewArray(),
                $this->draft->fields,
            ),
            'list' => $this->draft->adminList,
            'search' => $this->draft->adminSearch,
            'notes' => $this->notes,
        ];
    }
}
