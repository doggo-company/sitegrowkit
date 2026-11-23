import { distributesContentType } from "../../src/distribution.js";

/**
 * **生成物が、利用者の世界に無いものを呼んでいないか**（R-13 / B-35）。
 *
 * import と figma は別の生成器だが、**壊れ方は同じ**である。
 * どちらも「HTML / Design がそう見えるか」だけで受け皿を決め、
 * **型が在るかを見ないまま** `Modules\Works` や `route('works')` を書き出していた。
 *
 * ▷ 検査の座を 1 つにする。二重に持つと、片方だけ更新されて**ずれる**。
 */

/** 生成物が呼んでいる route 名（`route('x')` / `route("x")` の x） */
export function emittedRouteNames(all: string): string[] {
  return [
    ...new Set([...all.matchAll(/route\(['"]([^'"]+)['"]/g)].map((m) => m[1]!)),
  ];
}

/**
 * 生成した routes ファイルが定義している route 名。
 *
 * `prefix` は `->name('figma.')->group(...)` のような群の接頭辞
 * （生成器ごとに違うので、知っている側が渡す）。
 */
export function declaredRouteNames(php: string, prefix = ""): Set<string> {
  return new Set(
    [...php.matchAll(/->name\('([^']+)'\)/g)]
      .map((m) => m[1]!)
      // 群の接頭辞を宣言している行そのものは route ではない
      .filter((n) => n !== prefix)
      .map((n) => `${prefix}${n}`),
  );
}

/** 解決できない route 名（利用者に在る組み込み route でも、生成した route でもないもの） */
export function unresolvedRoutes(
  all: string,
  builtin: Iterable<string>,
  declared: Iterable<string>,
): string[] {
  const known = new Set([...builtin, ...declared]);

  return emittedRouteNames(all).filter((r) => !known.has(r));
}

/**
 * 配らない投稿タイプの module を名指ししていないか、を見るための印。
 *
 * 手で並べると**当たり方を説明できない印**が混ざるので、**配布境界から引く**。
 * 当たる形は 2 つ — 生成先のディレクトリと、PHP の FQCN（円記号 1 つ）。
 */
export function undistributedModuleMarkers(kitSchemaIds: string[]): string[] {
  return kitSchemaIds
    .filter((id) => !distributesContentType(id))
    .flatMap((id) => {
      const pascal = id[0]!.toUpperCase() + id.slice(1);

      return [`modules/${pascal}/`, `Modules\\${pascal}`];
    });
}

/**
 * 生成物が名指ししているモデルのクラス（`Modules\X\Models\Y`）。
 *
 * route の針だけでは足りなかった（R-13 12 件目）。詳細ページの生成は
 * `route()` を 1 つも書かずに **`\Modules\Works\Models\Work::published()`** と
 * クラスを直接呼ぶので、route を見ているだけでは通り抜ける。
 */
export function emittedModelClasses(all: string): string[] {
  return [
    ...new Set(
      [
        ...all.matchAll(/Modules\\([A-Za-z0-9_]+)\\Models\\([A-Za-z0-9_]+)/g),
      ].map((m) => `Modules\\${m[1]!}\\Models\\${m[2]!}`),
    ),
  ];
}

/**
 * そのプロジェクトに**無い**モデルを呼んでいないか。
 *
 * `known` には plan が宣言した投入先（`seeds.collections[].model`）と、
 * 配られる kit の module のクラスを渡す。
 */
export function unresolvedModels(
  all: string,
  known: Iterable<string>,
): string[] {
  const ok = new Set(known);

  return emittedModelClasses(all).filter((c) => !ok.has(c));
}

/**
 * **針と「刺さる入力」を、型で組にする**（監査 指摘 39）。
 *
 * route の針には canary を置いたのに、隣に足したモデルの針には置き忘れた。
 * 「忘れないようにする」ではなく、**忘れたら型で見える**形にする。
 *
 * ここへ足すと `firesOn` を書かないとコンパイルが通らず、
 * `output-guard.test.ts` が**その入力で本当に鳴るか**を毎回確かめる。
 *
 * ## セットの決まり（この座を触るときは両方）
 *
 * - **針を足したら canary**（ここへ登録する = 刺さる入力を書く）
 * - **静的な網を外したら、動的な網に試験**（片方を消して片方を無試験にしない。
 *   実例: `RESERVED_TABLES` から kit の例を外したとき、
 *   代わりに効く実在検査は 1 つも試験を持っていなかった — 監査 指摘 38）
 */
export interface OutputNeedle {
  /** 何を見ている針か（失敗時に出る） */
  name: string;
  /** 生成物から「引っかかったもの」を集める */
  find: (all: string) => string[];
  /** **必ず刺さる入力**。実際の生成物から取った形をそのまま置く */
  firesOn: string;
}

export const OUTPUT_NEEDLES: OutputNeedle[] = [
  {
    name: "route（名前付き route の呼び出し）",
    find: emittedRouteNames,
    firesOn: `<a href="{{ route('works.show', $work->slug) }}">詳しく</a>`,
  },
  {
    name: "model（モデルのクラスの直接呼び出し）",
    find: emittedModelClasses,
    firesOn: `@php($__works = \\Modules\\Works\\Models\\Work::published()->get())`,
  },
  {
    name: "module（配らない module の名指し）",
    find: (all) =>
      undistributedModuleMarkers(["works"]).filter((m) => all.includes(m)),
    firesOn: `modules/Works/views/index.blade.php\nuse Modules\\Works\\Models\\Work;`,
  },
];
