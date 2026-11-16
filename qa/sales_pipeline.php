<?php declare(strict_types=1);
// Pure test of the standard sales pipeline rules (App\Services\Sales\Pipeline\Pipeline). Framework-free.
//   php qa/sales_pipeline.php
require __DIR__ . '/../app/Services/Sales/Pipeline/Pipeline.php';

use App\Services\Sales\Pipeline\Pipeline as P;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

$qual = ['service_line' => 'fiber', 'area' => 'Gudele Two, near the church', 'customer_type' => 'home', 'users' => 4];
$none = [];
$ready = ['qualification' => $qual, 'coverage_status' => 'covered', 'open_quote' => ['id' => 1], 'accepted_quote' => ['id' => 1], 'work_order_status' => 'completed'];

echo "== stages\n";
chk('ten stages, in order', array_keys(P::STAGES), ['new', 'contacted', 'qualified', 'site_check', 'quoted', 'negotiating', 'won', 'installation', 'active', 'lost']);
chk('an old lead with no stage takes it from its status', [P::stageOf(null, 'assigned'), P::stageOf(null, 'contacted'), P::stageOf(null, 'qualified'), P::stageOf(null, 'won')], ['new', 'contacted', 'qualified', 'won']);
chk('a lead marked lost on the old Leads screen shows as lost', P::stageOf('quoted', 'lost'), 'lost');
chk('a lead marked won on the old screen while still quoted shows as won', P::stageOf('quoted', 'won'), 'won');
chk('the Leads screen status follows the stage', [P::statusFor('site_check'), P::statusFor('installation'), P::statusFor('lost'), P::statusFor('new', 'assigned')], ['qualified', 'won', 'lost', 'assigned']);

echo "== gates\n";
chk('contacting needs nothing', P::check('new', 'contacted', $none, '', false), null);
chk('qualified needs the four answers', P::check('contacted', 'qualified', $none, '', false)['missing'] ?? null,
    ['which service (Starlink, Fiber, both or unsure)', 'the exact area or landmark', 'home, business or organisation', 'how many people or devices will use it']);
chk('…and passes once recorded', P::check('contacted', 'qualified', ['qualification' => $qual], '', false), null);
chk('site check needs a coverage result', P::check('qualified', 'site_check', ['qualification' => $qual], '', false)['missing'] ?? null, ['a coverage check (or a site-survey result)']);
chk('quoting needs confirmed coverage AND a quotation', P::check('site_check', 'quoted', ['qualification' => $qual, 'coverage_status' => 'survey'], '', false)['missing'] ?? null,
    ['coverage confirmed (published as covered, or a passed site survey)', 'a quotation sent to this customer']);
chk('a passed site survey counts as confirmed coverage', P::check('site_check', 'quoted', ['coverage_status' => 'survey_ok', 'open_quote' => ['id' => 2]], '', false), null);
chk('won needs the quotation accepted', P::check('negotiating', 'won', ['open_quote' => ['id' => 1]], '', false)['missing'] ?? null, ['the customer\'s quotation marked accepted']);
chk('installed needs the installation job completed', P::check('installation', 'active', ['accepted_quote' => ['id' => 1], 'work_order_status' => 'in_progress'], '', false)['missing'] ?? null, ['the installation job completed']);
chk('skipping forward checks EVERY gate on the way', P::check('new', 'quoted', ['open_quote' => ['id' => 1]], '', false)['error'] ?? null, 'gate');
chk('…and is allowed when all are met', P::check('new', 'installation', $ready, '', false), null);

echo "== lost, back, closed\n";
chk('lost needs a reason', [P::check('quoted', 'lost', $none, '', false)['error'] ?? null, P::check('quoted', 'lost', $none, 'Chose another provider', false)], ['reason_required', null]);
chk('going back needs a reason', [P::check('quoted', 'qualified', $none, '', false)['error'] ?? null, P::check('quoted', 'qualified', $none, 'Customer changed the address', false)], ['reason_required', null]);
chk('only a manager reopens a lost lead, with a reason', [P::check('lost', 'contacted', $none, 'Came back', false)['error'] ?? null, P::check('lost', 'contacted', $none, '', true)['error'] ?? null, P::check('lost', 'contacted', $none, 'Came back', true)], ['manager_only', 'reason_required', null]);
chk('a reopened lead walks the gates again', P::check('lost', 'quoted', $none, 'Came back', true)['error'] ?? null, 'gate');
chk('installed is final', P::check('active', 'lost', $none, 'x', true)['error'] ?? null, 'final');
chk('no move to the same stage or an unknown one', [P::check('quoted', 'quoted', $none, 'x', true)['error'] ?? null, P::check('quoted', 'paid', $none, 'x', true)['error'] ?? null], ['bad_stage', 'bad_stage']);

echo "== follow-ups\n";
chk('each open stage sets the next action and when', [P::followUp('contacted'), P::followUp('quoted')], [[1, 'Qualify: service, exact area, home or business, number of users'], [2, 'Follow up the quotation']]);
chk('closed stages have no follow-up', [P::followUp('active'), P::followUp('lost')], [null, null]);
chk('choices: a salesperson cannot reopen lost; nothing after installed', [P::choices('lost', false), P::choices('active', true)], [[], []]);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
