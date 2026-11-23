/**
 * ダッシュボードから AI 連携へ辿るための中身（Stage 1）。
 *
 * ## 無かったのではなく、ここからは見えなかった
 *
 * MCP 設定を書き出す面は `agentcms setup`（セットアップ画面）に**もう在る**。
 * ダッシュボードの「AI でカスタマイズする」が**押せなかった**ので、そこへ辿れなかった。
 * だから足すのは仕組みではなく、**その場で打てる 1 行と、行き先**である。
 *
 * ## 画面は短く、正本は 1 つ
 *
 * 詳しい説明は `docs/AI_AGENTS.md` が持つ。ここが持つのは
 * **起動の 1 行 / MCP をつなぐ 1 行 / 最初のプロンプト**だけ。
 *
 * `FIRST_PROMPT` は `docs/AI_AGENTS.md` の**複製**である
 * （`docs/` は生成プロジェクトへ配られないので、実行時に読むことはできない）。
 * **複製は許すが、黙ってずれることは許さない** — ずれたら
 * `tests/Feature/AiSetupPanelTest.php` が赤くなる。
 */

export type McpSupport = 'auto' | 'manual';

export interface AgentTab {
    id: string;
    label: string;
    /** プロジェクトのフォルダで打つ 1 行 */
    launch: string;
    /** `agentcms setup` が設定を書き出せるか */
    mcp: McpSupport;
    /** 書き出せない場合の断り（**差を正直に書く**） */
    note?: string;
}

/** MCP をつなぐ道は 1 本（セットアップ画面の「AI エージェント連携」） */
export const MCP_SETUP_COMMAND = 'agentcms setup';

/**
 * タブに出すエージェント。
 *
 * **公式の CLI があるものだけ**を出す。ここに無いものも
 * 「使えない」わけではないので、`OTHER_AGENTS_NOTE` で拾う。
 */
export const AGENT_TABS: AgentTab[] = [
    {
        id: 'claude-code',
        label: 'Claude Code',
        launch: 'claude',
        mcp: 'auto',
    },
    {
        id: 'codex',
        label: 'Codex',
        launch: 'codex',
        mcp: 'auto',
    },
    {
        id: 'gemini-cli',
        label: 'Gemini CLI',
        launch: 'gemini',
        mcp: 'manual',
        note: 'MCP 設定の自動生成に対応しているのは Claude Code / Cursor / Codex です。このタブでは設定を手動で 1 行足してください。',
    },
    {
        id: 'opencode',
        label: 'opencode',
        launch: 'opencode',
        mcp: 'manual',
        note: 'MCP 設定の自動生成に対応しているのは Claude Code / Cursor / Codex です。このタブでは設定を手動で 1 行足してください。',
    },
    {
        id: 'grok-build',
        label: 'Grok Build',
        launch: 'grok',
        mcp: 'manual',
        /*
         * **MCP は共通の口である。** Grok Build は MCP にネイティブ対応なので、
         * Claude Code 用に書き出した設定が**そのまま効く**。
         * 「自動生成の対象ではない」ことと「つながらない」ことは別である。
         */
        note: 'MCP 設定の自動生成に対応しているのは Claude Code / Cursor / Codex です。Grok Build は MCP に対応しているので、Claude Code 用に書き出した設定がそのまま使えます。',
    },
];

/**
 * 最初のプロンプト。**正本は `docs/AI_AGENTS.md`**（ずれたら試験が赤くなる）。
 */
export const FIRST_PROMPT = `.agentcms/ のルールに従って作業してください。
まず .agentcms/architecture.md と rules/boundaries.md を読み、
変更前に対象を調査し、core/ は編集せず、
作業後は \`agentcms verify\` で検証してください。
タスク: <あなたの依頼内容>`;

/** タブに無いエージェントを「使えない」ことにしない */
export const OTHER_AGENTS_NOTE =
    'ここに無いエージェントでも、MCP に対応していれば同じようにつなげます。';
