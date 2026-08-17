# WordPress Plugin Check

Run WordPress Plugin Check before tagging a public release.

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
