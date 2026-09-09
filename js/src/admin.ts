import app from 'flarum/admin/app';
import Alert from 'flarum/common/components/Alert';
import Button from 'flarum/common/components/Button';

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

/**
 * "Is it working?" answered without registering a fake account.
 *
 * Deliberately not disabled while disconnected: an admin who presses it then
 * gets told why, which is more use than a button that quietly does nothing.
 */
const test: { busy: boolean; ok: boolean | null; message: string } = { busy: false, ok: null, message: '' };

function sendTest() {
  test.busy = true;
  test.ok = null;
  test.message = '';
  m.redraw();

  app
    .request<{ sent: boolean; to?: string; error?: string }>({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/swoop/test',
      errorHandler: () => {},
    })
    .then((r) => {
      test.ok = true;
      test.message = app.translator.trans('linkrobins-swoop.admin.test_sent', { email: r.to }) as string;
    })
    .catch((e) => {
      test.ok = false;
      const reason = e?.response?.error || e?.message || '';
      test.message = app.translator.trans('linkrobins-swoop.admin.test_failed', { error: reason }) as string;
    })
    .then(() => {
      test.busy = false;
      m.redraw();
    });
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
    .registerSetting(() =>
      m('.Form-group', [
        m(
          Button,
          { className: 'Button', loading: test.busy, disabled: test.busy, onclick: sendTest },
          app.translator.trans(`linkrobins-swoop.admin.${test.busy ? 'test_sending' : 'test_button'}`)
        ),
        test.ok !== null
          ? m(Alert, { type: test.ok ? 'success' : 'error', dismissible: false }, test.message)
          : null,
        m('p.helpText', app.translator.trans('linkrobins-swoop.admin.test_help')),
      ]), 70, 'test')
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
