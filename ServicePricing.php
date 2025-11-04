<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServicePricing extends Model
{
    protected $table = 'service_pricings';
    protected $fillable = [
        'service_key', 'package', 'units', 'price_rupees'
    ];
}


