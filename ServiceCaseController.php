<?php

namespace App\Http\Controllers;

use App\Models\ServiceCase;
use App\Models\ServiceCustomer;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ServiceCaseController extends Controller
{
    /**
     * Return booked services for a specific user (by email/phone),
     * derived from service_cases joined with service_customers.
     * This endpoint is separate from the generic service_history table.
     */
    public function getUserServiceHistory(Request $request): JsonResponse
    {
        try {
            $email = strtolower(trim($request->query('email')
                ?: $request->header('X-User-Email')
                ?: $request->query('customer_email', '')));
            $phone = preg_replace('/[^0-9]/', '', (string)($request->query('phone')
                ?: $request->header('X-User-Phone')));

            if (!$email && !$phone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing user identifier (email or phone)'
                ], 422);
            }

            $status = strtolower((string)$request->query('status', ''));

            $query = ServiceCase::with(['customer', 'serviceType'])
                ->whereHas('customer', function ($q) use ($email, $phone) {
                    if ($email) {
                        $q->whereRaw('LOWER(email) = ?', [$email]);
                    } elseif ($phone) {
                        $q->where('phone', $phone);
                    }
                });

            // Map requested status to payment_status where applicable
            if (in_array($status, ['pending','completed','cancelled'])) {
                if ($status === 'pending') {
                    $query->where('payment_status', 'pending');
                } elseif ($status === 'completed') {
                    $query->where('payment_status', 'paid');
                } elseif ($status === 'cancelled') {
                    // no explicit cancel flag in schema; return empty for now
                    $query->whereRaw('1=0');
                }
            }

            $rows = $query->orderByDesc('id')->limit(200)->get();

            // Normalize shape for the frontend Service History UI
            $services = $rows->map(function ($r) {
                $date = $r->service_date ? ($r->service_date instanceof \DateTimeInterface ? $r->service_date->format('Y-m-d') : (string)$r->service_date) : ($r->created_at ? $r->created_at->format('Y-m-d') : null);
                $status = $r->payment_status === 'paid' ? 'completed' : ($r->payment_status ?: 'pending');
                return [
                    'id' => $r->id,
                    'serviceName' => $r->service_type ?? optional($r->serviceType)->type ?? 'Service',
                    'date' => $date,
                    'time' => $r->service_datetime ? (new \DateTime($r->service_datetime))->format('H:i') : null,
                    'price' => (int)($r->amount ?? 0),
                    'status' => $status,
                    'provider' => 'TommyAndFurry',
                    'notes' => null,
                    'payment_id' => null,
                    'order_id' => null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'services' => $services,
                'counts' => [
                    'all' => $services->count(),
                    'completed' => $services->where('status', 'completed')->count(),
                    'pending' => $services->where('status', 'pending')->count(),
                    'cancelled' => $services->where('status', 'cancelled')->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('User service history error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user service history',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get all service cases with customer information
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ServiceCase::with(['customer', 'serviceType']);

            // case status column removed; ignore filter gracefully

            // Filter by payment status
            if ($request->has('payment_status')) {
                $query->byPaymentStatus($request->get('payment_status'));
            }

            // Filter by priority
            if ($request->has('priority')) {
                $query->byPriority($request->get('priority'));
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->byDateRange($request->get('start_date'), $request->get('end_date'));
            }

            // Search by case code or customer name
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('case_code', 'like', "%{$search}%")
                      ->orWhereHas('customer', function ($customerQuery) use ($search) {
                          $customerQuery->where('first_name', 'like', "%{$search}%")
                                       ->orWhere('last_name', 'like', "%{$search}%")
                                       ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $cases = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Service cases retrieved successfully',
                'data' => $cases
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving service cases: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve service cases',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update payment status for the latest active case by customer id.
     * Request body: { customer_id, status }
     * - status: paid | unpaid | pending | partial | refunded
     * - 'unpaid' is normalized to 'pending'
     */
    public function updatePaymentStatusByCustomer(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:service_customers,id',
                'status' => 'required|string|in:paid,unpaid,pending,partial,refunded'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $normalizedStatus = $request->status === 'unpaid' ? 'pending' : $request->status;

            $serviceCase = ServiceCase::where('customer_id', $request->customer_id)
                // status column removed
                ->latest('id')
                ->first();

            if (!$serviceCase) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active service case found for this customer'
                ], 404);
            }

            $serviceCase->update([
                'payment_status' => $normalizedStatus
            ]);

            Log::info('Service case payment status updated', [
                'case_id' => $serviceCase->id,
                'customer_id' => $serviceCase->customer_id,
                'payment_status' => $normalizedStatus
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => $serviceCase->load('customer')
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating payment status by customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new service case
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:service_customers,id',
                'service_type' => 'required|string|max:100',
                'service_date' => 'required|date',
                'agent_id' => 'nullable|string|max:45',
                'amount' => 'nullable|string|max:45',
                'payment_status' => 'nullable|string|max:45'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify customer exists
            $customer = ServiceCustomer::find($request->customer_id);
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            $caseData = $request->all();

            $serviceCase = ServiceCase::create($caseData);

            Log::info('Service case created successfully', [
                'case_id' => $serviceCase->id,
                'case_code' => $serviceCase->case_code,
                'customer_id' => $serviceCase->customer_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service case created successfully',
                'data' => $serviceCase->load(['customer'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating service case: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create service case',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific service case
     */
    public function show($id): JsonResponse
    {
        try {
            $serviceCase = ServiceCase::with(['customer', 'serviceType'])->find($id);

            if (!$serviceCase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service case not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Service case retrieved successfully',
                'data' => $serviceCase
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving service case: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve service case',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a service case
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $serviceCase = ServiceCase::find($id);

            if (!$serviceCase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service case not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'service_type_id' => 'sometimes|exists:service_types,id',
                'service_date' => 'sometimes|date',
                'service_time' => 'nullable|date_format:H:i',
                'amount' => 'nullable|numeric|min:0',
                'payment_status' => 'sometimes|in:pending,paid,partial,refunded',
                'case_status' => 'sometimes|in:open,in_progress,completed,cancelled,on_hold',
                'description' => 'nullable|string',
                'notes' => 'nullable|string',
                'priority' => 'sometimes|in:low,normal,high,urgent',
                'estimated_completion' => 'nullable|date',
                'actual_completion' => 'nullable|date',
                'assigned_to' => 'nullable|string|max:255',
                'metadata' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Normalize service_date/service_datetime to IST
            $payload = $request->all();
            if (!empty($payload['service_date'])) {
                $dt = new \DateTime($payload['service_date'], new \DateTimeZone('Asia/Kolkata'));
                $payload['service_date'] = $dt->format('Y-m-d');
            }
            if (!empty($payload['service_time']) && empty($payload['service_datetime'])) {
                $datePart = !empty($payload['service_date']) ? $payload['service_date'] : (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
                $payload['service_datetime'] = (new \DateTime($datePart . ' ' . $payload['service_time'], new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
            }
            if (!empty($payload['service_datetime'])) {
                $payload['service_datetime'] = (new \DateTime($payload['service_datetime'], new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
            }

            $serviceCase->update($payload);

            Log::info('Service case updated successfully', [
                'case_id' => $serviceCase->id,
                'case_code' => $serviceCase->case_code
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service case updated successfully',
                'data' => $serviceCase->load(['customer', 'serviceType'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating service case: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update service case',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update case status
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $serviceCase = ServiceCase::find($id);

            if (!$serviceCase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service case not found'
                ], 404);
            }

            // status column removed; accept but ignore
            $validator = Validator::make($request->all(), []);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // status column removed; no-op

            Log::info('Service case status updated', [
                'case_id' => $serviceCase->id,
                'case_code' => $serviceCase->case_code,
                'new_status' => $request->status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service case status updated successfully',
                'data' => $serviceCase->load(['customer'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating service case status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update service case status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record a payment intent for the latest active case of a customer.
     * Request body: { customer_id, amount }
     * - Sets payment_status to 'pending' (unpaid) by default.
     * - Actual transition to 'paid' should occur in your payment success callback.
     */
    public function payByCustomer(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:service_customers,id',
                'amount' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find latest case for this customer
            $serviceCase = ServiceCase::where('customer_id', $request->customer_id)
                ->latest('id')
                ->first();

            if (!$serviceCase) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active service case found for this customer'
                ], 404);
            }

            // Update amount and payment status
            $serviceCase->update([
                'amount' => $request->amount,
                'payment_status' => 'pending'
            ]);

            // Build a simple order reference and payment page URL
            $orderRef = 'CASE_' . $serviceCase->id . '_' . time();
            $baseUrl = $this->getPublicUrl();
            $paymentLink = $baseUrl . '/payment-page?order_id=' . urlencode($orderRef)
                . '&amount=' . urlencode($request->amount)
                . '&customer_name=' . urlencode(optional($serviceCase->customer)->full_name ?? 'Customer')
                . '&customer_email=' . urlencode(optional($serviceCase->customer)->email ?? 'customer@example.com');

            Log::info('Service case payment updated by customer', [
                'case_id' => $serviceCase->id,
                'customer_id' => $serviceCase->customer_id,
                'amount' => $request->amount,
                'payment_status' => 'pending'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => $serviceCase->load('customer'),
                'payment_link' => $paymentLink,
                'order_reference' => $orderRef
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating payment by customer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a public base URL, falling back to http://127.0.0.1:8000
     */
    private function getPublicUrl(): string
    {
        $publicUrl = env('PUBLIC_URL');
        if ($publicUrl) {
            return rtrim($publicUrl, '/');
        }

        $appUrl = env('APP_URL');
        if ($appUrl) {
            return rtrim($appUrl, '/');
        }

        return 'http://127.0.0.1:8000';
    }

    /**
     * Delete a service case
     */
    public function destroy($id): JsonResponse
    {
        try {
            $serviceCase = ServiceCase::find($id);

            if (!$serviceCase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service case not found'
                ], 404);
            }

            // Only allow deletion of cancelled cases
            if ($serviceCase->case_status !== 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only cancelled service cases can be deleted'
                ], 422);
            }

            $serviceCase->delete();

            Log::info('Service case deleted successfully', [
                'case_id' => $id,
                'case_code' => $serviceCase->case_code
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service case deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting service case: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete service case',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
