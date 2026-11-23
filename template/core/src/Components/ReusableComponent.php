<?php

declare(strict_types=1);

namespace AgentCms\Core\Components;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 再利用 component（Phase 9.6 / P0-C7・SPEC §4.5）。
 *
 * ## draft と published を分けて持つ
 *
 * ページが draft / published を持つのと同じ向きである。公開側は node の
 * `instanceOf` から実体を引くだけなので、**下書きと公開の区別が実体側に無いと
 * 区別できない**（1 つの document で持つと、編集した瞬間に公開中のページが変わる）。
 *
 * - `published_document` が null = **一度も公開していない**
 * - **`status` は持たない**。`published_at` / `published_document` から導出できる
 *
 * ## 版と取り消しは持たない
 *
 * 共有物の版は「**誰の操作を戻すか**」が未定義になる。A のページで戻したいものと
 * B のページで戻したいものが、同じ 1 つの履歴の上に並ぶ。持たないと決めている（§4.5）。
 *
 * @property string $id
 * @property string $key
 * @property string $label
 * @property array<string, mixed> $draft_document
 * @property array<string, mixed>|null $published_document
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 */
final class ReusableComponent extends Model
{
    use HasUlids;

    protected $table = 'reusable_components';

    protected $fillable = ['key', 'label', 'draft_document', 'published_document', 'published_at', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'draft_document' => 'array',
            'published_document' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** しまわれていないか（`archived_at` が null なら有効） */
    public function isActive(): bool
    {
        return $this->archived_at === null;
    }

    /** 一度でも公開したか（`status` を持たない代わりに、ここで導出する） */
    public function isPublished(): bool
    {
        return $this->published_at !== null && is_array($this->published_document);
    }

    /**
     * いま公開に出る部分木。**公開していなければ null**。
     *
     * @return array<string, mixed>|null
     */
    public function publicDocument(): ?array
    {
        return $this->isPublished() ? $this->published_document : null;
    }
}
