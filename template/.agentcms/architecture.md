# Architecture (agent view)

Generated Agent CMS project. Two layers, never mix them:

| Path | What | Who edits |
| --- | --- | --- |
| `core/` | CMS Core package `agentcms/core` (path repo) | READ-ONLY — never edit. Extend only via published extension points (`core/src/Contracts/`, `core/config/`) |
| `app/`, `modules/`, `content/`, `resources/` | Project layer (this site's code) | site dev / AI |
| `content/schemas/*.json` | Content type definitions (Schema First SSoT) | site dev / AI (kit-standard schemas: use `content/schemas/overrides/`) |
| `.agentcms/` | AI context / rules / registries / manifests | rules & context: managed by the kit (regenerate via `agentcms sync-agents`); manifests: written by agentcms CLI |
| `.agentcms/local/` | Local-only secrets (admin credentials etc.) | NOT AI context. Never read, print, or commit its contents |
| `.agentcms/project.json` | Project identity + kit version metadata | agentcms CLI |

Runtime: `agentcms dev` (Docker required) starts nginx+php / mysql / mailpit and finishes setup (deps, APP_KEY, migrate, seed, frontend build, health check). Ports live in `.env` (`APP_PORT` etc.). Quality gate: `agentcms verify`.

Stack: Laravel 13 / PHP 8.5 / MySQL 8.4 / Inertia 3 + Vue 3 + TS (admin only) / Blade + plain CSS (public site) / Docker (php, mysql, mailpit) / Vite.

Data flow (CMS): `content/schemas/*.json` → Schema registry (core) → admin form/list + validation + model casts. Schema is the single source for content types.

Content Type Builder (Phase 9.4): `/admin/content-types` adds collection types **without editing code**. Same SSoT — it writes `content/schemas/<id>.json` (`"managedBy": "cms"`, an existing key) plus `modules/<Studly>/Models/<Studly>.php` and one migration; the model FQCN is derived deterministically from the id, so there is no extra registry file. Flow is Preview → Confirm (`ConfirmationGate`) → Apply: nothing is written until the token comes back, schema is published LAST (readers never see a half-built type), and any failure rolls back only what it created. **New types only** — field delete/rename/type change and content-type deletion are refused (Owner Gate G4). Not exposed to MCP: creation is Admin/CLI only, while entries of the new type use the existing `content.*` operations. Details: ADR-0014.

Contact pipeline (order fixed): validate → Turnstile siteverify → DB save → admin mail → auto-reply → record notification result → web push (independent). DB save must survive mail failure; mail and push must not fail each other (Notifier never throws).

Admin PWA: scope `/admin` only (manifest + SW served via routes, `Service-Worker-Allowed: /admin`). Never let the SW touch the public site or cache admin HTML/API. Web Push: `modules/Notifications/` — `Notifier` → `PushTransport` boundary (WebPush/Fake), VAPID keys in .env only (`php artisan notifications:vapid-keys`). Details: ADR-0007.

Template Import (Phase 5): `agentcms template inspect|plan|apply|verify` converts a static HTML/CSS/JS site into this project. inspect is deterministic (no AI) → Manifest v2 (`.agentcms/manifest/template.json`, fingerprinted); plan explains every decision with confidence; apply writes ONLY to the project layer (views/public/imported assets/seeder/schema-choice additions) with conflict STOP + idempotency + provenance. Preserve First: never rewrite CSS/classes/JS/design; agents read the Manifest before any raw template file. External deps get CSP origins via env `AGENTCMS_CSP_*` (core config, default empty). Details: ADR-0010.

Figma Import (Phase 6): `agentcms figma connect|inspect|diff|plan|apply|verify` — REST-API Standard Mode (PAT via env `FIGMA_ACCESS_TOKEN`, scopes `file_metadata:read`+`file_content:read`; MCP/Variables/Code Connect are optional capabilities, never dependencies). Manifest First: Raw Figma JSON never goes to AI — Metadata First → node-targeted fetch → Raw Cache (gitignored, NOT AI context) → deterministic Design Manifest (`.agentcms/manifest/figma.json`, fingerprinted) → Component Registry (`.agentcms/components/registry.json`). Read-only toward Figma: apply writes to the local project layer only (`resources/views/figma/`, `public/figma/`, `routes/figma.php`, seeder, schema overrides), reusing Phase 5 conflict/idempotency/provenance machinery. Agents read the Design Manifest, not raw cache. Details: ADR-0011.

Deployment (Phase 4): provider-neutral engine inside the agentcms CLI (`agentcms deploy doctor|setup|plan|staging|rollback`) with an XServer adapter.  Control Plane = XServer API (subdomain/SSL/DB/Cron/PHP via `deploy setup`, key only in env XSERVER_API_KEY, manual-panel fallback), Deployment = SSH: local build → self-contained artifact (vendor + built assets) → releases/shared/current on server, docroot = entry_stub (public_html stays a real dir). Secrets only in server-side shared/.env. Details: rules/deployment.md (ADR / deploy 詳細手順は Kit 配布パッケージの docs/ に同梱).

Boundaries detail: rules/boundaries.md. Commands: commands.md. Map: context/map.md.
