<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes;

use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * 下書き → 計画（Phase 9.4 / STAGE #17A）。
 *
 * **読み取りしかしない**。schema ファイル・モジュール・DB テーブル・
 * 管理画面 route の 4 面すべてで衝突を調べ、1 つでも当たれば計画を作らない。
 * 「schema だけ通ってテーブルで落ちる」ような半端な適用を、
 * 変更を始める前に排除するのがここの役目。
 */
final class ContentTypePlanner
{
    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypePaths $paths,
        private readonly Registrar $router,
    ) {}

    /**
     * @return array{plan: ContentTypePlan|null, errors: array<string, list<string>>}
     */
    public function plan(ContentTypeDraft $draft, string $timestamp): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        $id = $draft->identifier;

        if ($this->schemas->has($id->value)) {
            $errors['id'][] = "Content Type ID [{$id->value}] はすでに存在します";
        }

        if (is_file($this->paths->schemaFile($id))) {
            $errors['id'][] = "schema ファイルがすでに存在します（{$this->relative($this->paths->schemaFile($id))}）";
        }

        if (is_dir($this->paths->moduleDir($id))) {
            $errors['id'][] = "モジュール {$id->studly()} はすでに存在します";
        }

        /*
         * モデルが**ファイルとして**在るか（Phase 9.7 / STAGE 20F）。
         *
         * ここで `class_exists()` を使ってはいけない。2 つ壊れる:
         *
         * 1. 見つからなかったクラスを composer の ClassLoader が覚えてしまい
         *    （`missingClasses`）、このあと生成したモデルを**同じプロセスからは
         *    二度と読み込めなくなる**。作成が毎回巻き戻る。
         * 2. 逆に、一度失敗した作成でクラスだけ読み込まれていると、
         *    ファイルを片付けたあとも「すでに定義されています」と言い続ける。
         *
         * ID からモデルの置き場所は一意に決まる（PSR-4 の `Modules\` → `modules/`）。
         * 見るべきはその 1 ファイルであって、プロセスの記憶ではない。
         */
        if (is_file($this->paths->modelFile($id))) {
            $errors['id'][] = "モデル {$this->relative($this->paths->modelFile($id))} はすでに存在します";
        }

        // 別 ID の schema が同じテーブルを使っていないか（重複 table の拒否）
        foreach ($this->schemas->all() as $existing) {
            if ($existing->table === $id->table()) {
                $errors['id'][] = "テーブル [{$id->table()}] は Content Type [{$existing->id}] が使用中です";
                break;
            }
        }

        if ($this->tableExists($id->table())) {
            $errors['id'][] = "テーブル [{$id->table()}] はすでにデータベースに存在します";
        }

        if ($this->routeTaken($id->routeBase())) {
            $errors['id'][] = "管理画面の URL /admin/{$id->routeBase()} はすでに使われています";
        }

        if ($errors !== []) {
            return ['plan' => null, 'errors' => $errors];
        }

        return [
            'plan' => new ContentTypePlan(
                draft: $draft,
                schemaFile: $this->relative($this->paths->schemaFile($id)),
                modelFile: $this->relative($this->paths->modelFile($id)),
                migrationFile: $this->relative($this->paths->migrationFile($id, $timestamp)),
                migrationTimestamp: $timestamp,
                adminUrl: '/admin/'.$id->routeBase(),
                notes: $this->notes($draft),
            ),
            'errors' => [],
        ];
    }

    /**
     * 確認画面へ出す「今回やらないこと」。
     *
     * 期待と実装の差を **画面で先に伝える**（公開ページは STAGE #17A の対象外 — SPEC §1F）。
     *
     * @return list<string>
     */
    private function notes(ContentTypeDraft $draft): array
    {
        $notes = [
            '公開サイトのテンプレート・パーマリンク・メニューは作成されません（管理画面 / API / MCP まで）',
            '作成後にフィールドを削除・改名・型変更することは、この画面ではできません',
        ];

        if ($draft->referencesMedia()) {
            $notes[] = 'メディア項目は media テーブルへの外部キーになります'
                .'（必須のメディア項目は、参照中のメディアを削除できなくなります）';
        }

        return $notes;
    }

    /**
     * DB へ接続できない環境でも計画は立てられるようにする。
     *
     * 接続不能を「衝突なし」と誤読しないよう、適用直前の
     * `ContentTypeInstaller` が **必ずもう一度**確認する。
     */
    private function tableExists(string $table): bool
    {
        try {
            return SchemaFacade::hasTable($table);
        } catch (QueryException) {
            return false;
        }
    }

    private function routeTaken(string $base): bool
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if ($uri === 'admin/'.$base || str_starts_with($uri, 'admin/'.$base.'/')) {
                return true;
            }
        }

        return false;
    }

    /** 画面へ出すのは base_path からの相対パスだけ（サーバーの絶対パスを露出させない） */
    private function relative(string $absolute): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : basename($absolute);
    }
}
