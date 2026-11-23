import { ref } from 'vue';
import type { Ref } from 'vue';
import type { MediaItem, MediaKind } from '@/types/media';

/**
 * メディア一覧の **共有取得**（Phase 9.5-C / 9.5-D）。
 *
 * `images` は選択済み 1 件ごとにサムネイルと名前が要る。各行が自分で
 * `/admin/media/select` を叩くと、**N 件で N 回の同じ要求**になる。
 * 一覧はどの入力欄から見ても同じものなので、モジュール単位で 1 度だけ取り、
 * 取得中の Promise も共有する（同時に呼ばれても 1 回に畳む）。
 *
 * ## 絞り込みはサーバーで行う
 *
 * 一覧は **新しい順に 200 件**で打ち切られる。取ってから手元で絞ると、
 * 新しい 200 件がすべて画像だったときに **ファイルが 1 件も出ない**。
 * だから `?kind=` をサーバーへ渡し、上限はその種別の中で効かせる。
 *
 * 取得は **種別ごとに 1 回**。`media` と `images` は同じ画像の要求を共有し、
 * `file` の入力欄はいくつ置かれてもファイルの要求 1 回で済む。
 *
 * 再取得は `refresh()` を明示的に呼んだときだけ。アップロード直後など、
 * 「増えたはずのものが見えない」を避けたい場面で使う。
 */
interface Library {
    media: Ref<MediaItem[]>;
    loaded: Ref<boolean>;
    loading: Ref<boolean>;
    inflight: Promise<void> | null;
}

/** 種別ごとの状態。`all` は絞り込み無し */
const libraries = new Map<MediaKind | 'all', Library>();

function libraryFor(key: MediaKind | 'all'): Library {
    let library = libraries.get(key);

    if (!library) {
        library = {
            media: ref<MediaItem[]>([]),
            loaded: ref(false),
            loading: ref(false),
            inflight: null,
        };
        libraries.set(key, library);
    }

    return library;
}

async function fetchAll(key: MediaKind | 'all'): Promise<void> {
    const library = libraryFor(key);
    library.loading.value = true;

    const query = key === 'all' ? '' : `?kind=${key}`;

    try {
        const res = await fetch(`/admin/media/select${query}`, {
            headers: { Accept: 'application/json' },
        });

        if (!res.ok) {
            return;
        }

        const json = (await res.json()) as { media: MediaItem[] };
        library.media.value = json.media;
        library.loaded.value = true;
    } finally {
        library.loading.value = false;
        library.inflight = null;
    }
}

export function useMediaLibrary(kind?: MediaKind) {
    const key: MediaKind | 'all' = kind ?? 'all';
    const library = libraryFor(key);

    /** 未取得なら取りに行く。取得中なら同じ Promise を返す（要求を増やさない） */
    function load(): Promise<void> {
        if (library.loaded.value) {
            return Promise.resolve();
        }

        library.inflight ??= fetchAll(key);

        return library.inflight;
    }

    function refresh(): Promise<void> {
        library.loaded.value = false;
        library.inflight = null;

        return load();
    }

    /**
     * ID から 1 件。
     *
     * まず自分の種別の中を見て、無ければ他の種別の取得済み一覧も見る。
     * **選ばれているのに「未設定」と見せない**ためで、種別の判定には使わない
     * （種別の食い違いは server validation が拒否する）。
     */
    function find(id: number | null | undefined): MediaItem | null {
        if (id === null || id === undefined) {
            return null;
        }

        const own = library.media.value.find((m) => m.id === id);

        if (own) {
            return own;
        }

        for (const other of libraries.values()) {
            const hit = other.media.value.find((m) => m.id === id);

            if (hit) {
                return hit;
            }
        }

        return null;
    }

    return {
        media: library.media,
        loaded: library.loaded,
        loading: library.loading,
        load,
        refresh,
        find,
    };
}
