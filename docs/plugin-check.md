# WordPress Plugin Check

Run WordPress Plugin Check before tagging a public release.

## Local test package 3.7.1

Version 3.7.1 adds the explicit Overview submenu to the display preferences introduced in the local 3.7.0 test package. The distributable is `dist/qndrs-availability-heartbeat-monitor-3.7.1.zip`, with the existing plugin folder and main file for replacement uploads or fresh installation.

Local release, translation, reliability and overview-renderer checks pass, including the menu labels and existing overview target. The display controls were also checked in the browser using the production renderer and assets with WordPress/database doubles. Full WordPress installation/upgrade and the official Plugin Check for this version remain unverified; the 3.6.1 report below does not cover 3.7.1. This package has not been published to WordPress.org.

## Release 3.6.1 verification

The Plugin Check report from 2026-08-26 identified an outdated `Tested up to` value and static-analysis findings around the custom history table. The 3.6.1 candidate updates the metadata to WordPress 7.1 and adds narrowly scoped PHPCS explanations for intentional direct custom-table access. Dynamic values remain prepared; table names and placeholder lists are constructed only from trusted plugin code and `$wpdb->prefix`.

The release candidate was installed on the WordPress 7.1 testsite on 2026-08-26. Local release checks passed and the official `wp plugin check qndrs-availability-heartbeat-monitor --mode=update --format=strict-json` completed without errors or warnings. The plugin remained active, table-based history storage was healthy, the monitoring cron event remained scheduled, the homepage returned HTTP 200, and unauthenticated status and heartbeat requests returned HTTP 401 as intended.

Dutch runtime translation status checked on 2026-08-26:

- Development: 216/216 current (100%), no waiting, fuzzy or warnings.
- Stable: 216/216 current (100%), no waiting, fuzzy or warnings.
- Official `nl_NL` language pack: available for 3.6.0, generated 2026-08-21.
- Development Readme and Stable Readme: 0/67 translated (0%).

Current testsite context:

- URL: `https://qndrs.training/simpleuptimemonitor/`
- Deployment path: `/home/qndrs/public_html/simpleuptimemonitor/wp-content/plugins/qndrs-availability-heartbeat-monitor`
- SSH and SFTP authentication work for account `qndrs` with the locally configured Zadkine key.
- Shell access and WP-CLI are available on the testsite.
- The Plugin Check plugin is installed on the testsite, but running it requires WordPress admin access.

Manual check route:

1. Deploy the current plugin build to the testsite.
2. Verify the deployed version with `wp plugin status qndrs-availability-heartbeat-monitor` from the testsite WordPress root.
3. Log in to WordPress admin.
4. Open Plugin Check.
5. Run checks for **Qndrs Availability and Heartbeat Monitor**.
6. Prioritize security, escaping, internationalization, and plugin metadata findings.
7. Record blocking findings in `TODO.md` or a GitHub issue.

Local fallback:

Run the repository checks:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1
```

These local checks do not replace WordPress Plugin Check. They only verify syntax, version consistency, and stabilization invariants that can be checked without a full WordPress runtime.

For option-to-table history migrations and rollback, see `docs/history-storage.md`.
