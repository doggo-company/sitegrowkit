<?php

declare(strict_types=1);

namespace AgentCms\Core\Revisions;

/**
 * 「その版へ戻すと何が起きるか」を **1 つの確定した計画**にする
 * （Phase 9.8 / STAGE 21D CORRECTION・SPEC §F.2.2・§F.5）。
 *
 * ## preview と apply が同じものを見る
 *
 * 見せた内容と実際に起きることがずれてはいけない。だから計画は 1 か所で作り、
 * **確認の文面もその計画から**、**書き込む対象もその計画から**取る。
 *
 * 計画には指紋を持たせる。確認トークンをこれに束縛しておけば、押すまでのあいだに
 * 状況が変わった（参照先が消えた、分類が消えた、schema が変わった）ときに
 * **古い同意で書き込まれる**ことがない。
 */
final class RevisionRestorePlan
{
    /**
     * @param  list<string>  $restorable  戻す field のキー
     * @param  list<array{key: string, reason: string}>  $lost  戻さない field と理由
     * @param  list<string>  $added  版より後に増えた field（現在値を残す）
     * @param  list<array{taxonomy: string, term: string}>  $droppedTerms  消えていた分類項目
     * @param  list<string>  $missing  参照先が見つからないもの（あれば止める）
     */
    public function __construct(
        public readonly array $restorable,
        public readonly array $lost,
        public readonly array $added,
        public readonly array $droppedTerms,
        public readonly array $missing,
    ) {}

    /**
     * 計画の指紋。**確認トークンをこれに束縛する。**
     *
     * 押すまでのあいだに何かが変われば指紋も変わり、古い同意は通らなくなる。
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            'restorable' => $this->restorable,
            'lost' => $this->lost,
            'added' => $this->added,
            'dropped_terms' => $this->droppedTerms,
            'missing' => $this->missing,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** 参照先が消えているなら、そもそも戻せない（§F.5） */
    public function blocked(): bool
    {
        return $this->missing !== [];
    }

    /**
     * 確認の文面。**失われるものを必ず先に見せる。**
     *
     * @return list<string>
     */
    public function impact(int $revision): array
    {
        $impact = [
            "版 {$revision} の内容で、いまの内容を置き換えます",
            '復元した内容は新しい版として記録され、過去の版は変わりません',
        ];

        foreach ($this->lost as $lost) {
            $impact[] = "「{$lost['key']}」は復元されません（{$lost['reason']}）";
        }

        foreach ($this->droppedTerms as $dropped) {
            // **黙って落とさない。** どの分類の、どの項目かまで言う
            $impact[] = "分類「{$dropped['taxonomy']}」の項目（{$dropped['term']}）は"
                .'削除済みのため復元されません';
        }

        if ($this->added !== []) {
            $impact[] = 'この版より後に増えた項目（'.implode('、', $this->added).'）は今の値のまま残ります';
        }

        return $impact;
    }
}
