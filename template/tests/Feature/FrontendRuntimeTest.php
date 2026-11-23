<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 8.5-A: フロントエンド tooling（pnpm / Vite）は node コンテナで実行し、
 * 利用者のホストに Node.js / pnpm を要求しない。
 * その前提となる compose 定義・イメージ定義の regression。
 */
final class FrontendRuntimeTest extends TestCase
{
    public function test_compose_defines_node_tooling_service(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertMatchesRegularExpression('/^  node:/m', $compose);
        // 常駐サービスにしない（docker compose up --wait の対象を増やさない）
        $this->assertStringContainsString("profiles: ['tools']", $compose);
        $this->assertStringContainsString('context: ./docker/node', $compose);
    }

    public function test_node_image_runs_as_host_user(): void
    {
        $dockerfile = (string) file_get_contents(base_path('docker/node/Dockerfile'));

        // bind mount 上に root 所有の node_modules / public/build を残さない
        $this->assertStringContainsString('ARG HOST_UID=1000', $dockerfile);
        $this->assertStringContainsString('ARG HOST_GID=1000', $dockerfile);
        // pnpm の version は package.json の packageManager pin に従わせる
        $this->assertStringContainsString('corepack enable', $dockerfile);
    }

    public function test_dependencies_resolve_for_container_build(): void
    {
        // ネイティブバイナリ（rolldown / lightningcss 等）は linux コンテナ向けの
        // 解決が必須（これが無いと build が "Cannot find native binding" で失敗する）。
        // 設定は pnpm-workspace.yaml に置く（package.json の "pnpm" フィールドは
        // 新しい pnpm では読まれず、無視されるため）
        $workspace = (string) file_get_contents(base_path('pnpm-workspace.yaml'));

        $this->assertStringContainsString('supportedArchitectures:', $workspace);
        $this->assertMatchesRegularExpression('/os:\s*\n\s*- current\s*\n\s*- linux/', $workspace);
        $this->assertMatchesRegularExpression('/libc:\s*\n\s*- current\s*\n\s*- glibc/', $workspace);

        $pkg = json_decode((string) file_get_contents(base_path('package.json')), true);
        $this->assertArrayNotHasKey('pnpm', (array) $pkg, 'package.json の "pnpm" フィールドは使わない');
    }
}
