<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX 一周 第 20 周（税理士事務所の所長の目・業種の入口から・2026-10-04）で見つけたものの回帰。
 *
 * 見つけたのは 1 つ。リッチテキストを置いて本文を打つと、初期文言「リッチテキスト」の後ろに続き、
 * 「リッチテキスト平成12年の開業以来…」のまま公開された（見出し・テキストの「見出し」「テキスト」も同じ作り）。
 * 置いた直後に最初の文字の欄へ焦点を移し、中身を選んだ状態にする（打ち始めで置き換わる）。
 * 実ブラウザの振る舞いは Playwright で実測した。
 */
final class UxWalkRound20Test extends TestCase
{
    public function test_置いた直後は最初の欄の初期文言を選んだ状態にする(): void
    {
        $editor = (string) file_get_contents(resource_path('js/pages/admin/pages/Editor.vue'));

        $this->assertStringContainsString('function focusFirstField(): void', $editor);
        $this->assertStringContainsString('requestAnimationFrame(focusFirstField);', $editor);
        // input / textarea は select()、contenteditable（リッチテキスト）は選択範囲で中身を選ぶ
        $this->assertStringContainsString('field.select();', $editor);
        $this->assertStringContainsString('range.selectNodeContents(field);', $editor);
    }
}
