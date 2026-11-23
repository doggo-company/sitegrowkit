<?php

declare(strict_types=1);

namespace Tests\Unit;

use AgentCms\Core\Forms\FormKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * フォーム識別子の規則（監査 指摘 41・42）。
 *
 * **画面（TypeScript）と同じ表を読む。** 規則が 2 か所に在る限り、
 * 次にどちらかを触った人が同じずれを作る。片方だけ触れば、どちらかの試験が落ちる。
 *
 * 実際に起きていたずれ:
 * - `C`（1 文字）… 手で打つと断られるのに、名前から作ると通って `/forms/c` が配られた
 * - `"ab " × 30` … 60 文字で切った端にハイフンが残り `/forms/ab-…-ab-` になった
 *
 * **識別子はあとから変えられない**ので、ずれた分がそのまま配られる。
 */
final class FormKeyTest extends TestCase
{
    /**
     * @return list<array{string, string, string}>
     */
    public static function cases(): array
    {
        /** @var array{cases: list<array{name: string, slug: string, note: string}>} $doc */
        $doc = json_decode(
            (string) file_get_contents(
                // 表は配る側（TS の試験の隣）に置く。配る側が配らない側を読むと、
                // 利用者のプロジェクトで vitest が ENOENT になる（2026-08-30 実測）
                __DIR__.'/../../resources/js/lib/__tests__/form-key-cases.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return array_map(
            static fn (array $c): array => [$c['note'], $c['name'], $c['slug']],
            $doc['cases'],
        );
    }

    #[DataProvider('cases')]
    public function test_共有の表どおりに作る(string $note, string $name, string $expected): void
    {
        $this->assertSame($expected, FormKey::slugify($name), $note);
    }

    /** 表が空になったら、この試験は何も見ていない */
    public function test_表が空でない(): void
    {
        $this->assertGreaterThan(5, count(self::cases()));
    }
}
