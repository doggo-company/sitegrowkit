import { describe, expect, it } from 'vitest';
import { customerConfirmation, labelsByKey } from '@/lib/field-confirmation';
import { noStoredValues } from '@/lib/field-confirmation';
import { assignFieldKeys } from '@/lib/field-key';
import type { FieldRow } from '@/types/field-group-form';
import { emptyFieldRow } from '@/types/field-group-form';
import type { OperationConfirmation } from '@/types/operation';

const row = (over: Partial<FieldRow> = {}): FieldRow => ({
    ...emptyFieldRow('text'),
    ...over,
});

const confirmation = (
    summary: string,
    impact: string[],
): OperationConfirmation => ({
    token: 't',
    summary,
    impact,
    expiresAt: '2026-08-21T00:00:00Z',
});

describe('確認ダイアログの言い換え', () => {
    it('見出しから技術語彙が消える', () => {
        const result = customerConfirmation(
            confirmation(
                'カスタムフィールド「お問い合わせ」の定義を変更します',
                [],
            ),
            {},
        );

        expect(result?.summary).toBe('このフォームの入力項目を変更します');
    });

    it('影響の行から技術語彙が消える', () => {
        // いまサーバーが返す文言（B-32 で実測に合わせて直したあとのもの）
        const result = customerConfirmation(
            confirmation('x', [
                'field_1: 必須フィールドの追加は、既存データの埋め戻しが必要です',
                '入力済みの値が消えることはありません。',
            ]),
            { field_1: 'お名前' },
        );

        // 11 周目 R11-2 から、必須の追加は店の人の言葉で言う
        expect(result?.impact).toEqual([
            'お名前: 必須の項目を足します。これから送る人は入力が必要になります。すでに届いた送信では、この項目は空のまま残ります。',
            '入力済みの値が消えることはありません。',
        ]);
    });

    /**
     * **古い言い方が残っていても言い換える。**
     * `束` はいまの確認文には出てこないが、辞書から外すと
     * 「どこかに残っていたら素通りする」に戻る。守りは残す。
     */
    it('古い言い方が残っていても言い換える', () => {
        const result = customerConfirmation(
            confirmation('x', ['束へ戻せば再び表示されます。']),
            {},
        );

        expect(result?.impact[0]).toBe('入力項目へ戻せば再び表示されます。');
    });

    /**
     * **事実は 1 つも変えない。** 言い換えるのは呼び名だけで、
     * 「データは失われます」を柔らかくしたりしない。
     */
    it('事実の部分はそのまま残る', () => {
        const result = customerConfirmation(
            confirmation('x', [
                'field_1: この項目は画面に出なくなります。入力済みの値は消えません',
            ]),
            { field_1: 'お名前' },
        );

        expect(result?.impact[0]).toContain('入力済みの値は消えません');
    });

    it('知らないキーは作り話をせずそのまま出す', () => {
        const result = customerConfirmation(
            confirmation('x', ['unknown_key: 表示名の変更です']),
            { field_1: 'お名前' },
        );

        expect(result?.impact[0]).toBe('unknown_key: 表示名の変更です');
    });

    it('確認が無いときは何も作らない', () => {
        expect(customerConfirmation(null, {})).toBeNull();
    });

    /**
     * **消した項目こそ、名前で呼べないと困る。**
     *
     * 画面の行からは消えているので、現在の行だけを見ると
     * 「一番説明が要る項目だけがキーのまま出る」ことになる（監査 指摘 20）。
     */
    it('保存済み側の宣言からも表示名を引ける', () => {
        const saved = [{ key: 'field_1', label: 'お名前' }];

        const result = customerConfirmation(
            confirmation('x', [
                'field_1: フィールドを削除すると、そのデータは失われます',
            ]),
            { ...labelsByKey(saved), ...labelsByKey([]) },
        );

        expect(result?.impact[0]).toContain('お名前:');
    });

    it('キーから表示名を引ける（入れ子も辿る）', () => {
        expect(
            labelsByKey([
                row({ key: 'a', label: 'お名前' }),
                row({
                    key: 'b',
                    label: 'まとまり',
                    fields: [row({ key: 'c', label: '中の項目' })],
                }),
            ]),
        ).toEqual({ a: 'お名前', b: 'まとまり', c: '中の項目' });
    });
});

/**
 * まだ保存していない行を、確認ダイアログが**表示名で呼ぶ**（5 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * 実測（フォームに「お電話番号」を必須で足して保存）:
 *
 * > このフォームの入力項目を変更します
 * > **`field_3`**: 必須項目の追加は、既存データの埋め戻しが必要です
 *
 * キーは**サーバーが採番する**（`assignFieldKeys` と同じ規則で、送信の直前に付く）。
 * 画面の行はその時点でまだ `key: ''` なので、`labelsByKey` が
 * **「キーが空」として飛ばしていた**。結果、いま足したばかりの —
 * **一番説明が要る項目**だけが機械の名前で出る。
 *
 * 保存済みの行だけ表示名で出るので、**新しい行だけキーのまま**という
 * ちぐはぐな見え方になっていた。
 *
 * ## 直し方
 *
 * 送るときと**同じ規則でキーを付けてから**名前を引く。
 * `assignFieldKeys` は純粋な関数なので、送信側と必ず同じ答えになる。
 */
