# BuilderCMS Sync Posts

Pulls unit inventory from the BuilderCMS `UnitDataFeed` into a post type (e.g. `residence`) and its status taxonomy. Settings live under **Settings > BuilderCMS Sync**.

## Multisite

- Every setting (Community ID, username, password, field map, schedule) is stored per site, so each community site syncs with its own credentials. **Activate the plugin per site**, not network-wide.
- WP-Cron only runs when a site gets traffic. For scheduled syncs, add a real system cron per site, e.g. every 15 minutes:
  `wp --url=https://example.com/amara/ cron event run --due-now`
- Deactivating the plugin clears that site's scheduled sync and log (every site's, when network-deactivated).

## Security

- The password is encrypted in the database with a key derived from the site's `AUTH_KEY`/`AUTH_SALT`. Passwords saved by 1.0.x are encrypted the first time an admin page loads. If the salts change (for example when the database moves to a host with a different `wp-config.php`), an admin notice asks you to enter the password again.
- The password is never printed back into the settings form; leave the field blank to keep the saved one.
- "Sync Now" requires the `manage_options` capability and a nonce. There is no logged-out AJAX endpoint.
- The sync log is stored per site in the `bcms_sync_log` option (capped at 256 KB, not autoloaded) and shown on the settings page. It is no longer written to a file in the plugin folder, where the web server could serve it.

## Changelog

### 1.1.0
- Fix: activation/deactivation hooks were registered on `plugins_loaded`, too late to run; deactivating left the cron job scheduled.
- Fix: a feed row without a status cleared the unit's status (PHP 8 `strpos` with an empty needle). Rows without a status now leave it unchanged; status matching uses `has_term()` (name or slug) instead of a substring search.
- Fix: one row missing the ID field aborted the whole sync; it is now skipped and logged. The sync fails only if every row is missing it.
- Fix: changing the schedule didn't reschedule an existing cron event.
- Fix: status changes on existing posts weren't included in the log (`array_merge` result discarded).
- Security: capability check and nonce before anything runs; removed the `nopriv` AJAX action; password encrypted at rest and not echoed; settings output escaped; log moved out of the web root.
- The request sent to BuilderCMS is unchanged.
