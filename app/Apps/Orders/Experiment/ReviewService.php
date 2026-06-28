<?php
namespace App\Apps\Orders\Experiment;

use App\Models\ShadowDiff;

/**
 * Converts a reviewed live-vs-shadow disagreement into engineering signal in one step:
 * accept (shadow is better) / reject (live was right) / mark bug / promote to a replay regression.
 * promoteToReplay appends a labeled case to the replay corpus — every production disagreement can
 * become a reproducible test with no hand-written test code.
 */
class ReviewService
{
    public function __construct(private string $corpusPath = '')
    {
        $this->corpusPath = $corpusPath ?: base_path('tests/Fixtures/order_replays.json');
    }

    public function accept(int $diffId): void { $this->setStatus($diffId, 'accepted'); }
    public function reject(int $diffId): void { $this->setStatus($diffId, 'rejected'); }
    public function markBug(int $diffId): void { $this->setStatus($diffId, 'bug'); }

    /**
     * @param array $expected the human-confirmed correct draft, e.g.
     *   ['products'=>[['name'=>'Vanela Gathiya','weight_grams'=>250]], 'delivery_area'=>'Ntinda', 'payment_method'=>'Mobile Money']
     */
    public function promoteToReplay(int $diffId, array $expected): void
    {
        $diff = ShadowDiff::findOrFail($diffId);
        $corpus = $this->load();
        $corpus[] = [
            'id' => 'diff-' . $diffId,
            'source' => 'shadow_diff',
            'messages' => [$diff->message],
            'expected' => $expected,
            'reasons' => $diff->reasons,
        ];
        file_put_contents($this->corpusPath, json_encode($corpus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $diff->update(['status' => 'promoted']);
    }

    private function load(): array
    {
        if (! is_file($this->corpusPath)) return [];
        return json_decode((string) file_get_contents($this->corpusPath), true) ?: [];
    }

    private function setStatus(int $diffId, string $status): void
    {
        ShadowDiff::where('id', $diffId)->update(['status' => $status]);
    }
}
