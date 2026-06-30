<?php
/**
 * Pure-logic checks for the Task Engine config/maths — no DB, no framework boot.
 * Stubs the two framework touchpoints (Carbon, Tenant) and exercises the real
 * App\Support\WorkflowConfig. Run: php tests/Ops/workflow_logic_test.php
 */

namespace Illuminate\Support {
    class Carbon extends \DateTime {
        public static function parse($x) {
            return $x instanceof \DateTimeInterface ? new self($x->format('Y-m-d H:i:s')) : new self((string) $x);
        }
        public static function now() { return new self('now'); }
        public static function instance($c) { return self::parse($c); }
        public function addMinutes($n) { $c = clone $this; $c->modify('+' . (int) $n . ' minutes'); return $c; }
        public function eq($o) { return $this->getTimestamp() === self::parse($o)->getTimestamp(); }
        public function greaterThan($o) { return $this->getTimestamp() > self::parse($o)->getTimestamp(); }
    }
}

namespace App\Models {
    class Tenant {
        public $id = 99;
        private $s;
        public function __construct(array $s = ['workflow_enabled' => true]) { $this->s = $s; }
        public function setting($k, $d = null) { return $this->s[$k] ?? $d; }
    }
}

namespace {
    require __DIR__ . '/../../app/Support/WorkflowConfig.php';
    use App\Support\WorkflowConfig as W;
    use App\Models\Tenant;
    use Illuminate\Support\Carbon;

    $pass = 0; $fail = 0;
    function ok($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "  XX  $label\n"; } }

    $tenant = new Tenant();

    echo "stages / ordering\n";
    ok('5 default stages', count(W::stages($tenant)) === 5);
    ok('first stage is sales', W::firstStage($tenant)['key'] === 'sales');
    ok('after gov is store', W::nextStage($tenant, 'gov')['key'] === 'store');
    ok('after delivery is null (order completes)', W::nextStage($tenant, 'delivery') === null);
    ok('gov requires upload', W::stage($tenant, 'gov')['upload'] === true);
    ok('gov role is invoice_officer', W::stage($tenant, 'gov')['role'] === 'invoice_officer');
    ok('gov index is 2', W::stage($tenant, 'gov')['_index'] === 2);
    ok('disabled when flag off', W::enabled(new Tenant([])) === false);

    echo "SLA / due / pause maths\n";
    $a = Carbon::parse('2026-06-30 09:00:00');
    ok('due = assigned + 240m', W::dueAt($a, 240, 0)->eq(Carbon::parse('2026-06-30 13:00:00')));
    ok('pause extends due by paused minutes', W::dueAt($a, 240, 90)->eq(Carbon::parse('2026-06-30 14:30:00')));
    ok('overdue past due + in progress', W::isOverdue('in_progress', Carbon::parse('2026-06-30 13:00'), Carbon::parse('2026-06-30 13:01')) === true);
    ok('not overdue before due', W::isOverdue('in_progress', Carbon::parse('2026-06-30 13:00'), Carbon::parse('2026-06-30 12:59')) === false);
    ok('paused never overdue', W::isOverdue('paused', Carbon::parse('2026-06-30 13:00'), Carbon::parse('2026-07-01 00:00')) === false);
    ok('completed never overdue', W::isOverdue('completed', Carbon::parse('2026-06-30 13:00'), Carbon::parse('2026-07-01 00:00')) === false);

    echo "role -> owner (least-loaded)\n";
    ok('picks lighter-loaded user', W::pickOwner([10, 11], [10 => 5, 11 => 2]) === 11);
    ok('first candidate when tied', W::pickOwner([10, 11], [10 => 0, 11 => 0]) === 10);
    ok('user with no open tasks wins', W::pickOwner([10, 11], [10 => 3]) === 11);
    ok('empty role -> null', W::pickOwner([], [5 => 1]) === null);
    ok('role label', W::roleLabel('invoice_officer') === 'Invoice officer');
    echo "checklist / exceptions config\n";
    ok('gov checklist has 5 steps', count(W::checklistFor($tenant, 'gov')) === 5);
    ok('checklist items start not done', W::checklistFor($tenant, 'gov')[0]['done'] === false);
    ok('gov exceptions = portal_down,missing_doc,other', array_keys(W::exceptionsFor($tenant, 'gov')) === ['portal_down','missing_doc','other']);
    ok('store out_of_stock reassigns to procurement', W::exceptionsFor($tenant, 'store')['out_of_stock']['reassign'] === 'procurement');
    ok('sales (no override) gets all exception types', count(W::exceptionsFor($tenant, 'sales')) === 4);
    echo "priority / waiting-for\n";
    ok('gov priority = high (2)', W::priorityFor($tenant, 'gov') === 2);
    ok('sales priority = normal (1) default', W::priorityFor($tenant, 'sales') === 1);
    ok('priority label 2 = High', W::priorityLabel(2) === 'High');
    ok('5 waiting-for options', count(W::WAITING_FOR) === 5);
    ok('portal_down waits on government', W::EXCEPTION_WAITS['portal_down'] === 'government');
    echo "field channel\n";
    ok('delivery accept = required', W::acceptPolicy($tenant, 'delivery') === 'required');
    ok('store accept = optional', W::acceptPolicy($tenant, 'store') === 'optional');
    ok('sales accept = none', W::acceptPolicy($tenant, 'sales') === 'none');
    ok('delivery is a field stage', W::isFieldStage($tenant, 'delivery') === true);
    ok('office is not a field stage', W::isFieldStage($tenant, 'office') === false);
    ok('offer TTL default 15 min', W::offerTtl($tenant) === 15);




    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
