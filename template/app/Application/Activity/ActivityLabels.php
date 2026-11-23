<?php

declare(strict_types=1);

namespace App\Application\Activity;

use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;

/**
 * 履歴の日本語表示（PHASE WP-BASELINE / STAGE #18A）。
 *
 * 履歴に出るのは operation id という機械の言葉なので、
 * **画面に出す名前をここで 1 箇所に集める**（Vue 側へ辞書を散らさない）。
 * 未知の operation は id をそのまま出す（黙って消さない）。
 */
final class ActivityLabels
{
    /** 認証まわりのイベント（Application Service ではないので権限表には無い） */
    private const AUTH_OPERATIONS = [
        'auth.login' => 'ログイン',
        'auth.login.failed' => 'ログイン失敗',
        'auth.logout' => 'ログアウト',
        'auth.password.reset' => 'パスワード再設定',
        'auth.password.updated' => 'パスワード変更',
        'auth.two_factor.enabled' => '二要素認証を有効化',
        'auth.two_factor.confirmed' => '二要素認証を確認',
        'auth.two_factor.disabled' => '二要素認証を無効化',
        'auth.recovery_codes.generated' => 'リカバリーコードを再生成',
        'auth.passkey.registered' => 'パスキーを登録',
        'auth.passkey.deleted' => 'パスキーを削除',
    ];

    private const OPERATIONS = [
        'content.create' => 'コンテンツを作成',
        'content.duplicate' => 'コンテンツを複製',
        'content.update' => 'コンテンツを更新',
        'content.delete' => 'コンテンツを削除',
        'content.type.create' => '投稿タイプを作成',
        'page.create' => 'ページを作成',
        'page.duplicate' => 'ページを複製',
        'page.update' => 'ページを更新',
        'page.document.patch' => 'ページの本文を編集',
        'page.publish' => 'ページを公開',
        'page.unpublish' => 'ページを下書きへ戻す',
        'page.trash' => 'ページをゴミ箱へ',
        'page.restore' => 'ページを復元',
        'term.create' => '分類項目を作成',
        'term.update' => '分類項目を更新',
        'term.reorder' => '分類項目を並べ替え',
        'content.reorder' => '投稿を並べ替え',
        'term.delete' => '分類項目を削除',
        'media.upload' => 'メディアを追加',
        'media.update' => 'メディアを更新',
        'media.replace' => 'メディアを差し替え',
        'media.delete' => 'メディアを削除',
        'settings.update' => 'サイト設定を更新',
        'contact.updateStatus' => 'お問い合わせの状態を更新',
        'user.invite' => '利用者を招待',
        'user.update' => '利用者の情報を更新',
        'user.role.change' => '利用者の権限を変更',
        'user.deactivate' => '利用者を無効化',
        'user.reactivate' => '利用者を再有効化',
        'user.invitation.resend' => '招待メールを再送',
        'user.setup_link.issue' => 'パスワード設定 URL を発行',
    ];

    public static function operation(string $operationId): string
    {
        return self::OPERATIONS[$operationId]
            ?? self::AUTH_OPERATIONS[$operationId]
            ?? $operationId;
    }

    public static function result(string $result): string
    {
        return match (OperationStatus::tryFrom($result)) {
            OperationStatus::Success => '成功',
            OperationStatus::ValidationError => '入力エラー',
            OperationStatus::NotFound => '対象なし',
            OperationStatus::Forbidden => '権限なし',
            OperationStatus::Conflict => '競合',
            OperationStatus::ConfirmationRequired => '確認待ち',
            default => $result,
        };
    }

    public static function interface(string $interface): string
    {
        return match (OperationInterface::tryFrom($interface)) {
            OperationInterface::Admin => '管理画面',
            OperationInterface::Api => 'API',
            OperationInterface::Mcp, OperationInterface::WebMcp => 'AI 連携',
            OperationInterface::Cli => 'コマンド',
            OperationInterface::System => 'システム',
            default => $interface,
        };
    }

    /** @return list<string> */
    public static function authOperations(): array
    {
        return array_keys(self::AUTH_OPERATIONS);
    }

    /**
     * 絞り込みの選択肢（画面へ渡す）。
     *
     * @return list<array{value: string, label: string}>
     */
    public static function operationOptions(): array
    {
        $options = [];

        foreach ([...array_keys(self::OPERATIONS), ...array_keys(self::AUTH_OPERATIONS)] as $id) {
            $options[] = ['value' => $id, 'label' => self::operation($id)];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    public static function resultOptions(): array
    {
        return array_map(
            static fn (OperationStatus $status): array => [
                'value' => $status->value,
                'label' => self::result($status->value),
            ],
            OperationStatus::cases(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    public static function interfaceOptions(): array
    {
        return array_map(
            static fn (OperationInterface $interface): array => [
                'value' => $interface->value,
                'label' => self::interface($interface->value),
            ],
            OperationInterface::cases(),
        );
    }
}
