<?php

declare(strict_types=1);

namespace AgentCms\Core\Taxonomy;

/**
 * code-managed taxonomy 定義の read-only file registry（Phase 9.3B / STAGE 1・SPEC §J.4）。
 *
 * `content/taxonomies/*.json` を読むだけで、**DB には一切触れない**。
 * `ComponentRegistry` / `SchemaRegistry` と同じ形にしてあるので、
 * taxonomy を増やす作業は「JSON を 1 つ足す」だけになる。
 *
 * **セキュリティ境界**
 * - 読むディレクトリは **構築時に固定**され、呼び出し側から任意パスを渡せない
 * - ファイル名は `<key>.json` に限定し、`glob` の結果以外を開かない（path traversal 不可）
 * - 値は SQL 識別子として使わない（key は bind される値としてのみ扱う）
 */
final class TaxonomyDefinitionRegistry
{
    /** @var array<string, TaxonomyDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly string $directory) {}

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): TaxonomyDefinition
    {
        return $this->all()[$key]
            ?? throw new TaxonomyDefinitionException([
                "taxonomy [{$key}] の定義ファイルが content/taxonomies/ にありません",
            ]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * 全定義。**1 件でも不正なら例外**（部分的に読めたことにしない）。
     *
     * @return array<string, TaxonomyDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];
        $problems = [];

        foreach ($this->files() as $file) {
            $name = basename($file);
            $raw = @file_get_contents($file);

            if ($raw === false) {
                $problems[] = "{$name}: ファイルを読み取れません";

                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);

            if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                $problems[] = "{$name}: JSON として読み取れません";

                continue;
            }

            $errors = $this->validate($decoded, $name);

            if ($errors !== []) {
                array_push($problems, ...$errors);

                continue;
            }

            /** @var array{key: string, label: string, hierarchical: bool, managed_by: string} $decoded */
            $key = $decoded['key'];

            if (isset($definitions[$key])) {
                $problems[] = "{$name}: taxonomy key [{$key}] が重複しています（1 つの key につき定義は 1 ファイルだけです）";

                continue;
            }

            $definitions[$key] = new TaxonomyDefinition(
                key: $key,
                label: $decoded['label'],
                hierarchical: $decoded['hierarchical'],
                managedBy: TaxonomyManagedBy::Code,
                sourceFile: $name,
            );
        }

        if ($problems !== []) {
            throw new TaxonomyDefinitionException($problems);
        }

        ksort($definitions);

        return $this->definitions = $definitions;
    }

    /**
     * 読み取り対象ファイル。**固定ディレクトリ直下の *.json のみ**。
     *
     * @return list<string>
     */
    private function files(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $files = glob(rtrim($this->directory, '/').'/*.json');

        if ($files === false) {
            return [];
        }

        sort($files);

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<string> 日本語の問題説明
     */
    private function validate(array $raw, string $file): array
    {
        $problems = [];

        foreach (array_keys($raw) as $attribute) {
            if (! in_array($attribute, TaxonomyDefinition::ALLOWED_ATTRIBUTES, true)) {
                $name = is_string($attribute) ? $attribute : (string) $attribute;
                $problems[] = "{$file}: 未知の属性 [{$name}] があります（使えるのは "
                    .implode(' / ', TaxonomyDefinition::ALLOWED_ATTRIBUTES).' です）';
            }
        }

        foreach (TaxonomyDefinition::REQUIRED_ATTRIBUTES as $attribute) {
            if (! array_key_exists($attribute, $raw)) {
                $problems[] = "{$file}: 必須の属性 [{$attribute}] がありません";
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        $key = $raw['key'];

        if (! is_string($key) || $key === '') {
            return ["{$file}: key は空でない文字列にしてください"];
        }

        if (mb_strlen($key) > TaxonomyDefinition::KEY_MAX
            || preg_match(TaxonomyDefinition::KEY_PATTERN, $key) !== 1) {
            $problems[] = "{$file}: taxonomy key [{$key}] の形式が不正です"
                .'（小文字英字で始まり、小文字英数字と _ のみ・'.TaxonomyDefinition::KEY_MAX.' 文字以内）';
        }

        // ファイル名と key を一致させ、「どのファイルがどの key か」を機械的に決める
        if ($file !== $key.'.json') {
            $problems[] = "{$file}: ファイル名を [{$key}.json] にしてください（key と一致させます）";
        }

        if (! is_string($raw['label']) || trim($raw['label']) === '') {
            $problems[] = "{$file}: label は空でない文字列にしてください";
        } elseif (mb_strlen($raw['label']) > TaxonomyDefinition::LABEL_MAX) {
            $problems[] = "{$file}: label は ".TaxonomyDefinition::LABEL_MAX.' 文字以内にしてください';
        }

        if (! is_bool($raw['hierarchical'])) {
            $problems[] = "{$file}: hierarchical は true か false にしてください";
        }

        // 定義ファイルは code 管理の宣言に限る。cms は管理画面が SSoT（Phase 9.4）
        if ($raw['managed_by'] !== TaxonomyManagedBy::Code->value) {
            $problems[] = "{$file}: managed_by は \"code\" のみ指定できます"
                .'（cms 管理の taxonomy は定義ファイルで宣言しません）';
        }

        return $problems;
    }
}
