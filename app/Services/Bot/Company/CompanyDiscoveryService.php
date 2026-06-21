<?php

namespace App\Services\Bot\Company;

use App\Models\CompanyMemory;
use App\Models\EmployeeMemory;
use App\Models\Message;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Bot\Discovery\DiscoveryReport;
use App\Services\Bot\Discovery\MessageCorpus;

/**
 * Multi-Employee Learning — orchestrator. Groups a tenant's conversations by the employee who
 * handled them, runs the EXISTING Discovery per employee, feeds the KnowledgeConsensusEngine, and
 * persists Company Memory + Employee Memory. Builds no new parsers — discovery is reused per group.
 */
class CompanyDiscoveryService
{
    /** Run multi-employee discovery for a tenant and return the consensus + Company DNA report. */
    public function discover(Tenant $tenant, bool $persist = true): array
    {
        $groups = $this->groupByEmployee($tenant);
        $orders = $this->orders($tenant);

        $employees = [];
        foreach ($groups as $employee => $rows) {
            $corpus = MessageCorpus::fromRows($rows);
            if ($corpus->total() === 0) continue;
            $report = DiscoveryReport::build($corpus, $orders, (string) ($tenant->name ?? ''));
            $employees[] = ['employee' => $employee, 'report' => $report];
        }

        $consensus = KnowledgeConsensusEngine::consensus($employees);

        if ($persist) $this->persist($tenant, $consensus);

        return $consensus;
    }

    /**
     * Group messages into per-employee conversation buckets. A conversation (by customer phone) is
     * assigned to the employee who sent the most outbound messages in it; every message in that
     * conversation goes to that employee's corpus (so each employee's history is the threads they
     * actually handled).
     *
     * @return array<string,array<int,array>> employee => corpus rows
     */
    protected function groupByEmployee(Tenant $tenant): array
    {
        $msgs = Message::where('tenant_id', $tenant->id)->orderBy('id')->limit(8000)->get();

        // bucket messages by conversation
        $byConvo = [];
        foreach ($msgs as $m) {
            $byConvo[(string) $m->customer_phone][] = $m;
        }

        $groups = [];
        foreach ($byConvo as $convoMsgs) {
            $employee = $this->primaryEmployee($convoMsgs);
            foreach ($convoMsgs as $m) {
                $groups[$employee][] = [
                    'direction' => (string) $m->direction,
                    'body'      => (string) $m->body,
                    'ts'        => optional($m->created_at)->toDateTimeString(),
                ];
            }
        }
        return $groups;
    }

    /** The staff member who handled a conversation = the most frequent outbound sender. */
    protected function primaryEmployee(array $convoMsgs): string
    {
        $tally = [];
        foreach ($convoMsgs as $m) {
            if ((string) $m->direction !== 'out') continue;
            $sender = trim((string) ($m->sender ?? ''));
            if ($sender === '') continue;
            $tally[$sender] = ($tally[$sender] ?? 0) + 1;
        }
        if (! $tally) return 'Staff';
        arsort($tally);
        return (string) array_key_first($tally);
    }

    protected function orders(Tenant $tenant): array
    {
        return Order::where('tenant_id', $tenant->id)->orderByDesc('id')->limit(2000)->get()
            ->map(fn (Order $o) => ['items_json' => is_array($o->items_json) ? $o->items_json : []])->all();
    }

    /** Replace this tenant's company/employee memory with the latest consensus. */
    protected function persist(Tenant $tenant, array $consensus): void
    {
        CompanyMemory::where('tenant_id', $tenant->id)->delete();
        EmployeeMemory::where('tenant_id', $tenant->id)->delete();

        $cm = $consensus['company_memory'];
        foreach (['products', 'faqs', 'offers'] as $cat) {
            foreach ($cm[$cat] as $item) {
                CompanyMemory::create([
                    'category'   => $cat,
                    'fact'       => $item['value'],
                    'agreement'  => $item['agreement'] ?? 0,
                    'confidence' => $item['confidence'] ?? 0,
                    'employees'  => $item['employees'] ?? [],
                ]);
            }
        }
        foreach (['fee' => 'delivery_fee', 'free_threshold' => 'free_delivery_threshold'] as $k => $label) {
            if (! empty($cm['delivery'][$k])) {
                $d = $cm['delivery'][$k];
                CompanyMemory::create([
                    'category' => 'delivery', 'fact' => $label . ': ' . $d['value'],
                    'agreement' => $d['agreement'] ?? 0, 'confidence' => $d['confidence'] ?? 0,
                    'employees' => $d['employees'] ?? [], 'contested' => ! empty($d['contested']),
                ]);
            }
        }
        foreach ($cm['delivery']['areas'] as $a) {
            CompanyMemory::create([
                'category' => 'delivery', 'fact' => 'area: ' . $a['value'],
                'agreement' => $a['agreement'] ?? 0, 'confidence' => $a['confidence'] ?? 0, 'employees' => $a['employees'] ?? [],
            ]);
        }

        foreach ($consensus['employee_memory'] as $employee => $m) {
            EmployeeMemory::create(['employee' => $employee, 'category' => 'style', 'fact' => $m['style']['tone'] ?? '', 'detail' => $m['style'], 'confidence' => 60]);
            EmployeeMemory::create(['employee' => $employee, 'category' => 'upsell', 'fact' => $m['upsell']['level'] ?? '', 'detail' => $m['upsell'], 'confidence' => 60]);
            foreach (['unique_products', 'unique_faqs', 'unique_offers'] as $uk) {
                foreach ($m[$uk] ?? [] as $val) {
                    EmployeeMemory::create(['employee' => $employee, 'category' => $uk, 'fact' => $val, 'detail' => null, 'confidence' => 40]);
                }
            }
        }
    }
}
