<?php

namespace App\Http\Controllers;

use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ServiceTypeController extends Controller
{
    /**
     * Get all service types
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ServiceType::query();

            // Search by type
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where('type', 'like', "%{$search}%");
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $serviceTypes = $query->orderBy('type')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Service types retrieved successfully',
                'data' => $serviceTypes
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving service types: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve service types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new service type
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|string|max:255|unique:service_types,type'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $serviceType = ServiceType::create($request->all());

            Log::info('Service type created successfully', [
                'service_type_id' => $serviceType->id,
                'type' => $serviceType->type
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service type created successfully',
                'data' => $serviceType
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating service type: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create service type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific service type
     */
    public function show($id): JsonResponse
    {
        try {
            $serviceType = ServiceType::with('serviceCases')->find($id);

            if (!$serviceType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service type not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Service type retrieved successfully',
                'data' => $serviceType
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving service type: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve service type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a service type
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $serviceType = ServiceType::find($id);

            if (!$serviceType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service type not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'type' => 'sometimes|required|string|max:255|unique:service_types,type,' . $id
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $serviceType->update($request->all());

            Log::info('Service type updated successfully', [
                'service_type_id' => $serviceType->id,
                'type' => $serviceType->type
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service type updated successfully',
                'data' => $serviceType
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating service type: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update service type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a service type
     */
    public function destroy($id): JsonResponse
    {
        try {
            $serviceType = ServiceType::find($id);

            if (!$serviceType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service type not found'
                ], 404);
            }

            // Check if service type has active cases
            $activeCases = $serviceType->serviceCases()->whereIn('status', ['pending', 'in_progress'])->count();
            if ($activeCases > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete service type with active cases',
                    'active_cases_count' => $activeCases
                ], 422);
            }

            $serviceType->delete();

            Log::info('Service type deleted successfully', [
                'service_type_id' => $id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service type deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting service type: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete service type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get service type statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_service_types' => ServiceType::count(),
                'total_cases' => ServiceType::withCount('serviceCases')->get()->sum('service_cases_count'),
                'service_types_with_cases' => ServiceType::has('serviceCases')->count(),
                'service_types_without_cases' => ServiceType::doesntHave('serviceCases')->count()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Service type statistics retrieved successfully',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving service type statistics: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve service type statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
