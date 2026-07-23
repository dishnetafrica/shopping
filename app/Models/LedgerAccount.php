<?php
namespace App\Models;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
class LedgerAccount extends Model {
    use BelongsToTenant;
    protected $table = 'ledger_accounts';
    protected $fillable = ['tenant_id','code','name','type','normal','currency','is_active'];
}
