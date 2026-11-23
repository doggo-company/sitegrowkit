# Commands (agent view)

## CLI commands (run inside the project)

| Command | What |
| --- | --- |
| `agentcms --version` | Installed Agent CMS Kit version |
| `agentcms setup` | Open the browser Setup Wizard (primary interface for non-terminal users): environment check → project input → create + dev → ready. Serves on 127.0.0.1 only, ephemeral port, one-time session token; bundled assets only |
| `agentcms create <dir>` | New project from the bundled template (also generates admin credential, `.agentcms/` context, CLAUDE.md/AGENTS.md, git init) |
| `agentcms doctor` | Environment diagnosis (OS, docker, node, git, ports, project state). PASS/WARN/FAIL/NOT REQUIRED with cause + fix |
| `agentcms dev` | Start runtime: docker compose up + deps install + frontend build + migrate/seed + health check. URLs are printed on success |
| `agentcms stop` | Stop containers |
| `agentcms update check --manifest <json> --signature <sig> --public-key <trusted-pem> [--archive <tgz>] [--project <dir>]` | Verify Ed25519 manifest and optional archive; compare project versions and save a private result for admin/readiness. Never applies updates. Exit 0 permits diff/migration review, 1 means verification failure, 2 means blocked or migration review required. Trust key must arrive separately; no automatic latest-version feed. |
| `agentcms sync-agents` | Regenerate CLAUDE.md / AGENTS.md from .agentcms/ — never edit those by hand |
| `agentcms mcp [--root <dir>]` | AI agent bridge: stdio MCP server exposing the Application Service allowlist (15 operations). stdout is protocol-only; diagnostics go to stderr. Purchasers generate the client config from the Setup Wizard instead of running this by hand (ADR-0012) |
| `agentcms test` | Pest inside php container |

Running Pest directly (`vendor/bin/pest`) uses the `memory_limit` set in `phpunit.xml` (1G), so it matches what `agentcms verify` runs; the PHP default of 128M is not enough to boot the app.

| `agentcms verify` | Full quality gate: pint, phpstan, vue-tsc, eslint, pest, **vitest (JS unit)**, taxonomy check, vite build, secret scan, audits. PHP gates run in Docker when containers are up; otherwise a compatible host runtime (php version satisfying composer.json + installed vendor/) is used and reported. Neither available → FAIL with what/why/fix |
| `agentcms verify staging` | HTTP checks against deployed staging: health/PWA/security headers/noindex/exposure (no real mail/push) |
| `agentcms template inspect <path>` | Deterministic HTML template analysis → `.agentcms/manifest/template.json` (Manifest v2: fingerprint/CSS/JS/metadata/classification). `<path>` = dir or single HTML file. `--entry <file>` picks the home page; empty HTML never becomes a page candidate |
| `agentcms template plan` | Manifest → conversion plan (`template-plan.json` + human-readable `docs/template-import-plan.md`). Every decision carries reason + CONFIDENT/REVIEW/UNKNOWN |
| `agentcms template apply` | Apply approved plan to the project. Conflicts STOP (`--force` backs up originals to `.agentcms/manifest/backup/`), re-apply is a no-op, stale plans (source changed) are rejected. In standalone (preserve) mode each page written to `resources/views/public|pages/` whose body splits into blocks (body-level `<section>`s, or the children of a single `<main>`) may be raised as a fixed page whose **text, image src/alt and link href/text** are editable in the admin (`agentcms:import-page` in one transaction; the entry page also gets `front_page_id`): the original frame becomes `layouts/preserved/<slug>.blade.php`, bound to the created page's id in `imported_page_layouts` (never by slug or file existence), each block becomes an `imported_<slug>_<block>` component (self-contained Blade blocks are kept verbatim and labelled not editable; url props use the kit `url` validation), and `routes/imported.php` routes through `ImportedPageController`: no owner (`imported_page_layouts.owned_view`) → static Blade; owner published → the page; owner renamed → 301 to the new slug; owner unpublished/scheduled/trashed → 404 (never the static copy). Duplicates inherit the layout but never the URL ownership. If any block of a page cannot be converted that page is not raised (nothing disappears, nothing becomes editable). Module list/detail pages and layout-mode projects stay static. Existing pages are never overwritten; a same-slug page this import did not create is a failure every run. Re-importing another template asks `agentcms:imported-usage` first (any page version counts) and retains components/layouts still used by pages; if pages use them and the plan would change or remove `public/imported/` assets the whole apply is refused before writing (stops before writing if it cannot ask). Duplicating an imported page copies the layout binding (`PageDuplicated` event). `--dry-run` prints `would create page` / `would retain` and writes nothing |
| `agentcms template verify` | Import verification: manifest/plan consistency, fingerprint, generated-file hashes, schema, assets, HTTP page/link/asset/form checks. `--offline` skips the HTTP part — its target is `APP_URL` (default `http://localhost:8180`) and it checks **whatever is listening there**, so a commit-time gate must not depend on it |
| `agentcms figma connect <url>` | Parse Figma URL → `.agentcms/figma/project.json` (gitignored, never holds a token). No API call. Auth: env `FIGMA_ACCESS_TOKEN` only (no `--token` flag — shell history) |
| `agentcms figma inspect` | Metadata First (Tier-3 meta → version check) → node-targeted fetch → Raw Cache (`.agentcms/cache/figma/`, gitignored) → deterministic Design Manifest `.agentcms/manifest/figma.json`. Version unchanged ⇒ zero Tier-1 calls. `--renders` saves visual baselines; `--variables` opts into the Variables API (Advanced; default = NOT REQUESTED, no call). Offline/CI: env `AGENTCMS_FIGMA_FIXTURE=<dir>` |
| `agentcms figma diff` | Semantic diff (ADDED/CHANGED/REMOVED per screen/component/token/asset/cms) vs stored manifest. Never applies anything |
| `agentcms figma plan` | Manifest → implementation plan (`figma-plan.json` + `docs/figma-implementation-plan.md`) with confidence + reason; updates Component Registry `.agentcms/components/registry.json` (proposed) |
| `agentcms figma apply` | Apply approved plan to the LOCAL project only (never writes to Figma). Stale plan (fingerprint or Figma version drift) rejected, conflicts STOP (`--force` = backup to `.agentcms/manifest/backup-figma/`), re-apply no-op, provenance in `figma-apply.json`, registry → mapped |
| `agentcms figma interpret` | Record approved AI interpretation of generated files into provenance (generated → interpreted). Hash protection stays strict: verify passes only while current hashes equal the recorded interpreted hashes; later edits or stale records FAIL. `--status` = report matched/interpreted/broken without recording |
| `agentcms figma verify` | Figma import verification: manifest fingerprint recompute / plan / provenance hashes (generated OR recorded interpreted hash) / registry targets / secret scan / client-data gitignore / HTTP (only when APP_URL set) |
| `agentcms deploy doctor` | Diagnose XServer target over SSH (+ XServer API if XSERVER_API_KEY set: SSL/Cron state). Read-only, PASS/WARN/FAIL + fix |
| `agentcms deploy setup` | Provision server prerequisites via XServer API: subdomain/SSL/DB/user/grant/shared-.env/Cron/PHP. Create-only, idempotent, confirms before writes. Requires env XSERVER_API_KEY; without it prints manual fallback |
| `agentcms deploy plan` | Show what a deploy would do (artifact/migrations/symlinks/cron/backup). No changes, no secrets. `--offline` = no SSH |
| `agentcms deploy staging` | Deploy: local build → backup → upload → migrate → switch → health check (auto-rollback on failure). `--dry-run` = build artifact only |
| `agentcms deploy rollback` | Switch `current` back to previous release (DB is never auto-restored) |

