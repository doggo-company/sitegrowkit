/** A position alongside Inertia's history payload, without changing its page data. */
const KEY = '__sgkHistoryPosition';

interface HistoryLike {
    readonly state: unknown;
    pushState(data: unknown, unused: string, url?: string | URL | null): void;
    replaceState(
        data: unknown,
        unused: string,
        url?: string | URL | null,
    ): void;
}

export function historyPosition(state: unknown): number | null {
    if (state === null || typeof state !== 'object') {
        return null;
    }

    const value = (state as Record<string, unknown>)[KEY];

    return typeof value === 'number' && Number.isSafeInteger(value)
        ? value
        : null;
}

function positioned(state: unknown, position: number): unknown {
    // The app uses object payloads. Leave unrelated primitive/array payloads intact.
    return state === null ||
        (typeof state === 'object' && !Array.isArray(state))
        ? { ...state, [KEY]: position }
        : state;
}

export function trackHistoryPosition(history: HistoryLike) {
    let current = historyPosition(history.state) ?? 0;
    const push = history.pushState.bind(history);
    const replace = history.replaceState.bind(history);

    replace(positioned(history.state, current), '');

    history.pushState = (state, unused, url) => {
        const next = current + 1;
        push(positioned(state, next), unused, url);
        current = next;
    };
    history.replaceState = (state, unused, url) => {
        const position = historyPosition(history.state) ?? current;
        replace(positioned(state, position), unused, url);
        current = position;
    };

    return {
        current: () => current,
        accept(state: unknown) {
            const next = historyPosition(state);

            if (next !== null) {
                current = next;
            }

            return next;
        },
    };
}
