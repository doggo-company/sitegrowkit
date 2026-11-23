// PageDocument（Phase 9.1D / 9.6）。core の DocumentTree / ComponentDefinition と対応する。

/** component 定義の 1 つの prop。`ComponentDefinition::toSpec()` の propsSchema と対応 */
export interface ComponentPropSpec {
    type: string;
    /** `html` は「HTML を受け取る」という宣言。richtext の Field だけが流せる（§4.9） */
    format?: string;
    label?: string;
    required?: boolean;
    default?: unknown;
    values?: unknown[];
    /** 選択肢の表示名（`primary` → 目立たせる）。無い値はそのまま出す */
    valueLabels?: Record<string, string>;
    /** 保存値と表示名を分けた、サーバーが用意する選択肢。 */
    options?: { value: string; label: string }[];
    group?: string;
    css?: string;
    min?: number;
    max?: number;
    /** 欄の下に出す書き方の案内（例: 1 行に 1 品） */
    help?: string;
}

/** binding の接続先に出せる項目（Editor の props `bindingSources`） */
export interface BindingField {
    key: string;
    label: string;
    type: string;
}

/** 接続先の候補。**型整合の表は入っていない**（`ComponentSpec.accepts` が解いた結果を持つ） */
export interface BindingSources {
    entry: { type: string; label: string; fields: BindingField[] }[];
    collection: { type: string; label: string }[];
    page: BindingField[];
}

/** `GET /api/v1/components` / Editor の props で受け取る component の仕様 */
export interface ComponentSpec {
    type: string;
    label: string;
    category: string;
    since: number;
    propsSchema: Record<string, ComponentPropSpec>;
    styleSchema: string[];
    bindable: string[];
    /**
     * bindable な prop ごとに、**流せる Field 型**（サーバーの `BindingTypes` が解いた結果）。
     *
     * **ここに表を作らない。** 型整合の表は Core にしかなく、画面は
     * 「この prop の候補にこの Field の型が入っているか」を見るだけである。
     * `enum` の prop では緩い側へ倒してあるので、最後の判断は保存時の検証が持つ。
     */
    accepts: Record<string, string[]>;
    /** `*` は任意の component を許可 */
    allowedChildren: string[];
    allowedParents: string[];
    responsive: string[];
    container: boolean;
    /**
     * アクセシビリティ検査の宣言（`AccessibilityCheck` が読むもの）。
     * `level` は見出しの段を持つ prop の名前。宣言が無い component は見出しではない。
     */
    a11y?: { level?: string; [key: string]: unknown };
}

/**
 * document の 1 ノード。
 *
 * `id` は ULID（サーバーが採番する）。`key` は人が読む識別子で、無いこともある。
 */
export interface DocumentNode {
    id: string;
    key?: string;
    type: string;
    props?: Record<string, unknown>;
    style?: Record<string, string>;
    responsive?: Record<string, Record<string, string>>;
    binding?: Record<string, unknown>;
    /**
     * 参照している再利用 component の id（Phase 9.6 / P0-C7・§4.5）。
     *
     * これを持つ node は **`props` / `children` を自分で持たない** — 中身は実体から来る。
     * **入れ物の type は実体の起点と同じ**でなければならない（揃っていないと、
     * 展開したときに置き場所の規則に反する木ができる）。
     */
    instanceOf?: string;
    children?: DocumentNode[];
}

export interface PageDocument {
    schemaVersion: number;
    root: DocumentNode;
}

/** Editor が送る semantic command。JSON Patch はサーバー側で組み立てられる */
export type DocumentCommand =
    | {
          operation: 'component.insert';
          parent: string;
          after?: string | null;
          component: Partial<DocumentNode> & { type: string };
          expectedVersion: number;
      }
    | {
          operation: 'component.move';
          target: string;
          parent?: string;
          after?: string | null;
          expectedVersion: number;
      }
    | {
          operation: 'component.update';
          target: string;
          /**
           * 送った差分だけが変わる。**値を外すときは `null`**。
           *
           * `props` / `style` / `responsive` は入れ子の差分だが、
           * **`binding` と `key` は重ねずに差し替える欄**で、`binding` は
           * `null` を送ると外れる（§4.9）。だから値の型は入れ子に限らない。
           */
          changes: Record<string, unknown>;
          expectedVersion: number;
      }
    | {
          operation: 'component.duplicate';
          target: string;
          expectedVersion: number;
      }
    | {
          /**
           * 再利用 component から切り離す（§4.5）。**戻せない片方向**。
           *
           * 独立した operation にしないのは、document を変える口が 2 本になると
           * validator・確認ゲート・監査・楽観ロックを二重化するか穴にするかになるため。
           */
          operation: 'component.detach';
          target: string;
          expectedVersion: number;
      }
    | {
          operation: 'component.delete';
          target: string;
          expectedVersion: number;
      };

/**
 * アクセシビリティ検査の 1 件（`AgentCms\Core\Document\AccessibilityCheck`）。
 *
 * `warning` は直したほうがよいもの、`unknown` は **ここでは判定できないので確認が要る**もの。
 * どちらも保存や公開を止めない。
 */
/**
 * 版の一覧の 1 行（`PHASE9_6_SPEC.md` §4.12）。
 *
 * **`document` は入っていない。** 一覧が答えるのは「いつ・誰が・どういう保存か」で、
 * 中身は入れない（大きく、本文がそのまま応答へ流れるため）。
 */
export interface PageVersionSummary {
    revision: number;
    createdAt: string | null;
    /** 利用者の id。名前は出さない（出すと決めた場所の仕事） */
    createdBy: number | null;
    createdInterface: string;
    /** 自動保存で増えた版か（間引きの対象になるのはこちらだけ） */
    autosave: boolean;
    draft: boolean;
    published: boolean;
}

export interface AccessibilityFinding {
    nodeId: string;
    type: string;
    rule: string;
    /** `todo` はまだ入れていないだけのもの（未設定）。間違いではない */
    level: 'warning' | 'unknown' | 'todo';
    message: string;
}

/** 画面幅の宣言（`content/theme.json` 由来。名前も px もサーバーが決める） */
export interface BreakpointSpec {
    name: string;
    /** 画面に出す呼び名（サーバーが決める。無ければ name） */
    label?: string;
    /** 適用される条件（媒体クエリ。表示用） */
    condition: string;
    /** Canvas をこの幅にして確認する */
    width: number;
}

export interface ContentTypeOption {
    id: string;
    label: string;
}

/**
 * style プロパティの宣言（`AgentCms\Core\Theme\StyleProperties::specs()` と対応）。
 *
 * `values` が配列なら **その中からしか選べない**。null なら自由入力
 * （値の集合が閉じていないので breakpoint ごとの上書きもできない）。
 */
export interface StylePropertySpec {
    label: string;
    values: string[] | null;
    /** 値の表示名（`left` → 左寄せ）。無い値はそのまま出す */
    valueLabels?: Record<string, string>;
    responsive: boolean;
}

/**
 * パレットの 1 件。`defaults` は **サーバーが決めた初期値**で、
 * そのまま追加しても検証を通ることが保証されている
 * （`PageAdminController::defaultsFor()` と対応）。
 */
export interface PaletteEntry extends ComponentSpec {
    defaults: Partial<DocumentNode>;
}
