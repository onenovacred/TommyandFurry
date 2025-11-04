<?php

namespace App\Http\Controllers;

use App\Models\ServiceCustomer;
use App\Models\ServiceCase;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ServiceCustomerController extends Controller
{
    /**
     * Get all customers with their cases
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ServiceCustomer::with(['serviceCases.serviceType']);

            // Filter by active status
            if ($request->has('active_only') && $request->boolean('active_only')) {
                $query->active();
            }

            // Search by name or email
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $customers = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => $customers
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving customers: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new customer
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:100',
                'last_name' => 'nullable|string|max:100',
                'email' => 'nullable|email|unique:service_customers,email',
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'pincode' => 'nullable|string|max:10'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customer = ServiceCustomer::create($request->all());

            Log::info('Customer created successfully', [
                'customer_id' => $customer->id,
                'email' => $customer->email
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'data' => $customer->load('serviceCases')
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating customer: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific customer with their cases
     */
    public function show($id): JsonResponse
    {
        try {
            $customer = ServiceCustomer::with(['serviceCases.serviceType'])->find($id);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer retrieved successfully',
                'data' => $customer
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving customer: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a customer
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $customer = ServiceCustomer::find($id);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'first_name' => 'sometimes|required|string|max:100',
                'last_name' => 'nullable|string|max:100',
                'email' => 'nullable|email|unique:service_customers,email,' . $id,
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'pincode' => 'nullable|string|max:10'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customer->update($request->all());

            Log::info('Customer updated successfully', [
                'customer_id' => $customer->id,
                'email' => $customer->email
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => $customer->load('serviceCases')
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating customer: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a customer
     */
    public function destroy($id): JsonResponse
    {
        try {
            $customer = ServiceCustomer::find($id);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            // Check if customer has active cases
            $activeCases = $customer->activeCases()->count();
            if ($activeCases > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete customer with active service cases',
                    'active_cases_count' => $activeCases
                ], 422);
            }

            $customer->delete();

            Log::info('Customer deleted successfully', [
                'customer_id' => $id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting customer: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
