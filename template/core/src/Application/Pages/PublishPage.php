<?php

declare(strict_types=1);

namespace AgentCms\Core\Application\Pages;

use AgentCms\Core\Application\ConfirmationGate;
use AgentCms\Core\Application\Operation;
use AgentCms\Core\Application\OperationAudit;
use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Application\Result;
use AgentCms\Core\Document\DocumentValidator;
use AgentCms\Core\Pages\PageStatus;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * ページの公開（Phase 9.1D）。
 *
 * **EXTERNAL_EFFECT** — 公開サイトに出るため既定で確認を要する。
 * ただし管理画面の「公開する」ボタンは **その操作自体が人間の確認**なので、
 * Admin 経路では追加のダイアログを重ねない。MCP / API からは確認必須。
 *
 * ## 予約公開（release R02）
 *
 * `publishedAt` を渡すと、その日時から公開になる（`Page::isScheduled()`）。
 * 渡さなければ今まで通り **その場で公開**。日時の解釈は **サイトの timezone**
 * （`config('app.timezone')`）で、offset 付きならそれに従う。保存は Laravel の既定どおり
 * app timezone の壁時計で、比較も同じ基準（`now()`）なので DB と app の基準がずれない。
 * 過去の日時は拒まない — 入力してから押すまでに時計が進んだ人を止めない。
 */
final class PublishPage implements Operation
{
    /** 入力として受ける日時の形（`datetime-local` の `Y-m-d\TH:i` と ISO 8601） */
    public const INPUT_KEY = 'publishedAt';

    public function __construct(
        private readonly OperationPolicy $policy,
        private readonly GetPage $get,
        private readonly PageRepository $pages,
        private readonly DocumentValidator $validator,
        private readonly ConfirmationGate $confirmations,
        private readonly OperationAudit $outcome,
    ) {}

    public function operationId(): string
    {
        return 'page.publish';
    }

    public function classification(): OperationClassification
    {
        return OperationClassification::ExternalEffect;
    }

    /**
     * @param  mixed  $publishedAt  公開日時。null / 空文字は「いま」。
     *                              文字列以外・読めない文字列は validation error
     */
    public function execute(int $pageId, OperationContext $context, mixed $publishedAt = null): Result
    {
        if (! $this->policy->allows($this->operationId(), $this->classification(), $context)) {
            return $this->outcome->record($this, $context, Result::forbidden($this->policy->denialReason($this->operationId(), $context)));
        }

        $page = $this->get->find($pageId, $context);

        if ($page instanceof Result) {
            return $this->outcome->record($this, $context, $page, resourceType: 'page', resourceId: (string) $pageId);
        }

        // **日時は権限と存在の後、確認の前**に読む。読めない入力を confirmation まで運ばない
        $at = self::parsePublishedAt($publishedAt);

        if ($at instanceof Result) {
            return $this->outcome->record($this, $context, $at, resourceType: 'page', resourceId: (string) $page->id);
        }

        $draft = $this->pages->draft($page);

        // **編集中の版が居ないなら、公開しない。** 何を公開するのか言えない（§4.11）
        if ($draft instanceof Result) {
            return $this->outcome->record($this, $context, $draft, resourceType: 'page', resourceId: (string) $pageId);
        }

        // **publish 専用の検証**（Phase 9.3B / #18・PB-1〜PB-6）。
        // 構造検証に加えて「空のページは公開できない」を見る。draft 保存では空を許す。
        // **確認より先に**行うので、空 document は confirmation_required ではなく
        // validation_error として返る（Admin / API / MCP で同じ）
        $errors = $this->validator->validateForPublish($draft->document);

        if ($errors !== []) {
            return $this->outcome->record($this, $context, Result::validationError($errors, 'このページには公開できない内容があります'), resourceType: 'page', resourceId: (string) $page->id);
        }

        $scheduled = false;
        $summary = "「{$page->label()}」を公開します";
        $impact = ['公開サイトに表示されます', 'sitemap にも掲載されます'];

        if ($at !== null && $at->isFuture()) {
            $scheduled = true;
            $summary = "「{$page->label()}」を {$at->format('Y-m-d H:i')}（{$at->getTimezone()->getName()}）に公開予約します";
            $impact = ['指定した日時になると公開サイトに表示されます', 'それまでは公開サイト・メニュー・sitemap に出ません'];
        }

        // Admin の「公開する」操作自体を人間の確認とみなす。それ以外の経路は確認必須
        if ($context->interface !== OperationInterface::Admin) {
            $confirmation = $this->confirmations->require(
                $this->operationId(),
                $this->classification(),
                $context,
                // 確認 token は **日時込み**で結ぶ。承認した予定と違う日時で通さない
                ['pageId' => $page->id, 'version' => $draft->revision, 'publishedAt' => $at?->toIso8601String()],
                summary: $summary,
                impact: $impact,
            );

            if ($confirmation !== null) {
                return $this->outcome->record($this, $context, Result::confirmationRequired($confirmation), resourceType: 'page', resourceId: (string) $page->id);
            }
        }

        $page->forceFill([
            'published_version_id' => $draft->id,
            'status' => PageStatus::Published->value,
            'published_at' => $at ?? now(),
            'trashed_at' => null,
        ])->save();

        return $this->outcome->record(
            $this,
            $context,
            Result::success(
                ['page' => GetPage::present($page->refresh())],
                ['version' => $draft->revision, 'scheduled' => $scheduled],
            ),
            resourceType: 'page',
            resourceId: (string) $page->id,
            metadata: ['slug' => $page->slug, 'version' => $draft->revision, 'scheduled' => $scheduled],
        );
    }

    /**
     * 公開日時の入力を読む。**null は「いま」**、読めなければ validation error。
     *
     * offset の無い文字列（`datetime-local` の値）はサイトの timezone で読む。
     * offset 付き（ISO 8601）はそのまま従う。どちらも保存時は app timezone へ寄る。
     * 年だけ・空白だけのような曖昧な文字列は Carbon が通しても**意図と違う日時**に
     * なるので、日付と時刻の両方を持つ形だけを受ける。
     */
    private static function parsePublishedAt(mixed $raw): Carbon|Result|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $error = Result::validationError(
            [self::INPUT_KEY => ['公開日時は「2026-09-10T09:00」のような日付と時刻で指定してください']],
            '公開日時の入力内容を確認してください',
        );

        // Carbon の補正で「2月30日」や「24時」が別の日時になるのを防ぐ。
        if (! is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?$/D', $raw, $parts) !== 1) {
            return $error;
        }

        if ((int) $parts[1] < 1000 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59) {
            return $error;
        }

        $offset = $parts[7] ?? '';
        if ($offset !== '' && $offset !== 'Z'
            && ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59
                || ((int) substr($offset, 1, 2) === 14 && (int) substr($offset, 4, 2) !== 0))) {
            return $error;
        }

        try {
            $at = Carbon::parse($raw, (string) config('app.timezone'));
        } catch (InvalidFormatException) {
            return $error;
        }

        // 夏時間への切り替えで存在しない現地時刻も、黙って後ろへずらさない。
        $seconds = ($parts[6] ?? '') === '' ? '00' : $parts[6];
        if ($at->format('Y-m-d H:i:s') !== sprintf('%s-%s-%s %s:%s:%s', $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $seconds)) {
            return $error;
        }

        // 予約が来る前に DB の秒精度で丸まって「ちょうどの秒」がずれないよう秒で切る
        return $at->setTimezone((string) config('app.timezone'))->startOfSecond();
    }
}
