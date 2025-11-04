<?php

namespace App\Http\Controllers;

use App\Models\ServicePricing;
use Illuminate\Http\Request;

class ServicePricingController extends Controller
{
    public function show(Request $request)
    {
        $serviceKey = $request->input('service_key');
        $package = $request->input('package');
        $units = (int) $request->input('units');

        if (!$serviceKey || !$package || !$units) {
            return response()->json(['error' => 'service_key, package, units are required'], 400);
        }

        $pricing = ServicePricing::where([
            'service_key' => $serviceKey,
            'package' => $package,
            'units' => $units,
        ])->first();

        if (!$pricing) {
            return response()->json(['price_rupees' => null], 200);
        }

        return response()->json(['price_rupees' => $pricing->price_rupees]);
    }
}


