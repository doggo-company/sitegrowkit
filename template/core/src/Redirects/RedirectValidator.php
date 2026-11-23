<?php

declare(strict_types=1);

namespace AgentCms\Core\Redirects;

/**
 * 保存してよいルールか（Phase 9.10 / STAGE 23B・SPEC §B.3・§D.2・§D.3）。
 *
 * ここが見るのは **形と、輪になっていないこと**である。
 *
 * ## 輪は保存時にも止める
 *
 * 要求時の上限（`RedirectResolver::MAX_HOPS`）だけでも事故にはならないが、
 * それは「踏んだ人が 404 になる」だけで、**間違ったルールは残ったまま**である。
 * 保存時に断れば、そもそも間違いが残らない。両方やる。
 *
 * ## 転送してはいけない先を作らせない
 *
 * `/admin` へ転送するルールも、`/admin` から転送するルールも断る。
 * 前者は「管理画面へ誘導する外部リンク」を CMS が作ることになり、
 * 後者は要求時に無視されるだけの**効かないルール**になる。
 */
final class RedirectValidator
{
    /** 保存時に輪を探す深さ（要求時の上限より深く見る） */
    private const CYCLE_DEPTH = 10;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>> 空なら妥当
     */
    public function validate(array $input, ?int $ignoreId = null): array
    {
        $errors = [];

        $type = is_string($input['match_type'] ?? null)
            ? RedirectMatchType::tryFrom($input['match_type'])
            : null;

        if ($type === null) {
            $errors['match_type'][] = '照合の方法が正しくありません';
        }

        $source = RedirectPath::normalize((string) ($input['source_path'] ?? ''));

        if ($source === null) {
            $errors['source_path'][] = '転送元のパスが正しくありません（`.` や `..` は使えません）';
        } elseif (RedirectResolver::isProtected($source)) {
            $errors['source_path'][] = '管理画面や API のパスは転送できません';
        }

        $target = $this->target($input, $errors);

        if (! isset($input['status_code']) || RedirectStatus::tryFrom((int) $input['status_code']) === null) {
            $errors['status_code'][] = '転送の種類が正しくありません';
        }

        if ($source !== null && $target !== null && $errors === []) {
            $this->cycles($source, $target, $type ?? RedirectMatchType::Exact, $ignoreId, $errors);
        }

        $note = $input['note'] ?? null;

        if ($note !== null && (! is_string($note) || mb_strlen($note) > 255)) {
            $errors['note'][] = 'メモが長すぎます（255 文字まで）';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     */
    private function target(array $input, array &$errors): ?string
    {
        $raw = trim((string) ($input['target_url'] ?? ''));

        if ($raw === '') {
            $errors['target_url'][] = '転送先を入力してください';

            return null;
        }

        if (mb_strlen($raw) > RedirectPath::MAX_LENGTH) {
            $errors['target_url'][] = '転送先が長すぎます';

            return null;
        }

        // 外部は http / https だけ（OG4）。`javascript:` と `data:` は入れさせない
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) === 1) {
            if (preg_match('#^https?://[^\s]+$#i', $raw) !== 1) {
                $errors['target_url'][] = '外部への転送は http / https だけです';

                return null;
            }

            return $raw;
        }

        if (! str_starts_with($raw, '/')) {
            $errors['target_url'][] = '転送先は「/」で始まるパスか、http / https の URL です';

            return null;
        }

        /*
         * **`//example.com` を通さない。**
         *
         * scheme が無いだけの「プロトコル相対 URL」で、`Location:` へそのまま出すと
         * ブラウザは**外部サイトへ飛ぶ**。見た目はパスなので、
         * 「/ で始まるから内部」という判定をすり抜ける典型的な open redirect である。
         * `\` を使う書き方も同じ扱いにする（一部のブラウザが `/` と同一視する）。
         */
        if (preg_match('#^/[\\/]#', $raw) === 1) {
            $errors['target_url'][] = '「//」で始まる転送先は使えません（外部サイトへ飛びます）';

            return null;
        }

        $normalized = RedirectPath::normalize($raw);

        if ($normalized === null) {
            $errors['target_url'][] = '転送先のパスが正しくありません';

            return null;
        }

        if (RedirectResolver::isProtected($normalized)) {
            $errors['target_url'][] = '管理画面や API へは転送できません';

            return null;
        }

        // query は保ったまま返す（転送先の query は意図なので落とさない）
        return $raw;
    }

    /**
     * 自分自身への転送と、既存ルールを辿ってできる輪を断る。
     *
     * @param  array<string, list<string>>  $errors
     */
    private function cycles(
        string $source,
        string $target,
        RedirectMatchType $type,
        ?int $ignoreId,
        array &$errors,
    ): void {
        $normalizedTarget = RedirectPath::normalize($target);

        // 外部 URL は辿れない（辿る必要も無い）
        if ($normalizedTarget === null || preg_match('#^https?://#i', $target) === 1) {
            return;
        }

        if ($normalizedTarget === $source) {
            $errors['target_url'][] = '転送元と転送先が同じです';

            return;
        }

        /** @var array<string, array{target: string, type: RedirectMatchType, source: string}> $graph */
        $graph = [];

        foreach (RedirectRule::query()->enabled()->get() as $rule) {
            if ($ignoreId !== null && (int) $rule->getKey() === $ignoreId) {
                continue;
            }

            $graph[$rule->source_path] = [
                'target' => $rule->target_url,
                'type' => $rule->matchType(),
                'source' => $rule->source_path,
            ];
        }

        // いま保存しようとしているものも含めて辿る
        $graph[$source] = ['target' => $target, 'type' => $type, 'source' => $source];

        $seen = [$source => true];
        $current = $normalizedTarget;

        for ($depth = 0; $depth < self::CYCLE_DEPTH; $depth++) {
            $next = $graph[$current] ?? null;

            if ($next === null) {
                return;
            }

            $resolved = RedirectPath::normalize(
                RedirectPath::buildTarget(
                    $next['target'], $current, $next['type'], $next['source'], false,
                ),
            );

            if ($resolved === null || preg_match('#^https?://#i', $next['target']) === 1) {
                return;
            }

            if (isset($seen[$resolved])) {
                $errors['target_url'][] = 'この転送先は、既存のルールと輪になります';

                return;
            }

            $seen[$resolved] = true;
            $current = $resolved;
        }

        // 深さの上限まで辿り着いても終わらないなら、実質的に輪である
        $errors['target_url'][] = '転送が続きすぎます（既存のルールを見直してください）';
    }
}
