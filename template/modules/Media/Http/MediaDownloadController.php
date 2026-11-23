<?php

declare(strict_types=1);

namespace Modules\Media\Http;

use AgentCms\Core\Application\OperationClassification;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationPolicy;
use AgentCms\Core\Media\MediaKind;
use AgentCms\Core\Media\MediaVisibility;
use App\Models\User;
use App\Support\SessionInvalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Media\Models\Media;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 一般ファイルの配信（Phase 9.5-D / SPEC §3.2）。
 *
 * 一般ファイルは private disk に在り、**直リンクでは到達できない**。
 * 到達手段はこの 2 本だけである。
 *
 * ```
 * GET /media/file/{id}/{name}   -- visibility=public
 * GET /admin/media/file/{id}    -- private を含む。認可必須
 * ```
 *
 * ## 常に「保存させる」
 *
 * public / private を問わず `attachment` で返し、`nosniff` を付ける。
 * さらに `default-src 'none'; sandbox` と `X-Frame-Options: DENY` を足す。
 * 後ろ 2 つは最低条件への**上積み**で、`attachment` を無視するクライアントが
 * あっても埋め込みと能動的コンテンツの実行を止める二重の防御になる。
 *
 * ## 404 の形を揃える
 *
 * 「存在しない ID」「画像を file として要求」「未認可の private」を
 * **同じ 404** にする。403 だと「その ID は存在する」と教えてしまう。
 * ただし **所要時間までは揃えられない**（DB 参照や認可の有無で必ず差が出る）ので、
 * 守るのは「露呈を減らす順序」— 認可を先に判定し、そのあとで存在を確認する。
 */
final class MediaDownloadController
{
    public function __construct(private readonly OperationPolicy $policy) {}

    /** `{name}` は表示用のダミー。実体の特定には使わない（`{id}` が正本） */
    public function show(int $id, string $name = ''): StreamedResponse
    {
        $media = $this->findFile($id);

        if ($media->visibility !== MediaVisibility::Public) {
            // 管理画面から開くべきものを、公開の口では「無い」ことにする
            abort(404);
        }

        return $this->stream($media);
    }

    /**
     * 管理画面の口。private も開ける。
     *
     * **通常の認証 middleware に載せない。** あれは未認証を `/login` へ redirect するが、
     * それは「この URL は在る」と教えることになる。ここでは未認可も 404 にしたいので、
     * 認可を controller の先頭で自分で判定する。
     *
     * 判定は **Media を引くより前**に済ませる。未認可の呼び出しで DB に触れないことが、
     * 「存在する ID かどうか」を所要時間から推し量られにくくする唯一の手立てである。
     */
    public function admin(Request $request, int $id): StreamedResponse
    {
        $this->assertMayReadMedia($request);

        return $this->stream($this->findFile($id));
    }

    /**
     * 管理画面の利用者として読んでよいか。だめなら **404**（403 ではない）。
     *
     * `EnsureUserIsActive` と同じ 2 つ（`is_active` と `session_epoch`）を見る。
     * middleware を再利用しないのは、あれが redirect / 403 を返すからであって、
     * 判定の中身を変えたいからではない。
     */
    private function assertMayReadMedia(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail() || ! $user->is_active) {
            abort(404);
        }

        $epoch = $request->session()->get(SessionInvalidator::SESSION_KEY);

        // 記録の無い session は信用しない（権限変更を素通りさせない）
        if ($epoch === null || (int) $epoch !== (int) $user->session_epoch) {
            abort(404);
        }

        $context = OperationContext::admin((int) $user->getKey(), (string) $user->name);

        // 新しい operation は増やさない。読み取りの可否は既存の media.get と同じ
        if (! $this->policy->allows('media.get', OperationClassification::Read, $context)) {
            abort(404);
        }
    }

    private function findFile(int $id): Media
    {
        $media = Media::query()->find($id);

        // 画像を file の口から出さない（画像は直 URL で配信する）
        if ($media === null || $media->kind !== MediaKind::File) {
            abort(404);
        }

        return $media;
    }

    private function stream(Media $media): StreamedResponse
    {
        $disk = Storage::disk($media->diskName());

        if (! $disk->exists($media->path)) {
            abort(404);
        }

        return $disk->response($media->path, null, $this->headers($media), 'attachment');
    }

    /** @return array<string, string> */
    private function headers(Media $media): array
    {
        return [
            'Content-Type' => (string) $media->mime,
            'Content-Disposition' => $this->disposition($media),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => $media->visibility === MediaVisibility::Public
                ? 'public, max-age=3600'
                : 'private, no-store',
        ];
    }

    /**
     * `filename=` だけだと日本語の名前が壊れるので `filename*=UTF-8''…` を併記する。
     *
     * ASCII 版は**代替名**であって、正確さを求めるものではない。
     * 日本語だけの名前は `Str::ascii()` でほぼ何も残らないので、
     * その場合は `media-<id>.<拡張子>` を使う（空の filename を返さない）。
     */
    private function disposition(Media $media): string
    {
        $name = $this->downloadName($media);
        $extension = pathinfo((string) $media->path, PATHINFO_EXTENSION);

        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '', Str::ascii($name));

        if ($ascii === '' || $ascii === '.'.$extension) {
            $ascii = 'media-'.$media->getKey().($extension !== '' ? '.'.$extension : '');
        }

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $ascii);
    }

    /**
     * 利用者へ見せる名前。
     *
     * 拡張子は **保存時に確定したもの**を使う。`original_name` の拡張子は
     * 申告にすぎないので、そのまま信用しない。
     */
    private function downloadName(Media $media): string
    {
        $extension = pathinfo((string) $media->path, PATHINFO_EXTENSION);
        $original = (string) ($media->original_name ?? $media->name);
        $stem = pathinfo($original, PATHINFO_FILENAME);

        if ($stem === '') {
            $stem = 'media-'.$media->getKey();
        }

        return $extension === '' ? $stem : $stem.'.'.$extension;
    }
}
