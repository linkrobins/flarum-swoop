import app from 'flarum/admin/app';

/**
 * One field: the mail key. Saving it triggers the exchange server-side, so the
 * banner above tells the admin whether it actually worked rather than leaving
 * them to find out when a registration email goes missing.
 */
app.initializers.add('linkrobins-swoop', () => {
  app.registry
    .for('linkrobins-swoop')
    .registerSetting({
      setting: 'linkrobins-swoop.key',
      label: app.translator.trans('linkrobins-swoop.admin.key_label'),
      help: app.translator.trans('linkrobins-swoop.admin.key_help'),
      type: 'string',
    });
});
