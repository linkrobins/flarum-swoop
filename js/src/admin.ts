import app from 'flarum/admin/app';
import Alert from 'flarum/common/components/Alert';

/**
 * Two fields and a banner.
 *
 * Saving either field triggers the key exchange server-side, so the banner
 * reports what the service actually said rather than leaving an admin to find
 * out when a registration email goes missing. That is also why the last error
 * is printed: "not connected" on its own does not tell anyone whether the key
 * is wrong, the quota is spent, or the forum is pointed at a host that has
 * never heard of Swoop.
 */
function setting(key: string): string {
  return (app.data.settings as Record<string, string | null>)[key] ?? '';
}

app.initializers.add('linkrobins-swoop', () => {
  app.registry
    .for('linkrobins-swoop')
    .registerSetting(() => {
      const connected = setting('linkrobins-swoop.connected') === '1';
      const error = setting('linkrobins-swoop.last-error');

      return m('.Form-group', [
        m(
          Alert,
          { type: connected ? 'success' : 'warning', dismissible: false },
          app.translator.trans(`linkrobins-swoop.admin.${connected ? 'connected' : 'not_connected'}`)
        ),
        !connected && error
          ? m('p.helpText', app.translator.trans('linkrobins-swoop.admin.last_error', { error }))
          : null,
        m('p.helpText', app.translator.trans('linkrobins-swoop.admin.scope_note')),
      ]);
    }, 100, 'status')
    .registerSetting({
      setting: 'linkrobins-swoop.key',
      label: app.translator.trans('linkrobins-swoop.admin.key_label'),
      help: app.translator.trans('linkrobins-swoop.admin.key_help'),
      type: 'string',
    }, 90)
    .registerSetting({
      setting: 'linkrobins-swoop.service-url',
      label: app.translator.trans('linkrobins-swoop.admin.service_url_label'),
      help: app.translator.trans('linkrobins-swoop.admin.service_url_help'),
      placeholder: 'https://linkrobins.com',
      type: 'string',
    }, 80);
});
