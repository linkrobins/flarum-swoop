# Swoop

Account emails for your Flarum forum, sent through [Link Robins](https://linkrobins.com) with one key.

The first wall a new forum owner hits is email. Shared hosts block SMTP ports, Gmail throttles, SPF and DKIM are a mystery, and the forum looks broken before it has a member. Swoop takes that away: paste one key and your forum's account emails go out over HTTPS on port 443, the same port your site already serves on. No SMTP, no DNS records, no third-party account.

## What it carries

The three emails a forum cannot function without:

- Confirm your account
- Reset your password
- Confirm a new email address

Notifications and digests still use your own mail settings. They come later.

## Install

```sh
composer require linkrobins/flarum-swoop
```

Then paste your key under **Admin → Swoop**. Saving checks it immediately, so you know it works before a new member finds out for you.

## If the service is unreachable

Every path falls back to your forum's own mail settings. Installing this can only help; it cannot leave you worse off than you were.

## Why the API looks so narrow

The extension can ask for a *type* and a link. It cannot supply a subject, a body or a sender, because those are the fields that would turn a leaked key into a spam relay. The message is rendered on our side, and any link that does not point back at your forum is refused.

## License

MIT
