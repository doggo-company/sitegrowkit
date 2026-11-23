import { describe, expect, it } from 'vitest';
import {
    AGENT_TABS,
    FIRST_PROMPT,
    MCP_SETUP_COMMAND,
    OTHER_AGENTS_NOTE,
} from '../ai-setup';

/**
 * Stage 1 — 「AI でサイトを作る」を画面から**辿れる**ようにする。
 *
 * ## 無かったのではなく、ここからは見えなかった
 *
 * MCP 設定の生成 UI は `agentcms setup`（Wizard）に**もう在る**。
 * ダッシュボードのカードが `href: null` で、**そこへ辿れなかった**だけである。
 * だから足すのは仕組みではなく、**その場でできることと、行き先**である。
 */
describe('AI 連携パネルの中身', () => {
    it('タブは 5 つで、順番も決まっている', () => {
        expect(AGENT_TABS.map((t) => t.id)).toEqual([
            'claude-code',
            'codex',
            'gemini-cli',
            'opencode',
            'grok-build',
        ]);
    });

    /** **画面に出すのは「その場で打てる 1 行」だけ。** 説明は docs が持つ */
    it('どのタブにも起動の 1 行がある', () => {
        for (const tab of AGENT_TABS) {
            expect(tab.launch.trim(), tab.id).not.toBe('');
            // **1 行であること**（複数行の手順を画面へ持ち込まない）
            expect(tab.launch.includes('\n'), tab.id).toBe(false);
        }
    });

    /**
     * **差を正直に書く。**
     *
     * MCP 設定を自動生成できるのは `setup` が対応している 3 つだけである。
     * それを黙っていると、**手動タブの利用者は「自分のやり方が悪い」と思う**。
     */
    it('自動生成できないタブは、その旨を持っている', () => {
        const manual = AGENT_TABS.filter((t) => t.mcp === 'manual');

        expect(manual.map((t) => t.id)).toEqual([
            'gemini-cli',
            'opencode',
            'grok-build',
        ]);

        for (const tab of manual) {
            expect(tab.note ?? '', tab.id).toContain('自動生成');
        }
    });

    it('自動生成できるタブは、断り書きを持たない', () => {
        for (const tab of AGENT_TABS.filter((t) => t.mcp === 'auto')) {
            expect(tab.note ?? '', tab.id).toBe('');
        }
    });

    /** MCP をつなぐ道は 1 行で示す（`agentcms setup` の中の面へ送る） */
    it('MCP 接続は 1 行で示す', () => {
        expect(MCP_SETUP_COMMAND).toBe('agentcms setup');
    });

    /**
     * **押せるものには中身がある。**
     *
     * 表示だけあってコピーの中身が空、を作らない。
     */
    it('最初のプロンプトは空でなく、読ませる先を指している', () => {
        expect(FIRST_PROMPT.trim()).not.toBe('');
        expect(FIRST_PROMPT).toContain('.agentcms/');
        expect(FIRST_PROMPT).toContain('agentcms verify');
    });

    /**
     * **タブに無いエージェントを、使えないことにしない。**
     *
     * タブに出すのは公式の CLI があるものだけだが、
     * **MCP に対応していれば繋がる**ことは書いておく。
     */
    it('タブ以外でも繋がることを書いている', () => {
        expect(OTHER_AGENTS_NOTE).toContain('MCP');
    });

    /**
     * **「自動生成の対象でない」と「つながらない」は別である。**
     *
     * Grok Build は MCP にネイティブ対応なので、Claude Code 用に書き出した設定が
     * そのまま効く。**手動タブ = 使えない、に読ませない。**
     */
    it('Grok Build には、既存の MCP 設定が効くと書いてある', () => {
        const grok = AGENT_TABS.find((t) => t.id === 'grok-build');

        expect(grok?.note ?? '').toContain('そのまま');
    });
});
