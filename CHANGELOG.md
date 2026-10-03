# v1.0.1
##  2026-10-03

1. [](#bugfix)
    * `Transpiler::scan()` no longer false-flags `ReflectionParameter->getType()`/`hasType()` — receiver-aware detection (stock `ReflectionCaster.php` blocked `gpm self-upgrade` under `block_unshimmable`)
    * `bin/bootstrap.php` refuses to run on PHP <8.2 or >=8.3 (was: warned and patched anyway)
    * `PatchManager::{bootstrap,ensure,patchTree}` refuse non-8.2 runtimes, and `ensure()` no longer arms `.user.ini` while anchors are failing — same fail-closed gate as bootstrap
    * Typed constants now downgrade correctly after `"{$x}"`/`"${x}"`/heredoc interpolation, inside `new class(fn() => …)`, and `new #[Attr] readonly class` demotes keeping attributes
    * `eval()` removed from version-guard classification (`match`)
2. [](#improved)
    * `php` dependency widened to `>=8.2` (an upper bound would block GPM updates/uninstall after the host moves to 8.4+)
    * `.gitattributes` excludes `tests/` and `assets/` from dist archives
    * README: states the manual bootstrap step and "don't report to Grav/plugin authors" policy
    * LICENSE holder matches the blueprint author name

# v1.0.0
##  2026-10-03

1. [](#new)
    * Initial release: PHP 8.2 compatibility shim for Grav 2
    * Core/GPM version-gate patching, token-based PHP 8.3→8.2 transpiler,
      polyfills, self-healing via auto_prepend_file, Admin Next log page
      (EN/RU), fail-closed anchor handling, GPM install/uninstall hooks
