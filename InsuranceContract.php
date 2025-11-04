<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceContract extends Model
{
    protected $fillable = [
        'contract_start_date',
        'contract_end_date', 
        'product_id',
        'ncb_transfer',
        'ncb_percentage',
        'contract_tenure',
        'policy_type',
        'vehicle_make',
        'vehicle_model',
        'premium_amount',
        'status'
    ];

    protected $casts = [
        'premium_amount' => 'decimal:2',
        'ncb_percentage' => 'integer',
        'contract_tenure' => 'integer'
    ];
}