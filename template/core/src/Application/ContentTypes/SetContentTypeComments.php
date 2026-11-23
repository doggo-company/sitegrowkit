<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\ContentTypes;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Schema\SchemaRegistry;

/**
 * 投稿タイプ側のコメント利用可否（Phase 9.7 / STAGE 20E・SPEC §L）。
 *
 * **これは「型がコメントを使ってよいか」だけを決める。**
 * 受付・承認・返信・スパム判定・通知・記事ごとの設定は **P0-9** の仕事であり、
 * ここでは 1 つも作らない。ON にしても、まだ誰もコメントを書けない。
 *
 * | 決めごと | 内容 |
 * | --- | --- |
 * | 既定 | **すべて OFF**（設定行が無ければ false） |
 * | ① system facility | **恒久的に false**。設定を読みに行かない |
 * | ON → OFF | **既存のコメントを物理削除しない**。再び ON にすれば戻る（OG-I） |
 * | 監査 | 変わった項目名と前後の真偽値まで。**本文・投稿者名・メール・IP を入れない** |
 *
 * OFF は「隠す」ではなく「無効化」である。判定は
 * `ContentTypeLifecyclePolicy::commentsEnabled()` の 1 か所だけが持つ。
 */
final class SetContentTypeComments implements Operation
{
    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly OperationAudit $outcome,
        private readonly ConfirmationGate $gate,
        private readonly SchemaRegistry $schemas,
        private readonly ContentTypeSettingsResolver $resolver,
        private readonly ContentTypeSettingsWriter $writer,
    ) {}

    public function operationId(): string
    {
        return 'content.type.comments';
    }

    /**
     * **Destructive として扱う。**
     *
     * OFF にすると、その型ではコメントの設定も表示も一切出なくなる。
     * 値は消えないが、利用者から見える結果は大きく変わるので、確認なしに起こさない。
     */
    public function classification(): OperationClassification
    {
        return OperationClassification::Destructive;
    }

    public function execute(string $id, bool $enabled, int $expectedLockVersion, OperationContext $context): Result
    {
        $guard = ContentTypeGuard::for($this, $this->policy, $this->outcome, $this->schemas, $this->resolver, $id, $context);

        if ($guard instanceof Result) {
            return $guard;
        }

        $settings = $this->resolver->for($id);

        if ($settings->commentsEnabled === $enabled) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict($enabled ? 'この投稿タイプでは既にコメントを使えます' : 'この投稿タイプでは既にコメントを使いません'),
                resourceType: 'content_type',
                resourceId: $id,
            );
        }

        $confirmation = $this->gate->require(
            $this->operationId(),
            $this->classification(),
            $context,
            // 期待版を指紋に含める（確認してから適用するまでに他者が更新したら止まる）
            ['id' => $id, 'comments_enabled' => $enabled, 'lock_version' => $expectedLockVersion],
            $enabled
                ? "投稿タイプ「{$guard->label}」でコメントを使えるようにします"
                : "投稿タイプ「{$guard->label}」でコメントを使わないようにします",
            $enabled
                ? [
                    /*
                     * 🔴 **ここは「これから起きること」を言う場所である**
                     *（WP 一周 6 周目 2026-09-03）。
                     *
                     * 直す前はこう書いてあった:
                     * 「まだ誰もコメントを書けません。受け付ける仕組みは後の工程で作ります」
                     *
                     * **実物は違った。** ON にした瞬間、記事ページに投稿欄が出て、
                     * 実際に送信でき、管理画面に承認待ちとして並ぶ（実測）。
                     *
                     * 実物より控えめに書くのは、大きく書くのと同じくらい危ない。
                     * 片方は失望で済むが、こちらは**事故**である —
                     * 「まだ書けないなら先に入れておこう」と押した案件のサイトが、
                     * その瞬間から公開のコメント受付になる。
                     *
                     * 強調記法（`**`）は使わない。**画面はこの文字列をそのまま描く**ので、
                     * 読む人には記号が見えていた。
                     */
                    'この投稿タイプの公開ページで、読者からのコメントの受け付けが始まります',
                    '記事のページに投稿欄が出ます（お名前・メールアドレス・本文）',
                    '届いたコメントは、承認するまで公開ページには出ません',
                    'いつでも戻せます',
                ]
                : [
                    'この投稿タイプの公開ページから、コメントの投稿欄が消えます',
                    '書き込まれたコメントは削除されません。再び使えるようにすれば元どおり見えます',
                ],
        );

        if ($confirmation !== null) {
            return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation),
                resourceType: 'content_type', resourceId: $id);
        }

        if (! $this->writer->save($id, ['comments_enabled' => $enabled], $expectedLockVersion)) {
            return $this->outcome->record(
                $this,
                $context,
                Result::conflict('ほかの操作でこの投稿タイプが更新されました。読み込み直してからやり直してください'),
                resourceType: 'content_type',
                resourceId: $id,
                metadata: ['expectedVersion' => $expectedLockVersion, 'actualVersion' => $this->writer->currentLockVersion($id)],
            );
        }

        return $this->outcome->record(
            $this,
            $context,
            Result::success(['contentType' => [
                'id' => $id,
                'commentsEnabled' => $enabled,
                'lockVersion' => $expectedLockVersion + 1,
            ]]),
            resourceType: 'content_type',
            resourceId: $id,
            // **項目名と真偽値まで。** コメントの中身に関わるものは 1 つも入れない
            metadata: ['changed' => ['comments_enabled'], 'commentsEnabled' => $enabled],
        );
    }
}
