import tseslint from "typescript-eslint";

/**
 * CLI（Node/TS）用 lint gate。
 * 初期構築時から devDependencies（eslint / typescript-eslint）と lint script は
 * 用意されていたが config が欠落していた（dead gate）。ここでは新しい style rule で
 * 既存コードに大量の churn を起こさず、typescript-eslint recommended を基準にする。
 * （style/format は tsc + 既存コード規約に委ね、lint は正しさの検査に限定）
 */
export default tseslint.config(
  {
    ignores: ["dist/**", "node_modules/**", "tests/fixtures/**"],
  },
  ...tseslint.configs.recommended,
  {
    rules: {
      // 既存コードは cross-platform の防御的 catch を多用する — 空 catch の変数省略を許可
      "@typescript-eslint/no-unused-vars": [
        "error",
        {
          argsIgnorePattern: "^_",
          varsIgnorePattern: "^_",
          caughtErrors: "none",
        },
      ],
    },
  },
);
