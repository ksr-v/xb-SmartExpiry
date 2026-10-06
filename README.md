# SmartExpiry

SmartExpiry adds safe calendar-month expiry controls to Xboard's admin user forms.

## Supported scenes

1. Edit User
2. Create User

Both scenes provide Permanent, 1, 3, 6, 9, and 12 month actions. Create User also reuses the Edit User date-time controls, including the calendar and the browser-native time scroller with hour, minute, and second precision.

## Installation

### Prerequisite: admin asset permissions

SmartExpiry updates Xboard's compiled admin assets during installation. Before uploading the plugin, make the admin asset directory writable by the PHP-FPM user. The following example uses the common `www:www` account; replace it if your PHP-FPM service runs as another user.

```bash
cd /path/to/xboard

sudo chown -R www:www public/assets/admin
sudo find public/assets/admin -type d -exec chmod 755 {} +
sudo find public/assets/admin -type f -exec chmod 644 {} +
```

Verify write access before installation:

```bash
sudo -u www test -w public/assets/admin/locales/en-US.js \
  && echo "Writable" \
  || echo "Not writable"
```

Do not use `chmod -R 777`. To identify the actual PHP-FPM account when it is not `www`, inspect the service processes with `ps aux | grep '[p]hp-fpm'`.

1. Download the `SmartExpiry-1.1.1-unlocked.zip` asset from the GitHub Release.
2. Upload it on Xboard's plugin management page.
3. Install and enable `smart_expiry`. Uploading this version over an installed older release runs Xboard's normal plugin update flow.

The PHP process must be able to write the active files under `public/assets/admin`. Keep a backup or filesystem snapshot before installation because this Xboard release exposes no frontend plugin hook and SmartExpiry must bridge the compiled admin bundle.

“Permanent” on Create User is only the UI wording for Xboard's original permanent action. It still writes `null`; no replacement timestamp or new persistence rule is introduced.

Every shortcut reads the live `expired_at` form value and applies one shared rule:

```text
valid(currentExpiry) && currentExpiry > now
    ? addMonths(currentExpiry, months)
    : addMonths(now, months)
```

Calendar-month addition clamps month-end dates and preserves hours, minutes, and seconds. Controls update form state only; the normal Save/Create action remains responsible for submitting the user.

The bridge resolves the active entry bundle from the admin `index.html` and does not enforce an Xboard commit or SHA-256 version lock. Exact unique structural anchors are still required before any write. It recognizes unpatched, SmartExpiry v1, SmartExpiry v2, and partially patched states by markers; partial states are rejected. Failed multi-file writes restore files already changed in that attempt.