## Inside the project (docker)

| Command | What |
| --- | --- |
| `docker compose up -d` | php + mysql + mailpit |
| `docker compose exec app php artisan <...>` | Artisan |
| `docker compose exec app php artisan test` | Pest |
| `docker compose exec app ./vendor/bin/pint` | Format |
| `docker compose exec app ./vendor/bin/phpstan` | Static analysis |
| `pnpm dev` / `pnpm build` / `pnpm typecheck` / `pnpm lint` | Vite / vue-tsc / eslint (host node) |
| `pnpm e2e:visual` | Visual regression: imported HTML vs rendered site (host, against running app). Thresholds: `.agentcms/visual-thresholds.json`, default 2% — see `visual-thresholds.example.json` |
| `php artisan agentcms:backup-auto` | Take a backup, copy it to the offsite disk (size + sha256 checked), prune old generations. Scheduled daily only when `AGENTCMS_BACKUP_SCHEDULE=HH:MM` is set. Exit 1 when offsite is configured and the copy fails; local backup is kept |
| `php artisan agentcms:backup-offsite [<id>] [--list]` | Copy a local backup to the offsite disk (`AGENTCMS_BACKUP_OFFSITE_DISK`: a `config/filesystems.php` disk — another directory or S3-compatible). `--list` shows schedule/offsite settings and remote generations; exit 1 if unset or unreadable |
| `php artisan agentcms:backup-restore <id>\|--latest [--from-offsite] [--force]` | Restore DB + media from a backup on the server CLI. `--from-offsite` downloads it first and refuses a sha256 mismatch. Same verification, pre-restore backup and session cut as the admin restore; asks for confirmation unless `--force` |
| `php artisan agentcms:maintenance` | Check installed PHP dependencies and HTTPS certificate; save private results for admin/readiness (daily in production/staging with schedule:run). No code updates. |
| `pnpm lighthouse` | Lighthouse CI (host, against docker app) |

Admin user (local): seeded via `php artisan db:seed` — no public registration. Credentials are project-local (`.agentcms/local/admin-credentials.json`, gitignored). Never print or commit the password; tell the human where the file is instead.
