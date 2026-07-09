<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['tenant_id','name','phone','note','pay_term_days','opening_balance','opening_currency','contact_id','business_name','contact_name','email','tax_number','address','active'];
}
