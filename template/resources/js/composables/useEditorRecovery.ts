import { computed, ref } from 'vue';

interface UnsavedField {
    target: string;
    changes: Record<string, unknown>;
}

/** Keep individual unsaved fields, never a stale copy of the whole document. */
export function useEditorRecovery(userId: number, pageId: number) {
    const drafts = ref<UnsavedField[]>([]);
    const stored = ref(true);
    const prefix = `agentcms.editor.unsaved.${userId}.${pageId}.`;
    const pointer = `${prefix}current`;
    const key = `${prefix}${crypto.randomUUID()}`;
    let pointerReady = false;
    let inherited: { key: string; signature: string }[] = [];

    function read(raw: string | null): {
        drafts: UnsavedField[];
        inherited: typeof inherited;
    } {
        const parsed: unknown = JSON.parse(raw ?? '{}');

        if (parsed === null || typeof parsed !== 'object') {
            throw new Error('Invalid backup');
        }

        const data = parsed as Record<string, unknown>;

        if (
            !Array.isArray(data.drafts) ||
            !data.drafts.every(
                (entry) =>
                    entry !== null &&
                    typeof entry === 'object' &&
                    typeof entry.target === 'string' &&
                    entry.changes !== null &&
                    typeof entry.changes === 'object' &&
                    !Array.isArray(entry.changes),
            )
        ) {
            throw new Error('Invalid backup');
        }

        return {
            drafts: data.drafts,
            inherited: Array.isArray(data.inherited)
                ? data.inherited.filter(
                      (entry) =>
                          entry !== null &&
                          typeof entry === 'object' &&
                          typeof entry.key === 'string' &&
                          entry.key.startsWith(prefix) &&
                          typeof entry.signature === 'string',
                  )
                : [],
        };
    }

    try {
        const previous = window.sessionStorage.getItem(pointer);

        if (previous?.startsWith(prefix)) {
            const raw = window.localStorage.getItem(previous);

            if (raw !== null) {
                const data = read(raw);
                drafts.value = data.drafts;
                inherited = [
                    ...data.inherited,
                    { key: previous, signature: JSON.stringify(data.drafts) },
                ];
            }
        }

        // window.open/duplicate-tab copies sessionStorage. Every mount owns a new key,
        // so saving in the original tab cannot erase the copy's newer input.
        if (drafts.value.length > 0) {
            window.localStorage.setItem(
                key,
                JSON.stringify({ drafts: drafts.value, inherited }),
            );
        }

        window.sessionStorage.setItem(pointer, key);
        pointerReady = true;
    } catch {
        stored.value = false;

        // A backup without its session pointer cannot be found on reload.
        try {
            window.localStorage.removeItem(key);
        } catch {
            // Keep the in-memory text available for manual copy.
        }
    }

    function persist(): void {
        if (!pointerReady) {
            stored.value = false;

            return;
        }

        try {
            if (drafts.value.length === 0) {
                window.localStorage.removeItem(key);

                // Keep earlier keys until the recovered fields were saved. Only remove
                // unchanged snapshots: another live tab may still be writing to them.
                for (const old of inherited) {
                    const raw = window.localStorage.getItem(old.key);

                    if (
                        raw !== null &&
                        JSON.stringify(read(raw).drafts) === old.signature
                    ) {
                        window.localStorage.removeItem(old.key);
                    }
                }

                inherited = [];
            } else {
                window.localStorage.setItem(
                    key,
                    JSON.stringify({ drafts: drafts.value, inherited }),
                );
            }

            stored.value = true;
        } catch {
            stored.value = false;
        }
    }

    function paths(
        value: Record<string, unknown>,
        prefix: string[] = [],
    ): string[] {
        return Object.entries(value).flatMap(([name, item]) =>
            item !== null && typeof item === 'object' && !Array.isArray(item)
                ? paths(item as Record<string, unknown>, [...prefix, name])
                : [JSON.stringify([...prefix, name])],
        );
    }

    function contains(actual: unknown, wanted: unknown): boolean {
        if (
            wanted !== null &&
            typeof wanted === 'object' &&
            !Array.isArray(wanted)
        ) {
            return (
                actual !== null &&
                typeof actual === 'object' &&
                Object.entries(wanted).every(([name, value]) =>
                    contains((actual as Record<string, unknown>)[name], value),
                )
            );
        }

        return actual === wanted;
    }

    function remember(target: string, changes: Record<string, unknown>): void {
        const names = JSON.stringify(paths(changes));
        drafts.value = drafts.value.filter(
            (entry) =>
                entry.target !== target ||
                JSON.stringify(paths(entry.changes)) !== names,
        );
        drafts.value.push({ target, changes: structuredClone(changes) });
        persist();
    }

    function saved(target: string, changes: Record<string, unknown>): void {
        drafts.value = drafts.value.filter(
            (entry) =>
                entry.target !== target || !contains(changes, entry.changes),
        );
        persist();
    }

    function values(value: unknown): string[] {
        if (typeof value === 'string') {
            return [value];
        }

        if (value !== null && typeof value === 'object') {
            return Object.values(value).flatMap(values);
        }

        return [];
    }

    return {
        drafts,
        stored,
        remember,
        saved,
        text: computed(() =>
            drafts.value.flatMap((entry) => values(entry.changes)).join('\n\n'),
        ),
    };
}
