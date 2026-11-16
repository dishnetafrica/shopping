<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A piece of governed sales knowledge (Sales Brain). Content lives on its versions. */
class SalesKnowledgeEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'topic', 'key', 'title'];

    public function versions(): HasMany
    {
        return $this->hasMany(SalesKnowledgeVersion::class, 'entry_id');
    }
}
