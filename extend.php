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
use LinkRobins\Swoop\SwoopServiceProvider;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Core resolves both mailers from the container by class name when it wires
    // them as event listeners, so binding subclasses is enough to take over.
    (new Extend\ServiceProvider())
        ->register(SwoopServiceProvider::class),

    (new Extend\Event())
        ->listen(Saved::class, ExchangeKeyOnSave::class),

    // Core pushes its reset job with `new`, so the controller that pushes it is
    // the only seam.
    (new Extend\Routes('api'))
        ->remove('forgot')
        ->post('/forgot', 'forgot', LinkRobins\Swoop\Http\ForgotPasswordController::class),

    (new Extend\Settings())
        ->default('linkrobins-swoop.service-url', 'https://linkrobins.com')
        ->default('linkrobins-swoop.connected', '0')
        ->serializeToForum('swoopConnected', 'linkrobins-swoop.connected', 'boolval'),
];
