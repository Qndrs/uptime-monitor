# History Storage

Qndrs Availability and Heartbeat Monitor 3.6.0 stores status history in an indexed WordPress database table instead of one serialized option.

## Table

The table name follows the WordPress prefix and ends in `qndrs_ahm_history`, for example `wp_qndrs_ahm_history`.

Each row stores:

- a unique event ID;
- the monitor ID;
- the UTC check timestamp;
- status and optional HTTP status code;
- optional response time;
- the sanitized status message.

Indexes support unique event imports, recent-history queries, per-monitor aggregates and retention cleanup.

## Storage modes

- `legacy`: the serialized option remains the read source; new records are shadow-written to the table when it exists.
- `table_shadow`: the table is the read source while new records are written to both stores, allowing comparison and rollback.
- `table`: only the table is used; the legacy option can be deleted.

Fresh installations use table mode. Existing installations with stored legacy history remain in legacy mode until the controlled migration is run.

## Migration

Create a database backup before migration. From the WordPress root:

```bash
wp qndrs-ahm history status
wp qndrs-ahm history migrate --batch-size=500
wp qndrs-ahm history status
```

The migration uses idempotent batched inserts and switches to `table_shadow` only when the valid legacy count exactly matches the table count. The legacy option is retained at this stage.

Compare the dashboard metrics and recent history before finalizing. Roll back while shadow mode is active with:

```bash
wp qndrs-ahm history rollback
```

After successful verification:

```bash
wp qndrs-ahm history finalize --delete-legacy
```

Finalization repeats the count comparison before enabling table-only writes and deleting the legacy option. Rollback through the command is no longer possible after deleting that option; use the database or exported history backup instead.

## Queries and retention

The dashboard loads only the five newest rows per monitor. Uptime totals and response-time statistics are calculated with indexed aggregate queries rather than by loading all history into PHP memory.

Rows older than 30 days are removed at most once per hour when a new history event is stored.
