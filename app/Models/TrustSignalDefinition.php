<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Inventory Trust Engine v1 — signal weights mirror (code in Services\Trust\SignalCatalog is authoritative). */
class TrustSignalDefinition extends Model
{
    protected $table = 'trust_signal_definitions';
    protected $guarded = [];
    protected $casts = ['applies_to' => 'array', 'active' => 'boolean'];
}
