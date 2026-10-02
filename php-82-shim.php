<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\Php82Shim\PatchManager;

use Grav\Common\Utils;
use Grav\Plugin\Php82Shim\ShimLog;
use RocketTheme\Toolbox\Event\Event;

require_once __DIR__ . '/classes/Php82Shim/polyfill.php';

/**
 * Runs the Grav 2 stack on PHP 8.2 while presenting as 8.3:
 * neutralizes version gates in core/GPM and transpiles every package
 * GPM installs (plugins, themes, core updates) down to 8.2 syntax.
 */
class Php82ShimPlugin extends Plugin
{
    /**
     * Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [['onPluginsInitialized', 0]],
            'onAdminInitialized' => [['onAdminInitialized', 0]],
            // Above admin2's SPA handler (1000) so our log page wins the route.
            'onPagesInitialized' => [['onPagesInitialized', 5000]],
            // Native admin2 integration — the real log page lives inside the
            // SPA (JWT-authenticated, no separate token/login needed).
            'onApiRegisterRoutes' => [['onApiRegisterRoutes', 0]],
            'onApiSidebarItems' => [['onApiSidebarItems', 0]],
            'onApiPluginPageInfo' => [['onApiPluginPageInfo', 0]],
        ];
    }

    /**
     * Embed the shim log directly into the plugin settings page. admin2 is a
     * JWT-driven SPA — a standalone page can never see that auth, so the log
     * lives where the operator already is: inside this blueprint's display
     * field, re-rendered on every settings-page load.
     */
    public function getBlueprint()
    {
        $bp = parent::getBlueprint();
        try {
            $entries = ShimLog::read(60);
            $lines = [];
            foreach ($entries as $e) {
                $warn = in_array($e['event'] ?? '', ['patch-fail', 'unshimmable', 'transpile-fail'], true);
                $lines[] = ($warn ? '⚠️ **' : '`')
                    . ($e['event'] ?? '?')
                    . ($warn ? '**' : '`')
                    . ' `' . ($e['ts'] ?? '') . '` — ' . str_replace('`', "'", $e['detail'] ?? '');
            }
            $md = "**Shim log** (newest first, last 60):\n\n"
                . ($lines ? '- ' . implode("\n- ", $lines) : '_empty — nothing shimmed yet_')
                . "\n\nFull page: **PHP 8.2 Shim** in the sidebar"
                . " · raw file: `user/data/php82shim/log.jsonl`";
            $bp->set('form/fields/log_info/content', $md);
        } catch (\Throwable $e) {
            // never break settings rendering over the log embed
        }
        return $bp;
    }

    /**
     * Serve the shim log viewer at {admin route}/php82shim — a plain HTML
     * page handed to the browser before admin2's SPA shell can claim it.
     */
    public function onPagesInitialized(): void
    {
        $base = '/' . trim((string)$this->config->get('plugins.admin2.route', 'admin'), '/');
        $route = $this->grav['uri']->route();
        if ($route !== $base . '/php82shim') {
            return;
        }

        // admin2 auth is JWT over an Authorization header — a browser page
        // load never carries it, so grav['user'] here is the *site* session.
        // Site-session super-admins only; no bearer tokens, no cookies —
        // the native Admin Next page is the primary UI, this is a fallback.
        $user = $this->grav['user'] ?? null;
        $authed = $user && $user->authorize('admin.super');
        if (!$authed) {
            header('HTTP/1.1 403 Forbidden');
            header('Cache-Control: no-store');
            echo '<!doctype html><meta charset="utf-8"><title>php82shim</title><body style="font:15px/1.6 system-ui;padding:40px;max-width:640px;margin:auto">'
                . '<h2>php82shim — no admin session</h2><p>admin2 uses JWT (JS-only) auth, so a plain page load has no admin identity. '
                . 'Use the <b>PHP 8.2 Shim</b> page in the admin sidebar, or log in to the site session '
                . 'with an account that has <code>admin.super</code>.</p>'
                . '<p><a href="' . htmlspecialchars($base, ENT_QUOTES) . '">&larr; back to admin</a></p></body>';
            exit;
        }

        $self = $route;

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $nonce = (string)($_POST['shim_nonce'] ?? '');
            if (Utils::verifyNonce($nonce, 'php82shim')) {
                ShimLog::clear();
            }
            $this->grav->redirect($self);
            return;
        }

