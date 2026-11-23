<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationClassification;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * 監査の取りこぼしを **構造で** 止める（STAGE #18A / Correction 2-5）。
 *
 * ## なぜ個別テストでは足りないのか
 *
 * 「この operation の forbidden が記録される」を 1 本ずつ書いても、
 * **新しく足された operation** や **後から増えた return** は素通りする。
 * 実際 Correction 2 のレビューでは、6 つの Service を直したあとにも
 * 記録を通らない return が残っていた。
 *
 * そこで規約そのものをテストにする。
 *
 * | 規約 | 中身 |
 * | --- | --- |
 * | 列挙 | `Operation` を実装する全クラスを走査する（一覧を手で書かない） |
 * | 対象 | `classification()` が Read 以外のもの |
 * | 規則 | `execute()` の **すべての return** が `OperationAudit` を通る |
 *
 * 「通る」は再帰的に判定する。`$this->outcome->record()` を直接返してもよいし、
 * **同じクラスの private メソッドで、そのメソッドの return がすべて規約を満たす**
 * ものを返してもよい（`PatchPageDocument::record()` のような 1 か所への集約）。
 *
 * closure の中の `return` は operation の結末ではないので数えない
 * （`DB::transaction(fn () => ...)` の内側など）。
 */
final class OperationAuditCoverageTest extends TestCase
{
    /** 記録口として認める呼び出し */
    private const AUDIT_CALLS = [
        '$this->outcome->record(',
        '$this->outcome->recordOrFlag(',
    ];

    /** 走査するディレクトリ（project 層 / core / modules すべて） */
    private const ROOTS = ['app', 'core/src', 'modules'];

    public function test_全てのnon_read_operationが列挙できる(): void
    {
        $operations = $this->operations();

        // 一覧が空 = 走査に失敗している。空を「合格」と読ませない
        $this->assertGreaterThan(
            20,
            count($operations),
            'Operation の走査に失敗している（パスかオートロードを確認すること）',
        );

        // 代表的なものが確実に含まれているか（走査条件の取り違え検出）
        foreach ([
            'AgentCms\Core\Application\Content\UpdateContent',
            'AgentCms\Core\Application\ContentTypes\CreateContentType',
            'AgentCms\Core\Application\Pages\PatchPageDocument',
            'AgentCms\Core\Application\Pages\PublishPage',
            'AgentCms\Core\Application\Pages\RestorePage',
            'AgentCms\Core\Application\Pages\UpdatePageMetadata',
            'AgentCms\Core\Application\Settings\UpdateSettings',
            'AgentCms\Core\Application\Taxonomy\CreateTerm',
            'AgentCms\Core\Application\Taxonomy\DeleteTerm',
            'AgentCms\Core\Application\Taxonomy\ReorderTerms',
            'AgentCms\Core\Application\Taxonomy\UpdateTerm',
            'App\Application\Users\ChangeUserRole',
            'App\Application\Users\DeactivateUser',
            'App\Application\Users\InviteUser',
            'App\Application\Users\UpdateUser',
            'Modules\Contact\Application\UpdateContactStatus',
            'Modules\Media\Application\DeleteMedia',
            'Modules\Media\Application\UploadMedia',
        ] as $expected) {
            $this->assertContains($expected, $operations, "{$expected} が走査から漏れている");
        }
    }

    public function test_non_read_operationのexecuteは全ての結末をauditへ通す(): void
    {
        $violations = [];

        foreach ($this->operations() as $class) {
            $reflection = new ReflectionClass($class);

            if ($this->declaredClassification($reflection) === OperationClassification::Read) {
                continue;
            }

            if (! $reflection->hasMethod('execute')) {
                $violations[] = "{$class}: execute() が無い";

                continue;
            }

            foreach ($this->unauditedReturns($reflection, 'execute') as $expression) {
                $violations[] = "{$class}::execute() — 記録を通らない return: {$expression}";
            }
        }

        $this->assertSame([], $violations, "監査を通らない結末がある：\n".implode("\n", $violations));
    }

    /** READ は記録しない方針のまま（過剰記録の逆行を防ぐ） */
    public function test_read_operationはauditを要求されない(): void
    {
        $read = [];

        foreach ($this->operations() as $class) {
            if ($this->declaredClassification(new ReflectionClass($class)) === OperationClassification::Read) {
                $read[] = $class;
            }
        }

        $this->assertNotEmpty($read, 'Read 分類の operation が 1 つも見つからない');
        $this->assertFalse(
            OperationClassification::Read->shouldAudit(),
            'READ を記録対象へ変えるなら、この方針転換は ADR で決めること',
        );
    }

