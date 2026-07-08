<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LedgerLine extends Model {
    protected $table = 'ledger_lines';
    protected $fillable = ['tenant_id','journal_id','account_id','debit_usd','credit_usd','currency','native_amount','memo'];
    protected $casts = ['debit_usd'=>'decimal:2','credit_usd'=>'decimal:2','native_amount'=>'decimal:2'];
}