        $this->serveLogPage($route, $self);
    }

    private function serveLogPage(string $route, string $self): void
    {
        $entries = ShimLog::read(300);
        $nonce = Utils::getNonce('php82shim');

        $state = [];
        $locator = $this->grav['locator'];
        $stateFile = $locator->findResource('user://data/php82shim/state.json');
        if ($stateFile && is_file($stateFile)) {
            $state = json_decode((string)file_get_contents($stateFile), true) ?: [];
        }
        $failed = $state['failed'] ?? [];

        $esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($entries as $e) {
            $warn = in_array($e['event'], ['patch-fail', 'unshimmable', 'transpile-fail'], true);
            $cls = $warn ? ' style="color:#e67e22;font-weight:600"' : '';
            $rows .= '<tr><td class="ts">' . $esc($e['ts'] ?? '') . '</td>'
                . '<td' . $cls . '>' . $esc($e['event'] ?? '') . '</td>'
                . '<td>' . $esc($e['detail'] ?? '') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="empty">Log is empty — nothing shimmed yet.</td></tr>';
        }

        $statusHtml = $failed
            ? '<div class="bad">&#9888; Failed anchors: ' . $esc(implode(', ', array_keys($failed))) . ' — upstream changed, rules need updating.</div>'
            : '<div class="ok">&#10003; All patches in place. PHP ' . PHP_VERSION . ' posing as 8.3.</div>';

        // Prepend heartbeat — proof that .user.ini auto_prepend actually ran
        // (on LSAPI/cPanel it may be ignored entirely).
        $hb = ShimLog::dataDir() . '/early-heartbeat';
        $hbAge = is_file($hb) ? time() - (int)@file_get_contents($hb) : null;
        $hbHtml = $hbAge === null
            ? '<div class="bad">&#9888; early-prepend heartbeat: never ran — .user.ini may be ignored by this SAPI (LSAPI needs lsapi_enable_user_ini). Run bin/bootstrap.php and check <code>data/php82shim/early-heartbeat</code></div>'
            : ($hbAge < 600
                ? ''
                : '<div class="bad">&#9888; early-prepend heartbeat is ' . (int)($hbAge / 60) . 'm old — .user.ini may not be live</div>');

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>php82shim log</title><style>
            body{font:14px/1.5 -apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f8fb;color:#223;padding:24px;max-width:1100px;margin:auto}
            h1{font-size:20px;margin:0 0 4px} .sub{color:#667;margin-bottom:16px}
            .ok{background:#e7f6ec;border:1px solid #b8e2c8;padding:10px 14px;border-radius:6px;margin-bottom:16px}
            .bad{background:#fdeaea;border:1px solid #f0c0c0;padding:10px 14px;border-radius:6px;margin-bottom:16px}
            table{width:100%;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
            td{padding:6px 10px;border-bottom:1px solid #eef0f3;vertical-align:top}
            td.ts{white-space:nowrap;color:#889;font-family:ui-monospace,Menlo,monospace;font-size:12px}
            td.empty{color:#889;text-align:center;padding:24px}
            form{margin:0 0 16px} button{padding:6px 14px;border:1px solid #ccd;border-radius:5px;background:#fff;cursor:pointer}
            </style></head><body>'
            . '<h1>php82shim — activity log</h1><div class="sub">What the shim caught &amp; rewrote (newest first, last 300)'
            . ' &middot; native UI: <b>PHP 8.2 Shim</b> in the admin sidebar</div>'
            . $statusHtml
            . $hbHtml
            . '<form method="post" action="' . $esc($self) . '"><input type="hidden" name="shim_nonce" value="' . $esc($nonce) . '"><button type="submit" onclick="return confirm(\'Clear the shim log?\')">Clear log</button></form>'
            . '<table>' . $rows . '</table>'
            . '</body></html>';
        exit;
    }

    /**
     * Register the shim's API routes — consumed by the admin2 plugin page
     * (admin-next/pages/php-82-shim.js) with normal JWT auth.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\Php82Shim\Api\ShimApiController::class;
        $routes->get('/php82shim/log', [$controller, 'log']);
        $routes->post('/php82shim/clear', [$controller, 'clear']);
    }

    /**
     * Sidebar entry — admin2 shows it to admin.super users.
     */
    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'php82shim-log',
            'plugin'    => 'php-82-shim',
            'label'     => 'PHP 8.2 Shim',
            'icon'      => 'fa-code',
            'route'     => '/plugin/php-82-shim',
            'priority'  => 0,
            'authorize' => 'admin.super',
        ];
        $event['items'] = $items;
    }

    /**
     * Page definition — component mode; the script at
     * admin-next/pages/php-82-shim.js is auto-discovered and served.
     */
    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'php-82-shim') {
            return;
        }
        $event['definition'] = [
            'id'        => 'php-82-shim',
            'plugin'    => 'php-82-shim',
            'title'     => 'PHP 8.2 Shim',
            'icon'      => 'fa-code',
            'page_type' => 'component',
            // Endpoint-less actions fire `page-action` on the component —
            // it handles them in admin-next/pages/php-82-shim.js.
            'actions'   => [
                ['id' => 'refresh', 'label' => 'Refresh', 'icon' => 'fa-refresh'],
                ['id' => 'clear',   'label' => 'Clear log', 'icon' => 'fa-trash'],
            ],
        ];
    }

    public function onPluginsInitialized(): void
    {
        // Native 8.3+ runtime: stand down completely — rewriting core/GPM,
        // composer.json or .user.ini now would be pure vandalism.
        if (PHP_VERSION_ID >= 80300) {
            return;
        }

        // Cheap marker checks on every request — this is what makes the shim
        // self-healing after core updates replace the patched files.
        $report = PatchManager::ensure(Grav::instance());
        if ($report['failed']) {
            $this->grav['log']->warning(
                'php82shim: failed patches: ' . implode(', ', array_keys($report['failed']))
            );
        }
    }

    public function onAdminInitialized(): void
    {
        $messages = $this->grav['messages'] ?? null;
        if (!$messages) {
            return;
        }

        $failed = PatchManager::lastFailures(Grav::instance());
        if ($failed) {
            $messages->add(
                'php82shim: some core patches did not apply (likely a newer Grav version): '
                . implode(', ', array_keys($failed)),
                'warning'
            );
        }

        // Recent unshimmable/transpile-fail hits mean installed code will
        // fatal on PHP 8.2 when reached — surface them, don't bury in the log.
        $recent = 0;
        foreach (ShimLog::read(50) as $e) {
            $age = time() - (int)strtotime($e['ts'] ?? 'now');
            if ($age <= 86400 && in_array($e['event'] ?? '', ['unshimmable', 'transpile-fail'], true)) {
                $recent++;
            }
        }
        if ($recent > 0) {
            $route = '/' . trim((string)$this->config->get('plugins.admin2.route', 'admin'), '/');
            $messages->add(
                "php82shim: {$recent} unshimmable construct(s) in the last 24h — will fatal on PHP 8.2 if executed. See {$route}/php82shim",
                'error'
            );
        }
    }
}
