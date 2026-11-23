<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeRegistrationAllowlist;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\ContentTypes\Registration\PublicSlugRegistry;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Permalink\PermalinkChange;
use AgentCms\Core\Permalink\PermalinkRedirects;
use AgentCms\Core\Permalink\PermalinkStructure;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * 投稿タイプの表示属性・登録属性・公開スラッグを更新する（Phase 9.7 / STAGE 20C）。
 *
 * **schema の構造を 1 バイトも変えない。** field の追加・改名・型変更・削除はここを通らない
 * （項目は Field Group の仕事である — SPEC §G / R5）。変えられるのは
 *
 * - 表示属性（label / 複数形 / 説明 / アイコン / メニューに出すか）
 * - 登録属性（public / publicly_queryable / has_archive / exclude_from_search /
 *   hierarchical / api_enabled / api_base）
 * - 公開スラッグ
 *
 * だけである。**内部 ID は変えられない**（テーブル名・クラス名・route 名の導出元だから）。
 */
final class UpdateContentType implements Operation
{
    /** @var list<string> 受け付けるトップレベルのキー */
    public const ACCEPTED_KEYS = ['id', 'lock_version', 'display_overrides', 'registration', 'public_slug'];

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeSettingsResolver $resolver,
        private readonly ContentTypeSettingsWriter $writer,
        private readonly PublicSlugRegistry $slugs,
        private readonly ContentPermalink $permalinks,
        private readonly PermalinkRedirects $redirects,
    ) {}

    public function operationId(): string
    {
        return 'content.type.update';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::Write;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input, OperationContext $context): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden(
                $this->policy->denialReason($this->operationId(), $context),
            ));
        }

        $unknown = array_values(array_diff(array_keys($input), self::ACCEPTED_KEYS));

        if ($unknown !== []) {
            return $this->reject($context, ['id' => ['受け付けられない項目が含まれています（'.implode('、', $unknown).'）']]);
        }

        $id = is_string($input['id'] ?? null) ? $input['id'] : '';
        $schema = $this->schemas->declared()[$id] ?? null;

        if ($schema === null) {
            return $this->outcome->record($this, $context, Result::notFound('投稿タイプが見つかりません'),
                resourceType: 'content_type', resourceId: $id);
        }

        if (! EditorialCollections::isManageable($schema)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::forbidden('この投稿タイプはコードで管理されているため、管理画面からは変更できません'),
                resourceType: 'content_type',
                resourceId: $id,
            );
        }

        if (! $this->resolver->readiness()->allowsWrites()) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict($this->resolver->readiness()->message() ?? '設定を読めませんでした'),
                resourceType: 'content_type',
                resourceId: $id,
            );
        }

        $errors = [];
        $display = $this->assoc($input['display_overrides'] ?? [], 'display_overrides', $errors);
        $registration = $this->assoc($input['registration'] ?? [], 'registration', $errors);

        foreach (ContentTypeRegistrationAllowlist::validateDisplay($display) as $message) {
            $errors['display_overrides'][] = $message;
        }

        foreach (ContentTypeRegistrationAllowlist::validateRegistration($registration) as $message) {
            $errors['registration'][] = $message;
        }

        $slug = $input['public_slug'] ?? null;
        $slug = is_string($slug) && $slug !== '' ? $slug : null;

        if ($slug !== null && ! ContentTypeRegistrationAllowlist::isValidSlug($slug)) {
            $errors['public_slug'][] = '公開スラッグは英小文字・数字・ハイフンの 1〜60 文字で入力してください';
        }

        // 公開すると言いながら URL が無い状態を保存させない（設定が嘘にならないようにする）
        if (($registration['public'] ?? false) === true && $slug === null) {
            $errors['public_slug'][] = '公開する場合は公開スラッグが必要です';
        }

        if ($slug !== null) {
            // 投稿タイプ・ページ・既存 route の**すべて**と突き合わせる（§E.2）
            $conflict = $this->slugs->conflictFor($slug, $id);

            if ($conflict !== null) {
                $errors['public_slug'][] = "公開スラッグ [{$slug}] は使えません。{$conflict}";
            }
        }

        /*
         * **top-level を占められるのは 1 型だけ**（P1 ③ 段 2）。
         *
         * `slug` 構造は記事を `/{slug}` へ置くので、**その型がサイトの根を占める**。
         * 解決順が 通常 route → 投稿タイプ → ページ なので、
         * **投稿がページを隠す**。2 型が同時に名乗ると、どちらの記事かも決まらない。
         *
         * 断るときは**どの型が占めているか**を言う（「使えません」だけでは直しようが無い）。
         */
        if (($registration['permalink'] ?? null) === PermalinkStructure::Slug->value) {
            $holder = $this->permalinks->topLevelType();

            if ($holder !== null && $holder !== $id) {
                $errors['registration'][] = "URL の構造 [slug] は既に投稿タイプ [{$holder}] が使っています。"
                    ."サイトの入口を持てるのは 1 つだけなので、先に [{$holder}] の構造を変えてください。";
            }
        }

        $current = $this->resolver->for($id);

        /*
         * **URL が動くなら、旧 URL を生かせるかを先に見る**（P1 ③ 段 3）。
         *
         * 入口より後ろの形が変わる変更は、**接頭辞の付け替え 1 本では書けない**。
         * 記事ごとにルールを作ればルールが際限なく増えるので、**変更のほうを断る**。
         * まだ 1 件も公開していない型では、死ぬ URL が無いので通す。
         */
        $change = new PermalinkChange(
            $current->permalink(),
            $current->publicSlug,
            PermalinkStructure::tryFromValue($registration['permalink'] ?? null) ?? PermalinkStructure::DEFAULT,
            $slug,
        );

        /*
         * **守るのは「いま生きている URL」だけ**（R-13 11 件目）。
         *
         * この型がまだ公開されていなければ、公開面はどこも 404 で、
         * **引き継ぐべき URL がそもそも無い**。それでも断ると、
         * **未公開の型を公開できなくなる**（実測: import が作った型を公開できずに止まった）。
         *
         * 記事の公開件数は「URL が生きているか」を意味しない — seed で入れた記事は
         * `status: published` でも、型が非公開なら 1 本も配信されていない。
         *
         * ガードの意味（P1 の保護）は変えない。**適用条件だけ**を直す。
         */
        $wasPublic = $current->flag('public') && $current->publicSlug !== null;

        $refusal = $wasPublic ? $this->redirects->refusalFor($id, $change) : null;

        if ($refusal !== null) {
            $errors['registration'][] = $refusal;
        }

        if ($errors !== []) {
            return $this->reject($context, $errors, $id);
        }

        $expected = is_numeric($input['lock_version'] ?? null) ? (int) $input['lock_version'] : -1;

        $applied = $this->writer->save($id, [
            'display_overrides' => $display === [] ? null : json_encode($display, JSON_UNESCAPED_UNICODE),
            'registration' => $registration === [] ? null : json_encode($registration, JSON_UNESCAPED_UNICODE),
            'public_slug' => $slug,
        ], $expected);

        if ($applied && $change->canRewrite()) {
            // **旧 URL を生かす 1 本**（`created_reason = slug_change`）
            $this->redirects->record($id, $change);
        }

        if (! $applied) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('ほかの操作でこの投稿タイプが更新されました。読み込み直してからやり直してください'),
                resourceType: 'content_type',
                resourceId: $id,
                metadata: ['expectedVersion' => $expected, 'actualVersion' => $this->writer->currentLockVersion($id)],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['contentType' => ['id' => $id, 'lockVersion' => $expected + 1]]),
            resourceType: 'content_type',
            resourceId: $id,
            // **変更のあった項目名まで**（値は入れない — SPEC §J.6）
            metadata: ['changed' => $this->changedKeys($display, $registration, $slug, $current->publicSlug)],
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function reject(OperationContext $context, array $errors, string $id = ''): Result
    {
        return $this->outcome->record($this, $context, Result::validationError($errors),
            resourceType: 'content_type', resourceId: $id);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function assoc(mixed $raw, string $key, array &$errors): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw) || array_is_list($raw)) {
            $errors[$key][] = "{$key} は項目名と値の組で指定してください";

            return [];
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * @param  array<string, mixed>  $display
     * @param  array<string, mixed>  $registration
     * @return list<string>
     */
    private function changedKeys(array $display, array $registration, ?string $slug, ?string $currentSlug): array
    {
        $changed = [
            ...array_map(static fn (string $k): string => "display_overrides.{$k}", array_keys($display)),
            ...array_map(static fn (string $k): string => "registration.{$k}", array_keys($registration)),
        ];

        if ($slug !== $currentSlug) {
            $changed[] = 'public_slug';
        }

        return $changed;
    }
}
