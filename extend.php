<?php

/*
 * linkrobins/flarum-swoop
 *
 * Account emails for a Flarum forum, sent through Link Robins with one key.
 *
 * The forum never speaks SMTP. Each message is an ordinary HTTPS request on
 * 443, the port the site already serves on, which is the point: shared hosts
 * that block mail ports are exactly where forums die before their first member
 * arrives.
 *
 * Scope is deliberately the three account emails. Notifications and digests
 * come later, as a real mail driver. Everything here falls back to the forum's
 * own mailer if the service is unreachable, so installing this can only help.
 */

use Flarum\Extend;
use Flarum\Settings\Event\Saved;
use LinkRobins\Swoop\Listener\ExchangeKeyOnSave;
use LinkRobins\Swoop\SwoopClient;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Swoop is a choice in Admin → Email now, beside SMTP and Mailgun. Being
    // the driver is what lets it carry everything the forum sends, instead of
    // three intercepted mailers and nothing else.
    (new Extend\Mail())
        ->driver('swoop', LinkRobins\Swoop\Mail\SwoopDriver::class),

    (new Extend\Event())
        ->listen(Saved::class, ExchangeKeyOnSave::class),

    (new Extend\Routes('api'))
        // Admin-only, no recipient parameter: it can only mail the admin who
        // calls it.
        ->post('/swoop/test', 'swoop.test', LinkRobins\Swoop\Http\SendTestController::class)
        ->get('/swoop/status', 'swoop.status', LinkRobins\Swoop\Http\StatusController::class)
        ->get('/swoop/replies', 'swoop.replies', LinkRobins\Swoop\Http\RepliesController::class),

    // The address is a setting, and an admin can see and change it, because a
    // forum that is pointed at the wrong host has no other way back: the only
    // symptom is that account emails stop arriving.
    (new Extend\Settings())
        ->default('linkrobins-swoop.service-url', SwoopClient::DEFAULT_SERVICE_URL)
        ->default('linkrobins-swoop.connected', '0')
        ->serializeToForum('swoopConnected', 'linkrobins-swoop.connected', 'boolval'),
];
