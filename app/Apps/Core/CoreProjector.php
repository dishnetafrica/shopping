<?php
namespace App\Apps\Core;

use App\Models\KnowledgeAction;
use App\Models\Product;
use App\Services\Knowledge\Contracts\Projector;
use App\Services\Knowledge\OperationalStateStore;

/**
 * Applies confirmed Core actions into operational projections (never into knowledge_facts).
 * set_price → live Product price; set_operational → OperationalStateStore (today/dated).
 */
class CoreProjector implements Projector
{
    public function __construct(private OperationalStateStore $state) {}

    public function apply(KnowledgeAction $action): string
    {
        return match ($action->action_type) {
            'set_price'       => $this->setPrice($action),
            'set_operational' => $this->setOperational($action),
            default           => 'noop',
        };
    }

    public function revert(KnowledgeAction $action): void
    {
        $p = $action->params_json ?? [];
        if ($action->action_type === 'set_operational') {
            $this->state->forget((int) $action->tenant_id, 'core', (string) ($p['key'] ?? ''), $p['date'] ?? null);
        }
        // price revert is handled by the merchant change-request undo snapshot (2b).
    }

    private function setPrice(KnowledgeAction $action): string
    {
        $name = (string) $action->target;
        $price = (int) (($action->params_json['price'] ?? 0));
        $product = Product::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if (! $product) return 'price:not-found:' . $name;       // create-if-missing is the merchant lane's job (2b)
        $product->price = $price;
        $product->save();
        return 'price:' . $name . '=' . $price;
    }

    private function setOperational(KnowledgeAction $action): string
    {
        $p = $action->params_json ?? [];
        $this->state->set(
            (int) $action->tenant_id, 'core', (string) ($p['key'] ?? 'misc'),
            (array) ($p['value'] ?? []), (string) ($p['scope'] ?? 'today'), $p['date'] ?? null
        );
        return 'operational:' . ($p['key'] ?? 'misc');
    }
}
