<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ServiceHistory;
use Illuminate\Support\Facades\Log;

class ServiceHistoryController extends Controller
{
    /**
     * Get service history for a customer
     */
    public function getServiceHistory(Request $request)
    {
        try {
            $customerEmail = $request->input('customer_email', 'customer@example.com');
            $status = $request->input('status'); // pending, completed, cancelled
            
            $query = ServiceHistory::byCustomer($customerEmail);
            
            if ($status) {
                $query->byStatus($status);
            }
            
            $services = $query->orderBy('created_at', 'desc')->get();
            
            // Group services by status for counts
            $counts = [
                'all' => ServiceHistory::byCustomer($customerEmail)->count(),
                'pending' => ServiceHistory::byCustomer($customerEmail)->byStatus(ServiceHistory::STATUS_PENDING)->count(),
                'completed' => ServiceHistory::byCustomer($customerEmail)->byStatus(ServiceHistory::STATUS_COMPLETED)->count(),
                'cancelled' => ServiceHistory::byCustomer($customerEmail)->byStatus(ServiceHistory::STATUS_CANCELLED)->count()
            ];
            
            return response()->json([
                'success' => true,
                'services' => $services,
                'counts' => $counts,
                'message' => 'Service history retrieved successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Service history retrieval error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve service history: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create a new service entry (when added to cart)
     */
    public function createService(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0',
                'customer_email' => 'required|email',
                'service_type' => 'string',
                'service_details' => 'array'
            ]);
            
            $service = ServiceHistory::createService([
                'customer_name' => $request->input('customer_name'),
                'customer_email' => $request->input('customer_email'),
                'customer_phone' => $request->input('customer_phone'),
                'service_type' => $request->input('service_type', ServiceHistory::SERVICE_CAR_INSURANCE),
                'amount' => $request->input('amount'),
                'currency' => $request->input('currency', 'INR'),
                'service_details' => $request->input('service_details')
            ]);
            
            Log::info('Service created', [
                'service_id' => $service->service_id,
                'customer_email' => $service->customer_email,
                'amount' => $service->amount,
                'status' => $service->status
            ]);
            
            return response()->json([
                'success' => true,
                'service' => $service,
                'message' => 'Service created successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Service creation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create service: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update service status to completed (when payment is successful)
     */
    public function markServiceCompleted(Request $request)
    {
        try {
            $request->validate([
                'service_id' => 'required|string',
                'payment_id' => 'string',
                'order_id' => 'string',
                'payment_method' => 'string'
            ]);
            
            $service = ServiceHistory::where('service_id', $request->service_id)->first();
            
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }
            
            $service->markAsCompleted(
                $request->input('payment_id'),
                $request->input('order_id'),
                $request->input('payment_method')
            );
            
            Log::info('Service marked as completed', [
                'service_id' => $service->service_id,
                'payment_id' => $request->input('payment_id'),
                'payment_method' => $request->input('payment_method')
            ]);
            
            return response()->json([
                'success' => true,
                'service' => $service,
                'message' => 'Service marked as completed'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Service completion error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark service as completed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update service status to cancelled
     */
    public function markServiceCancelled(Request $request)
    {
        try {
            $request->validate([
                'service_id' => 'required|string',
                'reason' => 'string'
            ]);
            
            $service = ServiceHistory::where('service_id', $request->service_id)->first();
            
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }
            
            $service->markAsCancelled($request->input('reason'));
            
            Log::info('Service marked as cancelled', [
                'service_id' => $service->service_id,
                'reason' => $request->input('reason')
            ]);
            
            return response()->json([
                'success' => true,
                'service' => $service,
                'message' => 'Service marked as cancelled'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Service cancellation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark service as cancelled: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get service counts for dashboard
     */
    public function getServiceCounts(Request $request)
    {
        try {
            $customerEmail = $request->input('customer_email', 'customer@example.com');
            
            $counts = [
                'all' => ServiceHistory::byCustomer($customerEmail)->count(),
                'pending' => ServiceHistory::byCustomer($customerEmail)->byStatus(ServiceHistory::STATUS_PENDING)->count(),
                'completed' => ServiceHistory::byCustomer($customerEmail)->byStatus(ServiceHistory::STATUS_COMPLETED)->count(),
                'cancelled' => ServiceHistory::byCustomer($customerEmail)->byStatus(ServiceHistory::STATUS_CANCELLED)->count()
            ];
            
            return response()->json([
                'success' => true,
                'counts' => $counts
            ]);
            
        } catch (\Exception $e) {
            Log::error('Service counts error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get service counts: ' . $e->getMessage()
            ], 500);
        }
    }
}
