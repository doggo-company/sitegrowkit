/** 別メニューの行 ID は持ち込まない。子も新しい行として写し、保存までは元の木を触らない。 */
export function copyMenuTree<T extends { id: number | null; children: T[] }>(
    items: T[],
): T[] {
    return items.map((item) => ({
        ...item,
        id: null,
        children: copyMenuTree(item.children),
    }));
}
