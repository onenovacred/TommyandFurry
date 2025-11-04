<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceHistory extends Model
{
    use HasFactory;

    protected $connection = 'sqlite';

    protected $table = 'service_history';

    protected $fillable = [
        'service_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'service_type',
        'amount',
        'currency',
        'status',
        'payment_id',
        'order_id',
        'payment_method',
        'service_details', // Stores data in row format (key:value|key:value) instead of JSON
        'created_at',
        'updated_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime'
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Service type constants
    const SERVICE_CAR_INSURANCE = 'Car Insurance';
    const SERVICE_HEALTH_INSURANCE = 'Health Insurance';
    const SERVICE_LIFE_INSURANCE = 'Life Insurance';

    /**
     * Scope for filtering by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for filtering by customer email
     */
    public function scopeByCustomer($query, $email)
    {
        return $query->where('customer_email', $email);
    }

    /**
     * Scope for recent services
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute()
    {
        return '₹' . number_format($this->amount, 2);
    }

    /**
     * Get status badge class
     */
    public function getStatusBadgeClassAttribute()
    {
        return match($this->status) {
            self::STATUS_PENDING => 'badge-warning',
            self::STATUS_COMPLETED => 'badge-success',
            self::STATUS_CANCELLED => 'badge-danger',
            default => 'badge-secondary'
        };
    }

    /**
     * Get status display text
     */
    public function getStatusDisplayAttribute()
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Unknown'
        };
    }

    /**
     * Mark service as completed
     */
    public function markAsCompleted($paymentId = null, $orderId = null, $paymentMethod = null)
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'payment_method' => $paymentMethod,
            'completed_at' => now()
        ]);
    }

    /**
     * Mark service as cancelled
     */
    public function markAsCancelled($reason = null)
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancellation_reason' => $reason,
            'cancelled_at' => now()
        ]);
    }

    /**
     * Convert array to row format (key:value|key:value)
     */
    public static function formatServiceDetails($data)
    {
        if (is_array($data)) {
            $pairs = [];
            foreach ($data as $key => $value) {
                if ($value !== null && $value !== '') {
                    // Escape pipe and colon characters
                    $key = str_replace([':', '|'], ['&#58;', '&#124;'], $key);
                    $value = str_replace([':', '|'], ['&#58;', '&#124;'], $value);
                    $pairs[] = $key . ':' . $value;
                }
            }
            return implode('|', $pairs);
        }
        return $data;
    }

    /**
     * Parse row format string back to array when accessing service_details
     */
    public function getServiceDetailsAttribute($value)
    {
        if (empty($value)) {
            return [];
        }
        
        // Check if it's already JSON (for backward compatibility with existing data)
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        
        // Parse row format (key:value|key:value)
        $result = [];
        $pairs = explode('|', $value);
        foreach ($pairs as $pair) {
            if (strpos($pair, ':') !== false) {
                list($key, $val) = explode(':', $pair, 2);
                // Unescape pipe and colon characters
                $key = str_replace(['&#58;', '&#124;'], [':', '|'], $key);
                $val = str_replace(['&#58;', '&#124;'], [':', '|'], $val);
                $result[$key] = $val;
            }
        }
        return $result;
    }

    /**
     * Set service_details in row format when setting value
     */
    public function setServiceDetailsAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['service_details'] = self::formatServiceDetails($value);
        } else {
            // If it's already a string, store it as-is (might be row format already)
            $this->attributes['service_details'] = $value;
        }
    }

    /**
     * Create a new service entry
     */
    public static function createService($data)
    {
        // Format service_details if provided as array
        $serviceDetails = null;
        if (isset($data['service_details']) && is_array($data['service_details'])) {
            $serviceDetails = self::formatServiceDetails($data['service_details']);
        }
        
        $payload = [
            'service_id' => 'SRV_' . time() . '_' . rand(1000, 9999),
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'service_type' => $data['service_type'] ?? self::SERVICE_CAR_INSURANCE,
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? 'INR',
            'status' => self::STATUS_PENDING,
            'order_id' => $data['order_id'] ?? null,
            'service_details' => $serviceDetails,
            'created_at' => now()
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return self::create($payload);
            } catch (\PDOException $e) {
                if (stripos($e->getMessage(), 'database is locked') !== false && $attempt < 2) {
                    usleep(200000);
                    continue;
                }
                throw $e;
            }
        }
        // Should not reach here; fallback single create
        return self::create($payload);
    }

    /**
     * Ensure a single pending row exists for a given order_id. If one exists,
     * lightly update its customer/service fields; otherwise create it.
     */
    public static function ensurePending(array $data)
    {
        $orderId = $data['order_id'] ?? ($data['service_details']['order_id'] ?? null);
        if ($orderId) {
            $existing = self::where('order_id', $orderId)->latest('id')->first();
            if ($existing) {
                // Only update non-empty incoming fields
                $patch = [];
                foreach (['customer_name','customer_email','customer_phone','service_type','amount','currency'] as $k) {
                    if (isset($data[$k]) && $data[$k] !== null && $data[$k] !== '') {
                        $patch[$k] = $data[$k];
                    }
                }
                if (isset($data['service_details']) && is_array($data['service_details'])) {
                    $patch['service_details'] = self::formatServiceDetails($data['service_details']);
                }
                if (!empty($patch)) {
                    $existing->update($patch);
                }
                return $existing;
            }
        }
        // If creating fresh, ensure order_id column is set when known
        if ($orderId && !isset($data['order_id'])) {
            $data['order_id'] = $orderId;
        }
        return self::createService($data);
    }
}
