# S1c-3 fix — quote test needs customer_phone

The red check was a bug in ONE test file, not a config issue: my quote-snapshot test created a
Quotation without `customer_phone`, which is a NOT NULL column → Postgres rejected the insert.

Fix: the test now sets `customer_phone`. No schema change, no workflow change (the "shopbot" line in
the log is the harmless database health-check, not the cause).

## What to do
Upload this one file, replacing the existing one:
`tests/Feature/Kernel/DocumentSpecSnapshotTest.php`

Then the check should go green.
