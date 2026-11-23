# Project Map (read this before exploring)

| I need… | Go to |
| --- | --- |
| Architecture / boundaries | `.agentcms/architecture.md`, `.agentcms/rules/boundaries.md` |
| Commands | `.agentcms/commands.md` |
| Rules (coding/security/testing/deployment) | `.agentcms/rules/` |
| Content type definitions | `content/schemas/*.json` (registry: `.agentcms/registry/content-types.json`) |
| Content Type Builder (admin UI → schema + module + migration) | `core/src/ContentTypes/`, `core/src/Application/ContentTypes/` (ADR-0014) |
| Content Type Builder の画面 / route | `resources/js/pages/admin/content-types/`, `app/Http/Controllers/Admin/ContentTypeAdminController.php` |
| CMS が作った型の CRUD（type ごとの Controller を増やさない） | `core/src/Admin/ManagedContentController.php` |
| Field contract / 20 field types / storage 分類 | `core/src/Schema/Field.php`, `FieldStorage.php` |
| Custom Fields の値分割・richtext sanitize・repeater ID | `core/src/Application/Content/ContentValues.php` |
| relation 参照整合（RESTRICT） | `core/src/Application/Content/ContentReferences.php` |
| schema 変更の危険度分類 | `core/src/Schema/SchemaMutationClassifier.php` |
| Component registry | `.agentcms/registry/components.json` |
| Project identity / kit version | `.agentcms/project.json` |
| Local secrets (admin credential — NOT AI context) | `.agentcms/local/` |
| CMS Core | `core/src/` (read-only in generated projects) |
| Modules (news/works/contact/site/media/notifications) | `modules/<name>/` |
| Admin PWA (manifest/SW/offline/icons) | `app/Http/Controllers/Admin/PwaController.php`, `public/admin-pwa/`, `resources/js/composables/useAdminPwa.ts` |
| Web Push (transport/notifier/types) | `modules/Notifications/` (ADR-0007) |
| Public pages (Blade) | `resources/views/` |
| Admin pages (Vue) | `resources/js/pages/` |
| Docker services | `docker-compose.yml` (ports come from `.env`: `APP_PORT` / `MAILPIT_UI_PORT` / `DB_FORWARD_PORT`) |
| E2E / Lighthouse | `tests/e2e/`, `lighthouserc.json` |
| Deploy config / staging env templates | `.agentcms/deploy/staging.example.json`, `env.staging.example` |
| Staging health/noindex/mail-safety (Laravel side) | `app/Http/Controllers/HealthController.php`, `app/Http/Middleware/StagingNoindex.php`, `app/Providers/AppServiceProvider.php` |
| Import manifests / plan / provenance | `.agentcms/manifest/template*.json` （`agentcms template inspect/plan/apply` 実行時に生成 — 新規 project には存在しない） |
| Figma Design Manifest / plan / provenance | `.agentcms/manifest/figma*.json`, Component Registry: `.agentcms/components/registry.json` （`agentcms figma inspect/plan/apply` 実行時に生成 — 新規 project には存在しない） |
| Figma raw cache / renders (gitignored client data) | `.agentcms/cache/figma/` （figma inspect 実行時に生成・AI context 対象外） |

