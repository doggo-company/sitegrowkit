<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use App\Application\Activity\ActivityFilter;
use App\Application\Activity\ActivityLabels;
use App\Application\Activity\ExportActivity;
use App\Application\Activity\ListActivity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 全体の操作履歴（PHASE WP-BASELINE / STAGE #18A・SPEC §1C.2）。
 *
 * 一覧と CSV は **同じ絞り込み条件**を通す（画面と書き出しがずれない）。
 * 権限が無い利用者は一覧も CSV も開けない。
 */
final class ActivityAdminController
{
    public function index(Request $request, ListActivity $list): Response
    {
        $filter = ActivityFilter::fromInput($request->query());
        $result = $list->execute($filter, $this->context($request), (int) $request->query('page', 1));

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return Inertia::render('admin/activity/Index', [
            'entries' => $result->data['entries'] ?? [],
            'meta' => $result->meta,
            'filter' => $result->data['filter'] ?? [],
            'options' => [
                'operations' => ActivityLabels::operationOptions(),
                'results' => ActivityLabels::resultOptions(),
                'interfaces' => ActivityLabels::interfaceOptions(),
            ],
        ]);
    }

    /**
     * CSV 書き出し。
     *
     * 件数の判定までを Application Service が行い、ここは **流すだけ**。
     * BOM を付けて Excel が UTF-8 として開けるようにする。
     */
    public function export(Request $request, ExportActivity $export): StreamedResponse
    {
        $context = $this->context($request);
        $filter = ActivityFilter::fromInput($request->query());
        $result = $export->execute($filter, $context);

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        $includeNetwork = $export->maySeeNetworkContext($context);
        $filename = 'activity-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($export, $filter, $includeNetwork): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Excel が UTF-8 として開けるよう BOM を先頭へ
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $export->header($includeNetwork));

            foreach ($export->rows($filter, $includeNetwork) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function context(Request $request): OperationContext
    {
        $user = $request->user();

        return OperationContext::admin($user?->getKey(), $user === null ? '' : (string) $user->name);
    }

    private function abortFor(Result $result): never
    {
        abort(match ($result->status) {
            OperationStatus::NotFound => 404,
            OperationStatus::Forbidden => 403,
            OperationStatus::Conflict => 409,
            default => 500,
        }, $result->message ?? '');
    }
}
