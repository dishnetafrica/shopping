<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LedgerAccount extends Model {
    protected $table = 'ledger_accounts';
    protected $fillable = ['tenant_id','code','name','type','normal','currency','is_active'];
}
