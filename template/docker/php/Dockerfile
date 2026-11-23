FROM php:8.5-fpm-alpine

# gd: 画像アップロード / pdo_mysql: MySQL / intl,zip,bcmath,opcache: Laravel 標準要件
# 拡張のビルドは公式推奨の install-php-extensions を使用（依存解決とクリーンアップを自動化）
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd pdo_mysql intl zip bcmath opcache @composer

# Composer の zip 展開を PHP zip extension（in-process・同期）に固定する（Phase 8 RC2 finding）。
# unzip バイナリ（Alpine では busybox applet）が存在すると Composer は子プロセス展開を選び、
# macOS Docker Desktop の bind mount 上で展開結果の可視性 race により
# "Install of <pkg> failed" が高頻度発生する（実測: busybox unzip は 5 回中 1 回失敗、
# COMPOSER_MAX_PARALLEL_PROCESSES=1 の直列化はむしろ 5 回中 5 回失敗、
# PHP zip extension は 11 回中 11 回成功。container ローカル filesystem 上では
# unzip でも失敗しない = bind mount 起因）。busybox 本体は残るため sh/rm 等の applet に影響しない。
RUN rm -f /usr/bin/unzip /usr/bin/7z /usr/bin/7zz /usr/bin/7za

COPY php.ini /usr/local/etc/php/conf.d/agentcms.ini

# Runtime user mapping（Phase 7 RC2 Blocker 2）:
# bind mount された project へ container process が書き込めるよう、
# PHP-FPM worker をホストの UID/GID で実行する（WSL2 で必須・macOS でも無害）。
# chmod 777 / chown による回避は禁止 — identity を合わせるのが正。
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN set -eux; \
    if ! getent group "${HOST_GID}" >/dev/null; then addgroup -g "${HOST_GID}" app; fi; \
    GROUP_NAME="$(getent group "${HOST_GID}" | cut -d: -f1)"; \
    if ! getent passwd "${HOST_UID}" >/dev/null; then adduser -D -u "${HOST_UID}" -G "${GROUP_NAME}" app; fi; \
    USER_NAME="$(getent passwd "${HOST_UID}" | cut -d: -f1)"; \
    { echo "[www]"; echo "user = ${USER_NAME}"; echo "group = ${GROUP_NAME}"; } \
      > /usr/local/etc/php-fpm.d/zz-agentcms-user.conf

# exec（composer / artisan）を --user で実行しても cache が書けるよう /tmp 配下へ
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /var/www/html
