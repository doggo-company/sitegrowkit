/**
 * 管理画面の日時を、**サーバーの時間帯**で読む（WP 一周 5 周目 2026-09-03）。
 *
 * ## なぜ 1 か所に集めるか
 *
 * `published_at` は Carbon なので Inertia が ISO8601（UTC・末尾 `Z`）で流す。
 * 画面側は `String(value).replace('T', ' ').slice(0, 16)` で切っていただけなので、
 * **UTC の文字がそのまま JST の欄に入っていた**。
 *
 * 実測（`Asia/Tokyo`・入れたての生成 project）:
 * DB は `2026-09-03 19:29:46` で正しいのに、一覧は `10:29`、編集の欄も `10:29`。
 * **何も触らずに「保存」を押すと DB が `10:29` になり、もう一度押すと `01:29`。**
 * 押すたびに 9 時間ずつ過去へ戻り、警告は 1 つも出ない。
 *
 * ## 直す側を「画面」にした理由
 *
 * 線の上を流れる値（ISO8601・UTC）は**機械にとって正しい**。API / MCP から読む
 * AI エージェントもこれを見る。**契約を変えずに、人が読む面だけを直す。**
 *
 * ## 時間帯を持たない文字は動かさない
 *
 * すべての画面が ISO で受け取っているわけではない。実測で、
 * **フォームの受信日時（`19:44`）とページの最終更新（`19:46`）は正しく出ていた** —
 * サーバーが `Y-m-d H:i:s`（現地時刻）で渡しているためである。
 * 「同じ書き方だから同じように壊れている」は**外れる**。
 * ここで一律に時間帯を掛けると、**いま正しいものを壊す**。
 *
 * 見分けは末尾の時間帯だけで行う（`Z` か `±HH:MM`）。
 */

/** ISO8601 の末尾に時間帯が付いているか（`Z` または `+09:00` / `-05:00`）。 */
const HAS_TIMEZONE = /(?:Z|[+-]\d{2}:?\d{2})$/;

type Parts = {
    year: string;
    month: string;
    day: string;
    hour: string;
    minute: string;
};

function trimmed(value: unknown): string {
    return typeof value === 'string' ? value.trim() : '';
}

/**
 * その瞬間を、指定した時間帯の「年月日時分」に割る。
 *
 * `Intl` に時間帯を明示で渡すので、**動かしている環境の時計に依存しない**
 * （CI は UTC で回る）。
 */
function partsInZone(instant: Date, timeZone: string): Parts | null {
    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    const found: Record<string, string> = {};

    for (const part of formatter.formatToParts(instant)) {
        found[part.type] = part.value;
    }

    // `hour12: false` でも 24 時を返す実装があるため、0 時へ寄せる
    if (found.hour === '24') {
        found.hour = '00';
    }

    const keys = ['year', 'month', 'day', 'hour', 'minute'] as const;

    if (keys.some((key) => found[key] === undefined)) {
        return null;
    }

    return {
        year: found.year as string,
        month: found.month as string,
        day: found.day as string,
        hour: found.hour as string,
        minute: found.minute as string,
    };
}

/**
 * 時間帯付きの文字だけを、その時間帯の年月日時分へ割る。
 *
 * 時間帯を持たない文字（`2026-09-03 19:44:12`）は `null` を返す
 * — 呼ぶ側が「切るだけ」に倒す。
 */
function zonedParts(value: string, timeZone: string): Parts | null {
    if (!HAS_TIMEZONE.test(value)) {
        return null;
    }

    const instant = new Date(value);

    if (Number.isNaN(instant.getTime())) {
        return null;
    }

    return partsInZone(instant, timeZone);
}

/**
 * 一覧などに出す日時（`2026-09-03 19:29`）。
 *
 * 空は空文字にする。「—」を出すかどうかは呼ぶ側が決める。
 * 読めない文字は**そのまま返す**（勝手に消すと、何が入っているか分からなくなる）。
 */
export function formatDateTime(value: unknown, timeZone: string): string {
    const raw = trimmed(value);

    if (raw === '') {
        return '';
    }

    const parts = zonedParts(raw, timeZone);

    if (parts !== null) {
        return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`;
    }

    // 時間帯を持たない文字は動かさない（`2026-09-03 19:44:12` → `2026-09-03 19:44`）
    return raw.replace('T', ' ').slice(0, 16);
}

/**
 * `<input type="datetime-local">` に入れる値（`2026-09-03T19:29`）。
 *
 * 🔴 **ここが往復の要**。出した文字はそのまま送り返され、サーバーは
 * 時間帯を持たない文字を「サーバーの時間帯の時刻」として読む。
 * だから**サーバーの時間帯で割った値**でなければ、保存のたびに時刻が動く。
 */
export function toDateTimeLocal(value: unknown, timeZone: string): string {
    const formatted = formatDateTime(value, timeZone);

    if (formatted === '') {
        return '';
    }

    return formatted.replace(' ', 'T');
}

/**
 * `<input type="date">` に入れる値（`2026-09-03`）。
 *
 * 時間帯を持たない日付（`2026-09-03`）を時間帯で読み直すと**前日に飛ぶ**。
 * 日付の欄は日付そのものなので、そのときは触らない。
 */
export function toDateOnly(value: unknown, timeZone: string): string {
    const raw = trimmed(value);

    if (raw === '') {
        return '';
    }

    const parts = zonedParts(raw, timeZone);

    if (parts !== null) {
        return `${parts.year}-${parts.month}-${parts.day}`;
    }

    return raw.slice(0, 10);
}
