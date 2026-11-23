import { describe, expect, it } from 'vitest';
import { historyPosition, trackHistoryPosition } from '../history-position';

function browserHistory(initial: unknown = null) {
    const entries: unknown[] = [initial];
    let index = 0;

    return {
        get state() {
            return entries[index];
        },
        pushState(state: unknown) {
            entries.splice(++index);
            entries.push(state);
        },
        replaceState(state: unknown) {
            entries[index] = state;
        },
        traverse(delta: number) {
            index += delta;

            return entries[index];
        },
    };
}

describe('history entry positions', () => {
    it('preserves the Inertia payload and does not mutate it', () => {
        const page = new ArrayBuffer(8);
        const original = Object.freeze({ page, scrollRegions: [4] });
        const history = browserHistory(original);
        trackHistoryPosition(history);
        expect(history.state).toMatchObject(original);
        expect((history.state as { page: ArrayBuffer }).page).toBe(page);
        expect(historyPosition(original)).toBeNull();
    });

    it('tracks Back, Forward and a multi-entry traversal, while replace keeps the entry', () => {
        const history = browserHistory({ page: 'first' });
        const positions = trackHistoryPosition(history);
        history.pushState({ page: 'second' });
        history.pushState({ page: 'third' });
        history.replaceState({ page: 'third refreshed' });
        expect(positions.current()).toBe(2);
        expect(positions.accept(history.traverse(-2))).toBe(0);
        expect(positions.accept(history.traverse(2))).toBe(2);
        expect(positions.accept(history.traverse(-1))).toBe(1);
        history.pushState({ page: 'a new branch' });
        expect(positions.current()).toBe(2);
        expect(historyPosition(history.state)).toBe(2);
    });

    it('does not advance when the browser rejects a write', () => {
        const history = browserHistory();
        history.pushState = () => {
            throw new DOMException('blocked', 'SecurityError');
        };
        const positions = trackHistoryPosition(history);
        expect(() => history.pushState({ page: 'second' })).toThrow('blocked');
        expect(positions.current()).toBe(0);
    });

    it('leaves unrelated primitive payloads unchanged and treats them as untracked', () => {
        const history = browserHistory();
        const positions = trackHistoryPosition(history);
        history.pushState('external payload');
        expect(history.state).toBe('external payload');
        expect(positions.accept(history.state)).toBeNull();
    });
});
