import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useEditorRecovery } from '@/composables/useEditorRecovery';

function storage() {
    const values = new Map<string, string>();

    return {
        get length() {
            return values.size;
        },
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => {
            values.set(key, value);
        },
        removeItem: (key: string) => {
            values.delete(key);
        },
    };
}

const first = {
    target: 'text-1',
    changes: { props: { text: 'まだ保存されていない文' } },
};
describe('競合した入力の一時保存', () => {
    beforeEach(() =>
        vi.stubGlobal('window', {
            localStorage: storage(),
            sessionStorage: storage(),
        }),
    );

    it('保存前の入力を、同じタブの読み込み直し後に戻せる', () => {
        const editor = useEditorRecovery(1, 2);
        editor.remember(first.target, first.changes);
        const reloaded = useEditorRecovery(1, 2);
        expect(reloaded.drafts.value).toEqual([first]);
        expect(reloaded.text.value).toContain('まだ保存されていない文');
    });

    it('最新の入力だけ残し、古い送信の成功で消さない', () => {
        const editor = useEditorRecovery(1, 2);
        editor.remember(first.target, first.changes);
        editor.remember(first.target, { props: { text: 'さらに打った文' } });
        editor.saved(first.target, first.changes);
        const reloaded = useEditorRecovery(1, 2);
        expect(reloaded.text.value).toContain('さらに打った文');
        reloaded.saved(first.target, { props: { text: 'さらに打った文' } });
        expect(useEditorRecovery(1, 2).drafts.value).toEqual([]);
    });

    it('複数の欄・空欄を保ち、保存済みの欄だけ除く', () => {
        const editor = useEditorRecovery(1, 2);
        editor.remember(first.target, first.changes);
        editor.remember(first.target, { props: { title: null } });
        editor.saved(first.target, first.changes);
        expect(editor.drafts.value).toEqual([
            { target: 'text-1', changes: { props: { title: null } } },
        ]);
    });

    it('別利用者・別ページ・別タブには混ざらない', () => {
        useEditorRecovery(1, 2).remember(first.target, first.changes);
        expect(useEditorRecovery(2, 2).drafts.value).toEqual([]);
        expect(useEditorRecovery(1, 3).drafts.value).toEqual([]);
        window.sessionStorage = storage() as Storage;
        expect(useEditorRecovery(1, 2).drafts.value).toEqual([]);
    });

    it('window.openがsessionStorageを複製しても、片方の保存で他方の文を消さない', () => {
        const original = useEditorRecovery(1, 2);
        original.remember(first.target, first.changes);
        const copied = storage();
        const pointer = 'agentcms.editor.unsaved.1.2.current';
        copied.setItem(pointer, window.sessionStorage.getItem(pointer)!);
        const originalSession = window.sessionStorage;
        window.sessionStorage = copied as Storage;
        const other = useEditorRecovery(1, 2);
        other.remember(first.target, { props: { text: '複製したタブの文' } });
        window.sessionStorage = originalSession;
        original.saved(first.target, first.changes);
        window.sessionStorage = copied as Storage;
        expect(useEditorRecovery(1, 2).text.value).toContain(
            '複製したタブの文',
        );
    });

    it('読み込み直しを繰り返した一時保存は、保存できたあと全て片付く', () => {
        useEditorRecovery(1, 2).remember(first.target, first.changes);
        useEditorRecovery(1, 2);
        useEditorRecovery(1, 2);
        const reloaded = useEditorRecovery(1, 2);
        reloaded.saved(first.target, first.changes);
        expect(window.localStorage.length).toBe(0);
    });

    it('読み込み直す前のタブで追加された文は、別タブの復元後も消さない', () => {
        const original = useEditorRecovery(1, 2);
        original.remember(first.target, first.changes);
        const other = useEditorRecovery(1, 2);
        original.remember(first.target, {
            props: { text: '元のタブの新しい文' },
        });
        other.saved(first.target, first.changes);
        expect(window.localStorage.length).toBe(1);
    });

    it.each(['getItem', 'setItem'] as const)(
        'sessionStorageの%sが使えないなら復元可能と表示せずコピーを残す',
        (method) => {
            window.sessionStorage[method] = () => {
                throw new Error('session blocked');
            };
            const editor = useEditorRecovery(1, 2);
            editor.remember(first.target, first.changes);
            expect(editor.stored.value).toBe(false);
            expect(editor.text.value).toContain('まだ保存されていない文');
            expect(window.localStorage.length).toBe(0);
        },
    );

    it('localStorageが使えなくても文をコピーでき、保存失敗を示す', () => {
        Object.defineProperty(window, 'localStorage', {
            get() {
                throw new Error('blocked');
            },
        });
        const editor = useEditorRecovery(1, 2);
        editor.remember(first.target, first.changes);
        expect(editor.text.value).toContain('まだ保存されていない文');
        expect(editor.stored.value).toBe(false);
    });

    it('壊れた保存値を読み込まない', () => {
        const editor = useEditorRecovery(1, 2);
        editor.remember(first.target, first.changes);
        const old = window.localStorage.getItem;
        window.localStorage.getItem = (key: string) =>
            old(key) === null ? null : '{broken';
        expect(useEditorRecovery(1, 2).drafts.value).toEqual([]);
    });
});
