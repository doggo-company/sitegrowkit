<?php

declare(strict_types=1);

namespace Modules\Contact\Http;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Contact\Application\GetContact;
use Modules\Contact\Application\ListContacts;
use Modules\Contact\Application\UpdateContactStatus;

/**
 * 問い合わせは submissions kind — 管理画面からの作成・編集はせず、閲覧と状態変更のみ。
 *
 * Phase 9.1B 以降、業務ロジックは `Modules\Contact\Application` 側にある。
 */
final class AdminContactController
{
    public function index(Request $request, ListContacts $list): Response
    {
        $status = (string) $request->query('status', '');
        $items = $list->paginate($this->context($request), $status);

        if ($items instanceof Result) {
            $this->abortFor($items);
        }

        return Inertia::render('admin/contact/Index', [
            'items' => $items->withQueryString(),
            'status' => $status,
            'statuses' => ListContacts::statuses(),
        ]);
    }

    public function show(Request $request, GetContact $get, int $id): Response
    {
        $item = $get->find($id, $this->context($request));

        if ($item instanceof Result) {
            $this->abortFor($item);
        }

        return Inertia::render('admin/contact/Show', [
            'item' => $get->present($item),
            'statuses' => ListContacts::statuses(),
        ]);
    }

    public function updateStatus(Request $request, UpdateContactStatus $update, int $id): RedirectResponse
    {
        $result = $update->execute($id, (string) $request->input('status'), $this->context($request));

        if ($result->status === OperationStatus::ValidationError) {
            throw ValidationException::withMessages($result->errors);
        }

        if (! $result->isSuccess()) {
            $this->abortFor($result);
        }

        return back()->with('success', '対応状態を更新しました');
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
            default => 500,
        }, $result->message ?? '');
    }
}
