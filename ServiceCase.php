<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ServiceCase extends Model
{
    use HasFactory;

    // Use SQLite connection
    protected $connection = 'sqlite';

    protected $table = 'service_cases';

    protected $fillable = [
        'case_code',
        'customer_id',
        'agent_id',
        'service_type',
        'package',
        'service_date',
        'service_datetime',
        'status',
        'amount',
        'payment_status'
    ];

    protected $casts = [
        'service_date' => 'date',
        'service_datetime' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the customer that owns the service case
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ServiceCustomer::class, 'customer_id');
    }

    /**
     * Get the service type for this case
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type', 'type');
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // Generate case code before creating
        static::creating(function ($serviceCase) {
            if (empty($serviceCase->case_code)) {
                $serviceCase->case_code = static::generateUniqueCaseCode($serviceCase->service_type);
            }
        });
    }

    /**
     * Generate a unique case code matching your existing format
     * Format: CASE@ServiceType@00001
     */
    public static function generateUniqueCaseCode($serviceType = null): string
    {
        do {
            // Get the next case number
            $lastCase = static::orderBy('id', 'desc')->first();
            $nextNumber = $lastCase ? (intval(substr($lastCase->case_code, -5)) + 1) : 1;
            
            // Format the service type
            $serviceTypeFormatted = $serviceType ? str_replace(' ', '', $serviceType) : 'Service';
            
            $caseCode = 'CASE@' . $serviceTypeFormatted . '@' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        } while (static::where('case_code', $caseCode)->exists());

        return $caseCode;
    }

    /**
     * Scope for cases by status
     */
    public function scopeByStatus($query, $status)
    {
        // column removed; no-op for compatibility
        return $query;
    }

    /**
     * Scope for cases by payment status
     */
    public function scopeByPaymentStatus($query, $paymentStatus)
    {
        return $query->where('payment_status', $paymentStatus);
    }

    /**
     * Scope for cases by service type
     */
    public function scopeByServiceType($query, $serviceType)
    {
        return $query->where('service_type', $serviceType);
    }

    /**
     * Scope for cases by agent
     */
    public function scopeByAgent($query, $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Scope for active cases
     */
    public function scopeActive($query)
    {
        // column removed; return all records
        return $query;
    }

    /**
     * Scope for completed cases
     */
    public function scopeCompleted($query)
    {
        // column removed; no-op
        return $query;
    }

    /**
     * Scope for pending payment cases
     */
    public function scopePendingPayment($query)
    {
        return $query->where('payment_status', 'pending');
    }

    /**
     * Get status badge class for UI
     */
    public function getStatusBadgeClassAttribute(): string
    {
        return 'badge-light';
    }

    /**
     * Get payment status badge class for UI
     */
    public function getPaymentStatusBadgeClassAttribute(): string
    {
        return match($this->payment_status) {
            'pending' => 'badge-warning',
            'paid' => 'badge-success',
            'partial' => 'badge-info',
            'refunded' => 'badge-danger',
            default => 'badge-light'
        };
    }

    /**
     * Mark case as completed
     */
    public function markAsCompleted(): void
    {
        // status column removed
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(string $status): void
    {
        $this->update(['payment_status' => $status]);
    }

    /**
     * Update case status
     */
    public function updateCaseStatus(string $status): void
    {
        // status column removed
    }
}