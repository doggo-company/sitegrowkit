import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

import { slugifyFormKey } from '@/lib/form-key';

/**
 * フォーム識別子の規則（監査 指摘 41・42）。
 *
 * **保存側（PHP）と同じ表を読む。** 規則が 2 か所に在る限り、
 * 次にどちらかを触った人が同じずれを作る。片方だけ触れば、どちらかの試験が落ちる。
 *
 * 実際に起きていたずれ:
 * - `C`（1 文字）… 手で打つと断られるのに、名前から作ると通って `/forms/c` が配られた
 * - `"ab " × 30` … 60 文字で切った端にハイフンが残り `/forms/ab-…-ab-` になった
 *
 * **識別子はあとから変えられない**ので、ずれた分がそのまま配られる。
 *
 * ▷ **表がこの隣に在る理由。** この試験は `resources/` の下に在るので
 *   **利用者のプロジェクトへ配られる**。表を `tests/fixtures/` に置くと、
 *   そちらは配布境界の外なので、利用者の `agentcms verify` が無傷の
 *   プロジェクトで `ENOENT` で赤くなる（2026-08-30 に実測）。
 *   **配る側が配らない側を読まない。**逆向き（PHP の試験がここを読む）は安全。
 */
const CASES = (
    JSON.parse(
        readFileSync(
            resolve(__dirname, './form-key-cases.json'),
            'utf8',
        ),
    ) as { cases: { name: string; slug: string; note: string }[] }
).cases;

describe('slugifyFormKey', () => {
    it('表が空でない（空なら何も見ていない）', () => {
        expect(CASES.length).toBeGreaterThan(5);
    });

    it.each(CASES.map((c) => [c.note, c.name, c.slug]))(
        '%s',
        (_note, name, expected) => {
            expect(slugifyFormKey(name)).toBe(expected);
        },
    );
});