describe('まだ保存していない行の呼び名', () => {
    it('新しい行にも、送るときと同じキーで表示名が引ける', () => {
        const rows = [
            row({ id: 'x', key: 'field_1', label: 'テスト項目' }),
            row({ label: 'お電話番号', required: true }),
        ];

        const labels = labelsByKey(assignFieldKeys(rows));

        expect(labels.field_2).toBe('お電話番号');
    });

    it('確認ダイアログが field_2 ではなく「お電話番号」と言う', () => {
        const rows = [
            row({ id: 'x', key: 'field_1', label: 'テスト項目' }),
            row({ label: 'お電話番号', required: true }),
        ];

        const result = customerConfirmation(
            confirmation('x', [
                'field_2: 必須フィールドの追加は、既存データの埋め戻しが必要です',
            ]),
            labelsByKey(assignFieldKeys(rows)),
        );

        // 呼び名がキーでなく表示名になる（文は 11 周目 R11-2 から店の人の言葉）
        expect(result?.impact[0]).toBe(
            'お電話番号: 必須の項目を足します。これから送る人は入力が必要になります。すでに届いた送信では、この項目は空のまま残ります。',
        );
    });

    /**
     * 英字の表示名からは読めるキーが作られる（`slugifyFieldKey`）。
     * **通し番号を決め打ちにしない**ための 1 件。
     */
    it('英字の表示名でも、送るときと同じキーで引ける', () => {
        const rows = [row({ label: 'Email', required: true })];

        expect(labelsByKey(assignFieldKeys(rows)).email).toBe('Email');
    });

    /**
     * 🔴 **表示名の無い行を、名前があるように見せない。**
     * キーだけが出るほうが「名前を付けていない項目」だと分かる。
     */
    it('表示名が空の行は、キーのまま出す', () => {
        const rows = [row({ label: '' })];

        const result = customerConfirmation(
            confirmation('x', ['field_1: 何かが起きます']),
            labelsByKey(assignFieldKeys(rows)),
        );

        expect(result?.impact[0]).toBe('field_1: 何かが起きます');
    });
});

describe('影響を受けるデータが無いか（UX 一周 2 周目 F2）', () => {
    it('受信 0 件で、項目がこのフォームにしか付いていなければ無い', () => {
        // 2 周目: 受信 0 件なのに赤い「この内容で保存する」が出た
        expect(noStoredValues(0, [{ kind: 'form' }])).toBe(true);
    });

    it('受信が 1 件でもあれば、あるかもしれない', () => {
        expect(noStoredValues(1, [{ kind: 'form' }])).toBe(false);
    });

    it('投稿タイプにも付いていれば、そちらは数えていないので無いとは言わない', () => {
        expect(
            noStoredValues(0, [{ kind: 'form' }, { kind: 'content_type' }]),
        ).toBe(false);
    });

    it('分からないときは無いと言わない', () => {
        expect(noStoredValues(undefined, [{ kind: 'form' }])).toBe(false);
        expect(noStoredValues(0, [])).toBe(false);
        expect(noStoredValues(0, null)).toBe(false);
    });
});

/**
 * 種類を替えたときの理由を、店の人の言葉にする（UX 一周 9 周目 R9-2: 「型の変更は値の変換が必要です
 * （text → date）」と出て、何かしないといけないように読めた）。
 */
describe('種類を替えたときの言い換え', () => {
    it('型と機械の名前を出さず、種類の呼び名で言う', () => {
        const result = customerConfirmation(
            confirmation('x', ['field_2: 型の変更は値の変換が必要です（text → date）']),
            { field_2: '宿泊希望日' },
        );

        expect(result?.impact[0]).toBe(
            '宿泊希望日: 入力の種類を「1 行テキスト」から「日付」に替えます。すでに届いた送信に入っている値は、書き換えずにそのまま残ります。',
        );
        expect(result?.impact[0]).not.toMatch(/型|→|text|date/);
    });
});

/** UX 一周 11 周目 R11-2: 必須にする確認に「不正」「埋め戻し」と出た */
describe('必須にするときの言い換え', () => {
    it('「不正」「埋め戻し」を出さない', () => {
        const result = customerConfirmation(
            confirmation('x', [
                'field_3: 必須化は、空のままの既存データを不正にします',
                'field_4: 必須項目の追加は、既存データの埋め戻しが必要です',
            ]),
            { field_3: 'ご希望の撮影日', field_4: '撮影場所' },
        );

        expect(result?.impact.join(' ')).not.toMatch(/不正|埋め戻し/);
        expect(result?.impact[0]).toContain('ご希望の撮影日: この項目を必須にします');
    });
});
