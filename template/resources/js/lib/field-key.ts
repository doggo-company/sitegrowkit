import type { FieldRow } from '@/types/field-group-form';

/**
 * 項目のキーを、利用者に書かせずに決める（R-8A ③）。
 *
 * ## なぜ画面から消せるのか
 *
 * キーは **入力欄の `name` と、受信データの JSON のキー**として使われるだけである。
 * 見出しは受信のときに一緒に保存された表示名から作るので（`ExportSubmissions`）、
 * **CSV の見出しにも公開ページにもキーは出てこない**。
 * つまり人が読める必要は無く、**利用者に書かせる理由が無い**。
 *
 * ## 一度決めたキーは、二度と変えない
 *
 * 受信済みのデータがそのキーで保存されている。表示名を直すたびに付け直すと、
 * **過去の受信が別の項目のものに見える**。
 * だから**まだ保存されていない行（`id === null`）で、キーが空のときだけ**決める。
 *
 * ## 日本語の表示名からは作れない
 *
 * キーに使えるのは `/^[a-z][a-z0-9_]*$/` だけなので、「お名前」からは 1 文字も取れない。
 * **取れないことを隠さずに**、`field_1` のような通し番号へ落とす。
 * 英字の表示名（`Email` など）からは読める名前を作る — 作れるときは作る。
 */

/** 表示名から作れるキー。**作れなければ空**（推測で埋めない） */
export function slugifyFieldKey(label: string): string {
    const slug = label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 60);

    // 先頭は英字でなければならない（数字始まりは使えない）
    return /^[a-z][a-z0-9_]*$/.test(slug) ? slug : '';
}

/**
 * まだキーの無い新しい行に、キーを付ける。**入れ子も同じ規則で辿る。**
 *
 * 既に使われているキーは避ける（同じ束の中で重複できない）。
 */
export function assignFieldKeys(rows: FieldRow[]): FieldRow[] {
    const taken = new Set<string>();

    collectKeys(rows, taken);

    return fill(rows, taken);
}

function collectKeys(rows: FieldRow[], into: Set<string>): void {
    for (const row of rows) {
        if (row.key !== '') {
            into.add(row.key);
        }

        collectKeys(row.fields, into);
    }
}

function fill(rows: FieldRow[], taken: Set<string>): FieldRow[] {
    return rows.map((row) => {
        const fields = fill(row.fields, taken);

        // **保存済みの行は触らない。** 受信データがそのキーで残っている
        if (row.key !== '' || row.id !== null) {
            return { ...row, fields };
        }

        const key = nextKey(row.label, taken);

        taken.add(key);

        return { ...row, key, fields };
    });
}

function nextKey(label: string, taken: Set<string>): string {
    const base = slugifyFieldKey(label);

    if (base !== '' && !taken.has(base)) {
        return base;
    }

    const prefix = base === '' ? 'field' : base;

    // 表示名から作れなかったときは 1 から。作れたのに埋まっていたときは 2 から
    // （`email` が埋まっているなら次は `email_2` で、`email_1` ではない）
    for (let n = base === '' ? 1 : 2; n < 1000; n++) {
        const candidate = `${prefix}_${n}`;

        if (!taken.has(candidate)) {
            return candidate;
        }
    }

    // ここまで来るのは同名が 999 個ある場合だけ。**推測で上書きしない**
    return `${prefix}_${taken.size + 1}`;
}
