<?php declare(strict_types=1);

namespace App\Http\Controllers\Advisor;

use App\Http\Controllers\Controller;
use App\Models\AdvisorProject;
use App\Services\Advisor\ProjectAdvisorService;
use Illuminate\Http\Request;

/**
 * Project Advisor API (Slice 2 — Discovery). Serves the questionnaire schema, computes a deterministic
 * sizing from answers (stateless — for live wizard preview), and saves/reads captured projects. Gated
 * on the Sales menu; tenant-scoped. Sizing always comes from the deterministic engine, never the caller.
 */
class AdvisorController extends Controller
{
    private function gate(Request $r): bool
    {
        return $r->user() && $r->user()->canMenu('orders');
    }

    /** GET /papi/advisor/schema?profile=solar — the questionnaire the wizard renders. */
    public function schema(Request $r)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $svc     = ProjectAdvisorService::make();
        $profile = (string) $r->query('profile', 'solar');
        if (! $svc->has($profile)) {
            return response()->json(['ok' => false, 'error' => 'unknown_profile', 'profiles' => $svc->profiles()], 422);
        }
        return response()->json(['ok' => true, 'schema' => $svc->schema($profile), 'profiles' => $svc->profiles()]);
    }

    /** GET|POST /papi/advisor/compute — answers → deterministic sizing (no save). */
    public function compute(Request $r)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $svc     = ProjectAdvisorService::make();
        $profile = (string) $r->input('profile', 'solar');
        if (! $svc->has($profile)) {
            return response()->json(['ok' => false, 'error' => 'unknown_profile'], 422);
        }
        $answers = (array) $r->input('answers', []);
        $config  = (array) $r->input('config', []);
        return response()->json(['ok' => true] + $svc->compute($profile, $answers, $config));
    }

    /** POST /papi/advisor/projects — create/update a captured project (stores answers + sizing). */
    public function saveProject(Request $r)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);

        $project = ProjectAdvisorService::make()->save($t, [
            'id'          => $r->input('id'),
            'profile'     => $r->input('profile', 'solar'),
            'name'        => $r->input('name'),
            'status'      => $r->input('status', 'sized'),
            'customer_id' => $r->input('customer_id'),
            'answers'     => (array) $r->input('answers', []),
            'config'      => (array) $r->input('config', []),
        ], (int) $r->user()->id);

        return response()->json(['ok' => true, 'project' => $project]);
    }

    /** GET /papi/advisor/projects — this tenant's captured projects (newest first). */
    public function listProjects(Request $r)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        $rows = AdvisorProject::where('tenant_id', $t->id)
            ->orderByDesc('id')->limit(200)
            ->get(['id', 'profile', 'name', 'status', 'customer_id', 'sizing', 'created_at']);
        return response()->json(['ok' => true, 'projects' => $rows]);
    }

    /** GET /papi/advisor/projects/{id} — one captured project in full. */
    public function showProject(Request $r, int $id)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        $project = AdvisorProject::where('tenant_id', $t->id)->find($id);
        if (! $project) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        return response()->json(['ok' => true, 'project' => $project]);
    }

    /** POST /papi/advisor/projects/{id}/proposal — build & store the proposal (Slice 3). */
    public function generateProposal(Request $r, int $id)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        $project = AdvisorProject::where('tenant_id', $t->id)->find($id);
        if (! $project) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $proposal = \App\Services\Advisor\ProposalService::forTenant($t)->generate(
            $project,
            (array) $r->input('equipment', []),
            (array) $r->input('opts', [])
        );
        return response()->json(['ok' => true, 'proposal' => $proposal, 'project_id' => $project->id]);
    }

    /** GET /papi/advisor/projects/{id}/proposal — the stored proposal. */
    public function getProposal(Request $r, int $id)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        $project = AdvisorProject::where('tenant_id', $t->id)->find($id);
        if (! $project || ! $project->proposal) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        return response()->json(['ok' => true, 'proposal' => $project->proposal]);
    }

    /** GET /papi/advisor/packages?profile=solar — the predefined solution packages (Slice 4). */
    public function packages(Request $r)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $r->user()->tenant;
        $svc = \App\Services\Advisor\PackageService::forTenant($t);
        $profile = (string) $r->query('profile', 'solar');
        return response()->json(['ok' => true, 'packages' => $svc->list($profile)]);
    }

    /** POST /papi/advisor/packages/{key}/start — create a sized project + proposal from a package. */
    public function startPackage(Request $r, string $key)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        $svc = \App\Services\Advisor\PackageService::forTenant($t);
        $profile = (string) $r->input('profile', 'solar');
        if (! $svc->get($profile, $key)) {
            return response()->json(['ok' => false, 'error' => 'unknown_package'], 404);
        }
        $res = $svc->start($profile, $key, (array) $r->input('overrides', []), (int) $r->user()->id);
        return response()->json(['ok' => true, 'project_id' => $res['project']->id, 'proposal' => $res['proposal']]);
    }

    /** GET /papi/advisor/knowledge?profile=solar&type=&category=&q= — the knowledge library (Slice 5). */
    public function knowledge(Request $r)
    {
        if (! $this->gate($r)) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $lib = new \App\Services\Advisor\KnowledgeLibrary();
        $profile = (string) $r->query('profile', 'solar');
        $entries = $lib->search($profile, [
            'type'     => (string) $r->query('type', ''),
            'category' => (string) $r->query('category', ''),
            'q'        => (string) $r->query('q', ''),
        ]);
        return response()->json([
            'ok' => true, 'entries' => $entries,
            'categories' => $lib->categories($profile), 'types' => \App\Services\Advisor\SolarKnowledge::TYPES,
        ]);
    }

    /** GET /papi/advisor/projects/{id}/proposal/pdf — a branded PDF of the stored proposal (Slice: PDF). */
    public function proposalPdf(Request $r, int $id)
    {
        if (! $this->gate($r)) {
            abort(403);
        }
        $t = $r->user()->tenant;
        app(\App\Support\TenantContext::class)->set($t->id);
        $project = AdvisorProject::where('tenant_id', $t->id)->find($id);
        if (! $project || ! $project->proposal) {
            abort(404);
        }
        $renderer = new \App\Services\Advisor\ProposalPdfRenderer();
        if (! $renderer->available()) {
            abort(503, 'PDF engine unavailable');
        }
        $pdf = $renderer->pdf($t, (array) $project->proposal);
        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="Proposal-' . $id . '.pdf"');
    }
}
