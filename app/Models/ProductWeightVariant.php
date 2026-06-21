<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductWeightVariant extends Model
{
    protected $fillable = ['product_id', 'weight_grams', 'price'];
    protected $casts = ['price' => 'decimal:2', 'weight_grams' => 'integer'];

    public function product() { return $this->belongsTo(Product::class); }
}
