# Repository Guidelines

## Project Structure & Module Organization
This is a WordPress plugin with a flat, class-based structure:
- `motorsport-club.php`: plugin bootstrap, constants, class loading, activation/deactivation hooks.
- `includes/`: PHP modules by domain (for example `class-registration.php`, `class-results.php`, `class-security.php`).
- `includes/lib/`: bundled helper libraries (for example `class-msc-pdf.php`).
- `assets/js/frontend.js`: frontend account, garage, and registration interactions.
- `assets/css/frontend.css`: all plugin UI styling.
- `templates/`: currently empty; reserve for future view templates.

## Build, Test, and Development Commands
No Node/Composer build pipeline is configured in this repository.
- `php -l motorsport-club.php` and `php -l includes/class-registration.php`: quick syntax checks.
- `find includes -name "*.php" -print0 | xargs -0 -n1 php -l`: lint all PHP modules.
- `zip -r motorsport-club-manager.zip . -x ".git/*" ".claude/*"`: create a distributable plugin zip.

Run and verify in a local WordPress install:
1. Copy folder into `wp-content/plugins/`.
2. Activate plugin in WP admin.
3. Test key flows: event creation, paid registration (PoP required), indemnity PDF/email, results entry.

## Coding Style & Naming Conventions
- Follow existing WordPress style: tabs for indentation in PHP, escaped output (`esc_html`, `esc_attr`, `wp_kses_post`), sanitized input, nonce and capability checks.
- Class names use `MSC_*` (for example `MSC_Registration`).
- Hooks, AJAX actions, meta keys, and options use `msc_` prefix.
- Keep frontend selectors/classes prefixed with `msc-`.

## Testing Guidelines
There is no automated test suite yet. Use manual regression testing for:
- registration window/capacity rules,
- minor vs adult signature handling,
- paid event PoP upload validation,
- role-gated dashboard access (`administrator`, `msc_event_creator`),
- event close/reopen and results ordering.

## Commit & Pull Request Guidelines
Recent commits use concise, imperative subjects, often with version context (for example `Bump version to 0.5.9.5: ...`).
- Commit format: `Area: concise change` or `Bump version to X.Y.Z: concise summary`.
- Keep commits scoped to one logical change.
- PRs should include: what changed, why, affected user flows, manual test steps, and screenshots/GIFs for UI updates.
