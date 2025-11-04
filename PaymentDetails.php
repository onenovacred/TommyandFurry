<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentDetails extends Model
{
    //
    protected $connection = 'sqlite';

    protected $table='payments';
    protected $fillable=[
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'amount',
        'value1',
        'value2',
        'currency',
        'status',
        'method',
        'card_last4',
        'card_network',
        'customer_name',
        'customer_email',
        'customer_phone',
        'car_make',
        'car_model',
        'year',
        'insurance_type',
        'policy_duration',
        'reference_id',
        'quote_amount',
        'payment_completed_at'
    ];
}
