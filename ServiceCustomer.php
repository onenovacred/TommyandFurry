<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ServiceCustomer extends Model
{
    use HasFactory;

    // Use SQLite connection
    protected $connection = 'sqlite';

    protected $table = 'service_customers';

    // Disable Laravel's default timestamps since we only have created_at
    public $timestamps = false;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'pincode'
    ];

    protected $casts = [
        'created_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Get the service cases for the customer
     */
    public function serviceCases(): HasMany
    {
        return $this->hasMany(ServiceCase::class, 'customer_id');
    }

    /**
     * Get the customer's full name
     */
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    /**
     * Get active service cases
     */
    public function activeCases(): HasMany
    {
        return $this->serviceCases()->whereIn('status', ['pending', 'in_progress']);
    }

    /**
     * Get completed service cases
     */
    public function completedCases(): HasMany
    {
        return $this->serviceCases()->where('status', 'completed');
    }

    /**
     * Scope for customers with cases
     */
    public function scopeWithCases($query)
    {
        return $query->with('serviceCases');
    }
}
