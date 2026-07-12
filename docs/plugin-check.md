# WordPress Plugin Check

Run WordPress Plugin Check before tagging a public release.

Current testsite context:

- URL: `https://qndrs.training/simpleuptimemonitor/`
- Deployment path: `/home/qndrs/public_html/simpleuptimemonitor/wp-content/plugins/uptime-monitor`
- SSH authentication works through SFTP.
- Shell access is disabled for the current account, so `wp-cli` based checks cannot run remotely.
- The Plugin Check plugin is installed on the testsite, but running it requires WordPress admin access.

Manual check route:

1. Deploy the current plugin build to the testsite.
2. Log in to WordPress admin.
3. Open Plugin Check.
4. Run checks for **Qndrs Availability and Heartbeat Monitor**.
5. Prioritize security, escaping, internationalization, and plugin metadata findings.
6. Record blocking findings in `TODO.md` or a GitHub issue.

Local fallback:

Run the repository checks:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/check-release.ps1
```

These local checks do not replace WordPress Plugin Check. They only verify syntax, version consistency, and stabilization invariants that can be checked without a full WordPress runtime.
