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

/**
 * Emails left, asked for when the page opens.
 *
 * A balance drains, so the number is only meaningful at the moment you look at
 * it. Reading it from a stored setting would show whatever it was the last time
 * the forum happened to send — which is precisely wrong on the one screen an
 * admin opens to find out where they stand.
 */
type Stats = { sent?: number; delivered?: number; bounced?: number; complained?: number };
type Reply = { id: number; from: string; name: string; subject: string; body: string; at: string };

const meter: {
  loaded: boolean;
  balance: number | null;
  topUpUrl: string;
  stats: Stats;
  unread: number;
} = { loaded: false, balance: null, topUpUrl: '', stats: {}, unread: 0 };

const replies: { open: boolean; loading: boolean; items: Reply[] } = { open: false, loading: false, items: [] };

function loadReplies() {
  replies.loading = true;
  m.redraw();

  app
    .request<{ replies: Reply[] }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/swoop/replies',
      errorHandler: () => {},
    })
    .then((r) => {
      replies.items = r.replies || [];
      // Fetching marks them read on the service, so the badge should go too.
      meter.unread = 0;
    })
    .catch(() => {})
    .then(() => {
      replies.loading = false;
      m.redraw();
    });
}

function loadMeter() {
  app
    .request<{ connected: boolean; balance: number | null; topUpUrl?: string; stats?: Stats; unread?: number }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/swoop/status',
      errorHandler: () => {},
    })
    .then((r) => {
      meter.loaded = true;
      meter.balance = r.balance;
      meter.topUpUrl = r.topUpUrl || '';
      meter.stats = r.stats || {};
      meter.unread = r.unread || 0;
      m.redraw();
    })
    .catch(() => {
      meter.loaded = true;
      m.redraw();
    });
}

/** Low enough to act on, not so low the forum is already stuck. */
const LOW_WATER = 250;

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
        // Upgrading from the version that intercepted mailers leaves the key
        // connected and mail_driver untouched, so Swoop quietly stops carrying
        // anything while this page still says "connected". Nothing errors; it
        // just stops working. Say so.
        connected && setting('mail_driver') !== 'swoop'
          ? m(Alert, { type: 'warning', dismissible: false }, app.translator.trans('linkrobins-swoop.admin.not_selected'))
          : null,
        !connected && error
          ? m('p.helpText', app.translator.trans('linkrobins-swoop.admin.last_error', { error }))
          : null,
        m('p.helpText', app.translator.trans('linkrobins-swoop.admin.scope_note')),
      ]);
    }, 100, 'status')
    .registerSetting(() => {
      if (!meter.loaded) loadMeter();

      const left = meter.balance;
      const low = left !== null && left <= LOW_WATER;

      return m('.Form-group.Swoop-meter', [
        m('label', app.translator.trans('linkrobins-swoop.admin.balance_label')),
        left === null
          ? m('p.helpText', app.translator.trans('linkrobins-swoop.admin.balance_unknown'))
          : [
              m('.Swoop-meterBar', { role: 'img', 'aria-label': String(left) }, [
                m('.Swoop-meterFill', {
                  className: low ? 'is-low' : '',
                  style: { width: Math.max(2, Math.min(100, (left / 10000) * 100)) + '%' },
                }),
              ]),
              m(
                'p.helpText',
                low
                  ? app.translator.trans('linkrobins-swoop.admin.balance_low', { count: left })
                  : app.translator.trans('linkrobins-swoop.admin.balance_left', { count: left })
              ),
            ],
        meter.topUpUrl
          ? m(
              'a.Button',
              { href: meter.topUpUrl, target: '_blank', rel: 'noopener' },
              app.translator.trans('linkrobins-swoop.admin.top_up')
            )
          : null,
      ]);
    }, 90, 'balance')
    .registerSetting(() => {
      const st = meter.stats;
      const has = (st.delivered ?? 0) + (st.bounced ?? 0) + (st.complained ?? 0) > 0;

      return m('.Form-group.Swoop-stats', [
        m('label', app.translator.trans('linkrobins-swoop.admin.delivery_label')),
        !has
          ? m('p.helpText', app.translator.trans('linkrobins-swoop.admin.delivery_none'))
          : m('.Swoop-statRow', [
              m('.Swoop-stat', [m('b', String(st.delivered ?? 0)), m('span', app.translator.trans('linkrobins-swoop.admin.stat_delivered'))]),
              m('.Swoop-stat', { className: (st.bounced ?? 0) > 0 ? 'is-bad' : '' }, [
                m('b', String(st.bounced ?? 0)),
                m('span', app.translator.trans('linkrobins-swoop.admin.stat_bounced')),
              ]),
              m('.Swoop-stat', { className: (st.complained ?? 0) > 0 ? 'is-bad' : '' }, [
                m('b', String(st.complained ?? 0)),
                m('span', app.translator.trans('linkrobins-swoop.admin.stat_complained')),
              ]),
            ]),
      ]);
    }, 85, 'delivery')
    .registerSetting(() => {
      if (!replies.open) {
        return m('.Form-group.Swoop-replies', [
          m('label', app.translator.trans('linkrobins-swoop.admin.replies_label')),
          m(
            Button,
            {
              className: 'Button',
              onclick: () => {
                replies.open = true;
                loadReplies();
              },
            },
            meter.unread > 0
              ? app.translator.trans('linkrobins-swoop.admin.replies_unread', { count: meter.unread })
              : app.translator.trans('linkrobins-swoop.admin.replies_show')
          ),
          m('p.helpText', app.translator.trans('linkrobins-swoop.admin.replies_help')),
        ]);
      }

      return m('.Form-group.Swoop-replies', [
        m('label', app.translator.trans('linkrobins-swoop.admin.replies_label')),
        replies.loading
          ? m('p.helpText', app.translator.trans('linkrobins-swoop.admin.replies_loading'))
          : replies.items.length === 0
            ? m('p.helpText', app.translator.trans('linkrobins-swoop.admin.replies_empty'))
            : m(
                '.Swoop-replyList',
                replies.items.map((r) =>
                  m('.Swoop-reply', { key: r.id }, [
                    m('.Swoop-replyHead', [
                      m('strong', r.name || r.from),
                      m('span.Swoop-replyFrom', r.from),
                    ]),
                    r.subject ? m('.Swoop-replySubject', r.subject) : null,
                    m('.Swoop-replyBody', r.body),
                  ])
                )
              ),
      ]);
    }, 80, 'replies')
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
      ]), 75, 'test')
    .registerSetting({
      setting: 'linkrobins-swoop.key',
      label: app.translator.trans('linkrobins-swoop.admin.key_label'),
      help: app.translator.trans('linkrobins-swoop.admin.key_help'),
      // A credential, so it is not rendered in the clear on a page an admin
      // may screenshot into a support thread. This hides it from the screen,
      // not from the browser: the value still arrives in the admin payload
      // like every other setting, so it is a shoulder-surfing fix rather than
      // a secrecy one.
      type: 'password',
    }, 95)
    .registerSetting({
      setting: 'linkrobins-swoop.service-url',
      label: app.translator.trans('linkrobins-swoop.admin.service_url_label'),
      help: app.translator.trans('linkrobins-swoop.admin.service_url_help'),
      placeholder: 'https://linkrobins.com',
      type: 'string',
    }, 70);
});
