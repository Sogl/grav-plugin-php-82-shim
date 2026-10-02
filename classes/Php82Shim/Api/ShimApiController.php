<?php

declare(strict_types=1);

namespace Grav\Plugin\Php82Shim\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\TranslatesAdminLabels;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Php82Shim\ShimLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin2 API endpoints for the shim's activity log. Authentication is the
 * standard api.access gate (JWT/API-key via AuthMiddleware); both endpoints
 * additionally require admin.super — this is an operator-level tool.
 */
class ShimApiController extends AbstractApiController
{
    use TranslatesAdminLabels;

    /** UI string keys shipped to the Admin Next page component. */
    private const UI_KEYS = [
        'LOG_TITLE', 'LOG_SUBTITLE', 'SEARCH_PLACEHOLDER', 'ALL', 'PROBLEMS',
        'PATCHES_LABEL', 'HEARTBEAT_LABEL', 'PHP_LABEL',
        'PATCHES_OK', 'PATCHES_FAIL', 'HEARTBEAT_NEVER', 'HEARTBEAT_STALE',
        'ENTRIES', 'OF', 'FAILED_ANCHORS', 'HEARTBEAT_ALERT', 'REFRESH_FAILED',
        'LOG_EMPTY', 'NO_ENTRIES', 'MATCHING', 'WITH_PROBLEMS', 'OF_TYPE',
        'CLEAR_TITLE', 'CLEAR_MSG', 'CLEAR_CONFIRM', 'CLEARED', 'NO_DIALOGS',
        'REFRESHING', 'REQUEST_FAILED', 'NATIVE_NOTICE',
        'EVENT_PATCH', 'EVENT_PATCH_FAIL', 'EVENT_TRANSPILE', 'EVENT_TRANSPILE_FAIL',
        'EVENT_UNSHIMMABLE', 'EVENT_GUARDED', 'EVENT_INERT', 'EVENT_PACKAGE',
        'EVENT_HEAL', 'EVENT_BOOTSTRAP', 'EVENT_HEARTBEAT', 'EVENT_USER_INI',
    ];

    /**
     * GET /php82shim/log — newest-first entries plus health status
     * (failed patch anchors, early-prepend heartbeat, PHP version).
     */
    public function log(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'admin.super');

        $stateFile = ShimLog::dataDir() . '/state.json';
        $state = is_file($stateFile)
            ? (json_decode((string)file_get_contents($stateFile), true) ?: [])
            : [];

        $hb = ShimLog::dataDir() . '/early-heartbeat';
        $heartbeatAge = is_file($hb) ? time() - (int)@file_get_contents($hb) : null;

        // UI strings translated to the calling admin user's language —
        // shadow-DOM components have no client-side t(), so the page
        // renders this map with English fallbacks baked into the JS.
        // NOTE: admin2 keeps its locale in the `adminLanguage` *preference*,
        // not the legacy `user.language` field — primeAdminLanguages()
        // resolves it via PreferencesResolver (with en tail-fallback).
        $languages = $this->primeAdminLanguages($request);
        $strings = [];
        foreach (self::UI_KEYS as $key) {
            $keyFull = 'PLUGIN_PHP82SHIM.' . $key;
            $t = $this->grav['language']->translate($keyFull, $languages);
            $strings[$key] = is_string($t) && $t !== '' ? $t : $key;
        }

        return ApiResponse::create([
            'entries'       => ShimLog::read(300),
            'failed'        => array_keys($state['failed'] ?? []),
            'heartbeat_age' => $heartbeatAge,
            'php_version'   => PHP_VERSION,
            'native'        => PHP_VERSION_ID >= 80300,
            'log_path'      => 'user/data/php82shim/log.jsonl',
            'strings'       => $strings,
        ]);
    }

    /**
     * POST /php82shim/clear — truncate the JSONL log.
     */
    public function clear(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'admin.super');
        ShimLog::clear();

        return ApiResponse::create(['cleared' => true]);
    }
}
