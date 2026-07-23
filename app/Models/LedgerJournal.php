<?php
namespace App\Models;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
class LedgerJournal extends Model {
    use BelongsToTenant;
    protected $table = 'ledger_journals';
    protected $fillable = ['tenant_id','ref','source','source_id','memo','amount_usd','status','reversed_by','created_by','posted_at'];
    protected $casts = ['posted_at'=>'datetime','amount_usd'=>'decimal:2'];
    public function lines(){ return $this->hasMany(LedgerLine::class,'journal_id'); }
}
