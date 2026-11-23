# フロントエンド tooling（pnpm / Vite / vue-tsc / ESLint）専用イメージ。
#
# 目的（Phase 8.5-A）: 利用者のホストに Node.js / pnpm を要求しない。
# 依存インストールと production build は常にこのコンテナで実行する。
#
# alpine ではなく glibc（bookworm-slim）を使う: Vite の依存（esbuild / rollup /
# lightningcss）は platform + libc 別のバイナリを持ち、musl 版よりも
# linux-*-gnu 版のほうが配布が安定しているため。
FROM node:22-bookworm-slim

# pnpm は package.json の packageManager 宣言（pin）に従わせる。
# corepack の shim を有効化しておき、実行時に project の pin が解決される。
RUN corepack enable

# Runtime user mapping（php イメージと同じ方針 — Phase 7 RC2 Blocker 2）:
# bind mount 上に root 所有の node_modules / public/build を残さないため、
# ホストの UID/GID で実行する。chmod 777 / chown による回避は禁止。
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN set -eux; \
    if ! getent group "${HOST_GID}" >/dev/null; then groupadd -g "${HOST_GID}" app; fi; \
    GROUP_NAME="$(getent group "${HOST_GID}" | cut -d: -f1)"; \
    if ! getent passwd "${HOST_UID}" >/dev/null; then \
      useradd -m -u "${HOST_UID}" -g "${GROUP_NAME}" -s /bin/bash app; \
    fi; \
    USER_NAME="$(getent passwd "${HOST_UID}" | cut -d: -f1)"; \
    HOME_DIR="/home/agentcms"; \
    mkdir -p "${HOME_DIR}/.cache" "${HOME_DIR}/.local/share/pnpm"; \
    chown -R "${HOST_UID}:${HOST_GID}" "${HOME_DIR}"

# --user 実行でも corepack / pnpm がキャッシュを書けるようにする。
# HOME 配下は named volume（node-home）で永続化し、再実行時の再ダウンロードを避ける。
#
# store-dir は node_modules 配下に置く: pnpm は hard link のため store と
# node_modules が同一ファイルシステム上にある必要があり、named volume 側に置くと
# プロジェクト直下へ `.pnpm-store` が自動生成されてしまう（利用者のプロジェクトに
# 見慣れないディレクトリを増やさない）。node_modules は既に gitignore・
# create のコピー対象外・deploy artifact 対象外なので追加の除外設定が不要。
ENV HOME=/home/agentcms \
    COREPACK_HOME=/home/agentcms/.cache/node/corepack \
    PNPM_HOME=/home/agentcms/.local/share/pnpm \
    npm_config_cache=/home/agentcms/.cache/npm \
    npm_config_store_dir=/var/www/html/node_modules/.pnpm-store \
    CI=true

WORKDIR /var/www/html
