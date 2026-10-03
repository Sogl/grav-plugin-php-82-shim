English | [Русский](README_RU.md)

# PHP 8.2 Shim Plugin

Runs a Grav 2 site on PHP 8.2 shared hosts that cannot offer PHP 8.3. The plugin relaxes the version gates in Grav core and transpiles PHP 8.3-only syntax in installed packages down to 8.2, with a fail-closed update path and a visible activity log.

> **⚠️ Unofficial compatibility layer.** This plugin is not affiliated with
> or endorsed by the Grav team — it rewrites core files to run a site on a
> PHP version upstream does not support. If something breaks while it is
> active, do **not** report it to Grav or to other plugin/theme authors:
> their code targets PHP 8.3+, and the shim is not their responsibility.
> File issues on this plugin's tracker instead.

![PHP 8.2 Shim — activity log in Grav Admin](assets/interface.jpg)

## What it does

- **Relaxes version gates**:
  - `index.php` / `system/` minimum-version checks, Composer's `platform_check.php`, and the root `composer.json` platform requirement.
  - GPM `Installer`/`Upgrader` interceptions so updates go through the shim before publish.
- **Transpiles packages** on install:
  - Typed class constants, `new readonly class`, `Class::{$expr}` and other 8.3-only syntax are rewritten to 8.2 equivalents before the package goes live.
  - Works for plugins, themes, and Grav core update payloads.
- **Polyfills** missing 8.3 functions (`json_validate`, `mb_str_pad`, `str_increment`/`str_decrement`, `stream_context_set_options`, and more).
- **Self-heals** after core updates:
  - An `auto_prepend_file` bootstrap re-applies patches on the next request if an update replaced patched files.
  - A heartbeat marker proves the prepend actually runs on your SAPI (LiteSpeed/cPanel hosts can silently ignore `.user.ini`).
- **Fails closed**:
  - If a patch anchor drifts after an upstream Grav change, updates are aborted until the shim is updated — the Admin page tells you exactly which files failed.
  - Packages containing constructs that cannot be shimmed are refused when `block_unshimmable` is enabled (default).
- **Admin log page**:
  - Sidebar → **PHP 8.2 Shim**: searchable activity log, patch health, heartbeat status.
  - English and Russian UI.

## Requirements

- Grav **2.x** (declared in `blueprints.yaml` compatibility metadata).
- PHP **8.2.x** — the real runtime. On native PHP 8.3+ the shim stands down on its own and can be disabled.
- A PHP SAPI that honours `.user.ini` (CGI/FastCGI; LiteSpeed needs `lsapi_enable_user_ini`). Apache `mod_php` ignores `.user.ini` — the log page heartbeat will tell you if the prepend never ran.

Successfully tested with Grav **2.2.x** on PHP **8.2.30**.

## Installation

This plugin **rewrites Grav core files in place** — back up the site first, or try it on a staging copy.

> **Important:** this is **not a one-click install** — wherever the plugin
> files come from (GPM or a manual copy), you must run the bootstrap by hand
> on the PHP 8.2 host (step 2). The plugin does nothing until core is patched
> and the prepend is armed.

1. Put the plugin into `user/plugins/php-82-shim`.
2. From the Grav root, run the bootstrap once:

   ```bash
   php user/plugins/php-82-shim/bin/bootstrap.php
   ```

   It scans and transpiles the existing tree, writes the managed
   `.user.ini` block, and verifies the prepend stub.

3. Open **Admin → PHP 8.2 Shim** — `Patches: all in place` plus a fresh heartbeat mean it is live. PHP re-reads `.user.ini` at most every `user_ini.cache_ttl` seconds (default 300s), so give it a few minutes on a busy host.

## Good to know

- `bin/gpm` and cron run under CLI, where `auto_prepend_file` does not apply. The persistent file patches still protect updates, but heal-on-request is a web-SAPI mechanism — re-run `bin/bootstrap.php` if in doubt.
- The transpiler is **best-effort, not a compatibility proof**. Known divergences: typed class constants lose runtime type checks, `new readonly class` loses readonly semantics, `__clone` re-initializing readonly properties still fatals on 8.2, and `catch` blocks for `DateMalformed*` exceptions never fire (PHP 8.2's date extension does not throw them).
- **Uninstalling**: `bin/gpm uninstall` and `bin/uninstall.php` both refuse to run while the runtime is still PHP 8.2 — removing the shim would leave patched gates accepting 8.3 packages nothing can execute. On PHP 8.3+ the cleanup runs: the `.user.ini` block, the composer hook and the plugin's state are removed (the prepend stub file is kept — PHP's ini cache may still reference it for a few minutes).
- The recommended long-term fix is still a host with PHP 8.3+; this plugin exists for the "a pile of projects on one 8.2-only shared account" scenario.

## Credits

`str_increment`/`str_decrement`, `mb_str_pad` and `json_validate` in
`classes/Php82Shim/polyfill.php` are ported verbatim from
[symfony/polyfill-php83](https://github.com/symfony/polyfill) (MIT,
© Fabien Potencier / Symfony contributors).
