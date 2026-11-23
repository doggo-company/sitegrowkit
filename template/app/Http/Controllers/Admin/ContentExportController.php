<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Support\Capability;
use App\Support\ContentExport;
use App\Support\ContentExportRuns;
use App\Support\RoleOperationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** 投稿内容の持ち出しはOwnerだけ。全経路でサーバー側権限を検査する。 */
final class ContentExportController
{
    public function index(Request $request, ContentExportRuns $runs): Response
    {
        $this->authorize($request);

        return Inertia::render('admin/exports/Index', ['run' => $runs->current()]);
    }

    public function store(Request $request, ContentExportRuns $runs): JsonResponse
    {
        $this->authorize($request);
        try {
            $state = $runs->start();
        } catch (Throwable) {
            return response()->json(['message' => '開始できませんでした。少し待って再実行し、続く場合は保存先の権限と空き容量を確認してください'], 409);
        }

        return response()->json(['data' => $state]);
    }

    public function status(Request $request, ContentExportRuns $runs, string $id): JsonResponse
    {
        $this->authorize($request);
        $state = $runs->find($id);
        abort_if($state === null, 404);

        return response()->json(['run' => $state]);
    }

    public function download(Request $request, ContentExportRuns $runs, string $id): BinaryFileResponse
    {
        $this->authorize($request);
        abort_unless(($runs->find($id)['status'] ?? null) === 'done' && is_file(ContentExport::path($id)), 404);
        Log::info('content.export.download', ['id' => $id, 'user_id' => $request->user()?->getKey()]);

        return response()->download(ContentExport::path($id), 'sitegrowkit-content-'.$id.'.xml', ['Cache-Control' => 'private, no-store']);
    }

    private function authorize(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array(Capability::BackupRestore->value, app(RoleOperationPolicy::class)->capabilitiesFor($user), true), 403);
    }
}
