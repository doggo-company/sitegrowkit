<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use App\Models\User;
use App\Support\Role;
use Illuminate\Contracts\Auth\Authenticatable;
use Inertia\Support\SessionKey;

/**
 * 書き込み画面のテスト基盤（Phase 9.5-B）。
 *
 * HTTP から叩くので、**同じ利用者で通す**必要がある。確認トークンは
 * actor へ束縛されるため、リクエストごとに利用者を作り直すと
 * 2 回目の送信が別人扱いになって永久に承認できない。
 */
abstract class FieldGroupScreenTestCase extends FieldGroupTestCase
{
    private ?User $actor = null;

    /** 全権限の利用者（制作者）。作りの画面はオーナーでは 403（UX 一周 2026-09-28） */
    protected function owner(): User
    {
        return $this->actor ??= User::factory()->create(['role' => Role::Developer->value]);
    }

    /**
     * 1 リクエストの境界を作る。
     *
     * 読み取り側（repository / registry / overlay）は scoped なので、本番では
     * リクエストごとに作り直される。テストの container はリクエストを跨いで
     * 生き続けるため、ここで明示的に捨てないと **2 回目のリクエストが
     * 1 回目のメモを見る**という、本番では起こらない状況を試すことになる。
     *
     * @param  string|null  $guard
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        app()->forgetScopedInstances();

        return parent::actingAs($user, $guard);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return [
            'key' => 'product_spec',
            'label' => '商品スペック',
            'locations' => [['kind' => 'content_type', 'value' => 'news']],
            'fields' => [['key' => 'spec_weight', 'type' => 'text', 'label' => '重量']],
            ...$overrides,
        ];
    }

    /**
     * Inertia が flash で返した確認内容。
     *
     * @return array<string, mixed>|null
     */
    protected function flashedConfirmation(): ?array
    {
        $flash = session()->get(SessionKey::FLASH_DATA, []);

        return is_array($flash) && is_array($flash['confirmation'] ?? null)
            ? $flash['confirmation']
            : null;
    }
}
