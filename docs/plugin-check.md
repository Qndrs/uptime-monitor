# WordPress Plugin Check

Run WordPress Plugin Check before tagging a public release.

## Release 3.7.1 verification

Release source: Git commit `4851e39068d079461957a558b7d1d0442e783566`, tagged and pushed as `v3.7.1`. SVN trunk was committed as `3763178`; tag `3.7.1` was copied from that exact revision and committed as `3763179` on 2026-10-24. The 16 release files match the tested ZIP byte-for-byte. Prototypes, browser fixtures, scripts and local project state are excluded.

The existing WordPress 7.1.3 testsite was upgraded from 3.6.1 to 3.7.1 using the verified ZIP. A backup of the prior plugin files was retained outside the webroot. The plugin remains active with 15 configured monitors, table-based history and the monitoring cron event scheduled. The menu exposes Overview and Settings at their existing targets. The homepage returned HTTP 200; unauthenticated status and heartbeat requests returned HTTP 401.

The official `wp plugin check qndrs-availability-heartbeat-monitor --user=1 --mode=update --format=strict-json` completed successfully with no reported errors. Local release, translation, reliability and overview-renderer checks pass. Desktop/mobile display preferences and AJAX retention were checked using the production renderer and assets with WordPress/database doubles; a separate fresh WordPress installation was not tested.

Public WordPress.org processing and the downloadable 3.7.1 ZIP are pending verification. SVN and Git publication succeeded; do not repeat these commits or recreate the tags while waiting for the directory to process them.

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
