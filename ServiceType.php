<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServiceType extends Model
{
    use HasFactory;

    protected $connection = 'sqlite';

    protected $table = 'service_types';

    protected $fillable = [
        'type'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the service cases for this service type
     */
    public function serviceCases()
    {
        return $this->hasMany(ServiceCase::class, 'service_type', 'type');
    }

    /**
     * Scope for service types by type name
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}