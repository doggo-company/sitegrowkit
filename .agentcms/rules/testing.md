# Testing Rules (MUST follow)

Completion = machine verification, not claims. The gate is `agentcms verify`:

1. Pint (format check)
2. PHPStan / Larastan
3. vue-tsc (TS typecheck)
4. ESLint
5. Pest (unit + feature; sqlite :memory: — real MySQL path is covered by E2E)
6. `vite build` (production build)
7. Secret scan (no .env/keys tracked by git)
8. `composer audit` + `pnpm audit --prod`

E2E layer (run before calling a phase done):

- Playwright: admin login (incl. invalid login rate-limit path), News/Works CRUD, public 5 pages render, contact form happy path + validation errors (Turnstile test keys)
- Screenshots: desktop (1280×800) and mobile (375×812) per public page → `tests/e2e/__screenshots__/` (visual regression baseline)
- Broken-link check over the public site
- Template import parity: `template-import.spec` + `template-visual.spec` run FIRST via dedicated Playwright projects; `global-setup.ts` resets the DB (migrate:fresh + AdminUser + TemplateContent seeds) every run — admin CRUD tests pollute public content, so never move template specs after them
- Lighthouse CI on public pages; performance budget in `lighthouserc` — target 100/100/100/100 desktop+mobile, no score-gaming hacks

Rules:

- New module ⇒ its own Pest tests in `modules/<name>/tests/` (feature-level: schema loads, CRUD, policies).
- Contact pipeline has a test proving submission survives mail failure.
- Never weaken a test to make it pass; fix the cause or record the failure in the benchmark journal.
- Repeating the same error 3+ times ⇒ stop, investigate root cause, log a journal entry.
- A fix commit MUST name **which test walks the situation this fix repairs**. If no test walks it, the fix ships unguarded — that was the single root cause behind 9 findings in one day (2026-08-22): the implementation was right every time, but the test suite only ever built worlds the fix could not reach (buyer-shaped type sets, preserve mode, another import path, after a type is created, after it is published, a commit-time record in the old format). Ask **"which world can this test never build?"** before writing it, not after review.

Measuring traps (each one cost a false "it is guarded" claim):

- Scoped bindings survive a request inside one test. After changing schemas / field groups mid-test, call `app()->forgetScopedInstances()`; if you forget one binding explicitly, pass the **bound key** (the interface), not the implementation class.
- Blade compiles to `storage/framework/views`. When you mutate a template to check a test catches it, run `php artisan view:clear` **before and after** — the compiled copy can outlive the source you restored.
- Generated markup that reads fine as a string can reparse into a different tree in a browser. Two measured cases with `node-html-parser` (7.1.0):
  - **DOM insertion is not re-parsed.** Inserting a `<div>` inside a `<p>` keeps it there (`parentNode` stays `P`) and serializes as `<p>…<div></div><button></button></p>`; a browser reparsing that string closes the `<p>` first, so the button lands outside and loses the paragraph's styling.
  - **Parsing differs per tag.** Parsing `<p>a<X>b</X></p>`, it closes the `<p>` before `div` and `p` (same as a browser) but keeps `ul` / `section` / `h2` / `table` / `blockquote` inside it (a browser closes the `<p>` before all of them).
  Verify layout-affecting output in a real browser, not in the parser's tree.
- Asserting `str_contains(...)` for text that also appears elsewhere on the page proves nothing about the line you care about. Anchor it (`str_ends_with`, a selector, a position).
