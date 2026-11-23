/**
 * 記事の「いまの状態」を、公開面と同じ規則で言う（UX 一周 7 周目 R7-3）。
 *
 * 公開日時を来週にした記事が、一覧でもダッシュボードでも、もう出ている記事と同じ黒い「公開」で
 * 出ていた（公開面は `status` と `published_at` の両方を見るので、実際には出ていない）。
 * 「予約」と出し、いつ出るかを添える。
 */

export type ContentState = 'published' | 'scheduled' | 'draft';

export function contentState(
    status: unknown,
    publishedAt: unknown,
    now: number = Date.now(),
): ContentState {
    if (status !== 'published') {
        return 'draft';
    }

    if (typeof publishedAt !== 'string' || publishedAt === '') {
        return 'published';
    }

    const at = Date.parse(publishedAt);

    return Number.isFinite(at) && at > now ? 'scheduled' : 'published';
}

export function contentStateLabel(state: ContentState): string {
    return { published: '公開', scheduled: '予約', draft: '下書き' }[state];
}

/** バッジの見た目。予約は「公開」と同じ塗りにしない（まだ出ていない） */
export function contentStateVariant(
    state: ContentState,
): 'default' | 'outline' | 'secondary' {
    return { published: 'default', scheduled: 'outline', draft: 'secondary' }[
        state
    ] as 'default' | 'outline' | 'secondary';
}
