# Fix — Project Advisor: "Undefined array key status"

## What was wrong
The failing test wasn't the test's fault, and `save()` was NOT returning an array — it returns a model.
The real bug was one line in `ProjectAdvisorService::save()`:

```php
'status' => in_array(($data['status'] ?? 'sized'), [...], true) ? $data['status'] : 'sized',
```

When `save()` is called without a `status` (which the tests and "start from package" all do), the
`in_array(...)` check passes on the `'sized'` default, and the ternary then reads `$data['status']` — a
key that doesn't exist — which PHP 8 raises as **"Undefined array key 'status'"** (turned into an
ErrorException in the test suite). Every save-without-status path hit it.

## The fix
Resolve the status into a local variable first, then validate it — never re-read the missing key:

```php
$status = (string) ($data['status'] ?? 'sized');
if (! in_array($status, ['draft','sized','proposed','won','lost'], true)) $status = 'sized';
// ...
'status' => $status,
```

## File in this zip (1)
- `app/Services/Advisor/ProjectAdvisorService.php` — the corrected service (this is the only change).

## Upload (GitHub, branch `main`)
Replace `app/Services/Advisor/ProjectAdvisorService.php` with this file. Then the green ✓ returns:
`AdvisorProposalTest`, `AdvisorProjectTest` and `AdvisorPackageTest` all pass.
