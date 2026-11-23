<?php

declare(strict_types=1);

namespace AgentCms\Core\Menus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * メニュー 1 本（Phase 9.9 / STAGE 22B・SPEC §B.1）。
 *
 * `key` はテーマが `<x-navigation menu="header" />` と書く識別子で、
 * **`managed_by = 'code'` の本では変えられない**（OG1）。名前と中身は編集できる。
 *
 * **Mass Assignment から意図的に外してある**: `key` / `managed_by` / `lock_version`。
 * key の変更と所有者の変更は「メニューの編集」ではないので、同じ経路で通さない。
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $managed_by
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Menu extends Model
{
    /** テーマが宣言した本。削除と key の変更を拒む */
    public const MANAGED_BY_CODE = 'code';

    /** 編集者が作った本 */
    public const MANAGED_BY_ADMIN = 'admin';

    protected $table = 'menus';

    protected $fillable = ['name', 'description'];

    protected $casts = ['lock_version' => 'integer'];

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id');
    }

    /** テーマが持っている本か（消せない・key を変えられない） */
    public function isCodeManaged(): bool
    {
        return $this->managed_by === self::MANAGED_BY_CODE;
    }
}