    /**
     * `Operation` を実装する具象クラスをすべて集める。
     *
     * @return list<class-string>
     */
    private function operations(): array
    {
        $found = [];

        foreach (self::ROOTS as $root) {
            $path = base_path($root);

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach ((new Finder)->files()->in($path)->name('*.php') as $file) {
                $class = $this->classIn($file->getRealPath());

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || ! $reflection->implementsInterface(Operation::class)) {
                    continue;
                }

                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    /** namespace + class 宣言から FQCN を組む（オートロードに任せず読む） */
    private function classIn(string $file): ?string
    {
        $source = (string) file_get_contents($file);

        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
            return null;
        }

        if (! preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $name)) {
            return null;
        }

        return trim($ns[1]).'\\'.$name[1];
    }

    /** `classification()` が宣言している値をソースから読む（生成に依存しない） */
    private function declaredClassification(ReflectionClass $class): ?OperationClassification
    {
        if (! $class->hasMethod('classification')) {
            return null;
        }

        $body = $this->methodSource($class->getMethod('classification'));

        if (! preg_match('/OperationClassification::(\w+)/', $body, $m)) {
            return null;
        }

        return match ($m[1]) {
            'Read' => OperationClassification::Read,
            'Write' => OperationClassification::Write,
            'Destructive' => OperationClassification::Destructive,
            'ExternalEffect' => OperationClassification::ExternalEffect,
            default => null,
        };
    }

    /**
     * 記録を通らない return の式を列挙する。
     *
     * @param  list<string>  $visited  private メソッドの循環参照よけ
     * @return list<string>
     */
    private function unauditedReturns(ReflectionClass $class, string $method, array $visited = []): array
    {
        if (in_array($method, $visited, true) || ! $class->hasMethod($method)) {
            return [];
        }

        $visited[] = $method;
        $bad = [];

        foreach ($this->topLevelReturns($class->getMethod($method)) as $expression) {
            if ($this->isAuditCall($expression)) {
                continue;
            }

            // 同じクラスの private メソッドへ委譲しているなら、その先を見る
            $delegate = $this->delegatedMethod($class, $expression);

            if ($delegate !== null) {
                $bad = [...$bad, ...$this->unauditedReturns($class, $delegate, $visited)];

                continue;
            }

            $bad[] = $expression;
        }

        return $bad;
    }

    private function isAuditCall(string $expression): bool
    {
        foreach (self::AUDIT_CALLS as $call) {
            if (str_starts_with($expression, $call)) {
                return true;
            }
        }

        return false;
    }

    /** `$this->foo(` 形式で、同じクラスに private メソッドがあれば名前を返す */
    private function delegatedMethod(ReflectionClass $class, string $expression): ?string
    {
        if (! preg_match('/^\$this->(\w+)\(/', $expression, $m)) {
            return null;
        }

        return $class->hasMethod($m[1]) ? $m[1] : null;
    }

    /**
     * メソッド本体の **closure を除いた** return 式を集める。
     *
     * @return list<string>
     */
    private function topLevelReturns(ReflectionMethod $method): array
    {
        $tokens = token_get_all('<?php '.$this->methodSource($method));

        $depth = 0;
        $closureAt = [];
        $pendingClosure = false;
        $returns = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_FUNCTION) {
                // closure（無名関数）の本体は operation の結末ではない
                $pendingClosure = true;

                continue;
            }

            /*
             * 文字列内の `"{$user->name}"` は開き側だけが T_CURLY_OPEN で、
             * 閉じ側は素の `}` として出てくる。ここを数えないと深さがずれ、
             * closure の内側を「本体の return」と読み違える。
             */
            if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }

            if ($token === '{') {
                $depth++;

                if ($pendingClosure) {
                    $closureAt[] = $depth;
                    $pendingClosure = false;
                }

                continue;
            }

            if ($token === '}') {
                if ($closureAt !== [] && end($closureAt) === $depth) {
                    array_pop($closureAt);
                }

                $depth--;

                continue;
            }

            if (! is_array($token) || $token[0] !== T_RETURN || $closureAt !== [] || $depth < 1) {
                continue;
            }

            $returns[] = $this->expressionAfter($tokens, $i);
        }

        return $returns;
    }

    /**
     * `return` の直後から `;` までを 1 行の文字列に畳む。
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function expressionAfter(array $tokens, int $index): string
    {
        $parts = [];

        for ($i = $index + 1, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === ';') {
                break;
            }

            $parts[] = trim($text);
        }

        return preg_replace('/\s+/', ' ', trim(implode('', $parts))) ?? '';
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $lines = (array) file($file);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
