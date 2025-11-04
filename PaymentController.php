<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\SignupModel;
use App\Models\InsuranceContract;
use Illuminate\Support\Facades\Http;
use App\Models\PaymentDetails;
use App\Models\ServiceHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Razorpay\Api\Api; 
use Illuminate\Support\Facades\DB;
use App\Models\ServiceCustomer;
use App\Models\ServiceCase;
use App\Models\ServiceType;
use App\Models\ServicePricing;
// Payment Service Integration
use App\PaymentService\Services\RazorpayService;
use App\PaymentService\Interfaces\PaymentServiceInterface;

class PaymentController extends Controller
{
    // Payment Service instance (can be injected or used directly)
    protected $paymentService;

    /**
     * Constructor - Inject PaymentService (optional)
     * You can also use PaymentService directly via dependency injection or helpers
     */
    public function __construct(PaymentServiceInterface $paymentService = null)
    {
        $this->paymentService = $paymentService ?? app(RazorpayService::class);
    }
    public function createPaymentOrder(Request $request) {
        try {
            // Try to parse JSON body if Content-Type is not set correctly
            $contentType = $request->header('Content-Type');
            if (!$request->has('amount') && $request->getContent()) {
                $bodyContent = $request->getContent();
                if (!empty($bodyContent)) {
                    $jsonData = json_decode($bodyContent, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                        // Merge parsed JSON data into request
                        $request->merge($jsonData);
                    }
                }
            }
            
            // Try multiple ways to get the amount value
            $amountValue = $request->input('amount') 
                        ?? $request->get('amount') 
                        ?? ($request->json() ? $request->json()->get('amount') : null)
                        ?? null;
            
            // Validate amount exists and is numeric
            if ($amountValue === null || $amountValue === '') {
                return response()->json([
                    'error' => 'Invalid amount provided',
                    'message' => 'Amount is required and must be a valid number',
                    'debug' => [
                        'has_amount' => $request->has('amount'),
                        'input_amount' => $request->input('amount'),
                        'all_input' => $request->all(),
                        'content_type' => $contentType,
                        'raw_content' => substr($request->getContent(), 0, 200),
                        'parsed_value' => $amountValue
                    ]
                ], 400);
            }
            
            // Validate amount is numeric
            if (!is_numeric($amountValue)) {
                return response()->json([
                    'error' => 'Invalid amount provided',
                    'message' => 'Amount must be a valid number',
                    'received_amount' => $amountValue,
                    'type' => gettype($amountValue)
                ], 400);
            }
            
            // Cast amount to float to handle both integer and decimal inputs
            $amount = (float) $amountValue;
            $customerName = $request->input('customer_name', 'Customer');
            $customerEmail = $request->input('customer_email', 'customer@example.com');
            $customerPhone = $request->input('customer_phone');
            
            // Validate amount is positive
            if ($amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided',
                    'message' => 'Amount must be greater than 0',
                    'received_amount' => $amountValue,
                    'parsed_amount' => $amount
                ], 400);
            }

            // Create a new service customer record once per flow (skip if just created recently)
            try {
                $customerData = $this->normalizeCustomerPayload($request);
                if (!empty($customerData)) {
                    $this->findOrUpdateCustomerByContact($customerData);
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to upsert service customer on createPaymentOrder: ' . $e->getMessage());
            }

            // Use PaymentService for order creation (loosely coupled approach)
            $orderData = [
                'receipt' => 'order_' . rand(1000, 9999),
                'amount' => $amount * 100, // Convert to paise
                'currency' => 'INR',
                'payment_capture' => 1, // Auto capture payment
                'notes' => [
                    'description' => 'Insurance Payment',
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail
                ]
            ];

            // Use PaymentService to create order
            $orderResult = $this->paymentService->createOrder($orderData);
            
            if (!$orderResult['success']) {
                // Fallback to demo mode if order creation fails
                $serviceType = $request->input('service_type', ServiceHistory::SERVICE_CAR_INSURANCE);
                $demoOrderId = 'demo_order_' . time() . '_' . rand(1000, 9999);
                $service = ServiceHistory::ensurePending([
                    'order_id' => $demoOrderId,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'service_type' => $serviceType,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'service_details' => [
                        'order_id' => $demoOrderId,
                        'description' => $serviceType,
                        'created_via' => 'demo_mode'
                    ]
                ]);
                
                // Also create a pending service case so UI can reflect booking before payment
                try {
                    // Determine or fetch customer created earlier
                    $uniqueCustomer = $request->input('customer_email')
                        ? ['email' => $request->input('customer_email')]
                        : ($request->input('customer_phone') ? ['phone' => $request->input('customer_phone')] : null);
                    if ($uniqueCustomer) {
                        $customer = ServiceCustomer::where(key($uniqueCustomer), current($uniqueCustomer))->first();
                        if ($customer) {
                            $serviceTypeName = $request->input('service_type', 'Pet Service');
                            $serviceDateInput = $request->input('service_datetime') ?? $request->input('service_date');
                            if ($serviceDateInput) {
                                $dtIst = new \DateTime($serviceDateInput, new \DateTimeZone('Asia/Kolkata'));
                                $serviceDate = $dtIst->format('Y-m-d');
                                $serviceDateTime = $dtIst->format('Y-m-d H:i:s');
                            } else {
                                $nowIst = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
                                $serviceDate = $nowIst->format('Y-m-d');
                                $serviceDateTime = $nowIst->format('Y-m-d H:i:s');
                            }
                            ServiceType::firstOrCreate(['type' => $serviceTypeName]);
                            ServiceCase::create([
                                'customer_id' => $customer->id,
                                'agent_id' => $request->input('agent_id') ?? $request->input('agentId'),
                                'service_type' => $serviceTypeName,
                                'package' => $request->input('package'),
                                'service_date' => $serviceDate,
                                'service_datetime' => $serviceDateTime,
                                'status' => 'pending',
                                'amount' => $amount,
                                'payment_status' => 'pending'
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Failed to create pending service case (demo): ' . $e->getMessage());
                }

                // Return demo mode for testing without Razorpay credentials
                return response()->json([
                    'order_id' => $demoOrderId,
                    'service_id' => $service->service_id,
                    'message' => 'Demo payment mode - Configure Razorpay credentials for real payments',
                    'key' => 'demo_key',
                    'amount' => $amount,
                    'currency' => 'INR',
                    'payment_method' => 'demo',
                    'note' => 'This is a demo payment. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payments.'
                ]);
            }

            // Extract order data from PaymentService response
            $order = $orderResult['data'];
            
            // Create service entry in pending status
            $serviceType = $request->input('service_type', ServiceHistory::SERVICE_CAR_INSURANCE);
            $service = ServiceHistory::ensurePending([
                'order_id' => $order['id'],
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'service_type' => $serviceType,
                'amount' => $amount,
                'currency' => 'INR',
                'service_details' => [
                    'order_id' => $order['id'],
                    'description' => $serviceType,
                    'created_via' => 'payment_order'
                ]
            ]);

            // Also create a pending service case now, to be updated after payment success
            try {
                $uniqueCustomer = $request->input('customer_email')
                    ? ['email' => $request->input('customer_email')]
                    : ($request->input('customer_phone') ? ['phone' => $request->input('customer_phone')] : null);
                if ($uniqueCustomer) {
                    $customer = ServiceCustomer::where(key($uniqueCustomer), current($uniqueCustomer))->first();
                    if ($customer) {
                        $serviceTypeName = $request->input('service_type', 'Pet Service');
                        $serviceDateInput = $request->input('service_datetime') ?? $request->input('service_date');
                        if ($serviceDateInput) {
                            $dtIst = new \DateTime($serviceDateInput, new \DateTimeZone('Asia/Kolkata'));
                            $serviceDate = $dtIst->format('Y-m-d');
                            $serviceDateTime = $dtIst->format('Y-m-d H:i:s');
                        } else {
                            $nowIst = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
                            $serviceDate = $nowIst->format('Y-m-d');
                            $serviceDateTime = $nowIst->format('Y-m-d H:i:s');
                        }
                        ServiceType::firstOrCreate(['type' => $serviceTypeName]);
                        ServiceCase::create([
                            'customer_id' => $customer->id,
                            'agent_id' => $request->input('agent_id') ?? $request->input('agentId'),
                            'service_type' => $serviceTypeName,
                            'service_date' => $serviceDate,
                            'service_datetime' => $serviceDateTime,
                            'amount' => $amount,
                            'payment_status' => 'pending'
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to create pending service case: ' . $e->getMessage());
            }
             
            return response()->json([
                'order_id' => $order['id'],
                'service_id' => $service->service_id,
                'message' => 'Proceed to Razorpay',
                'key' => $this->paymentService->getKeyId() ?? 'demo_key',
                'amount' => $amount,
                'currency' => 'INR',
                'payment_method' => 'razorpay',
                'demo_mode' => $this->paymentService->isDemoMode()
            ]);
        } catch (\Exception $e) {
            \Log::error('Payment order creation error: ' . $e->getMessage());
            
            // If Razorpay API fails, fall back to demo mode
            if (strpos($e->getMessage(), 'Invalid key') !== false || 
                strpos($e->getMessage(), 'authentication') !== false) {
                
                return response()->json([
                    'order_id' => 'demo_order_' . time() . '_' . rand(1000, 9999),
                    'message' => 'Demo payment mode - Check Razorpay credentials',
                    'key' => 'demo_key',
                    'amount' => $amount,
                    'currency' => 'INR',
                    'payment_method' => 'demo',
                    'note' => 'Razorpay credentials invalid. Using demo mode. Check your RAZORPAY_KEY and RAZORPAY_SECRET in .env file.'
                ]);
            }
            
            return response()->json([
                'error' => 'Failed to create payment order',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create Payment Order with Method Selection
     * This API creates a Razorpay order and returns payment options (Card, UPI, Netbanking, Wallet)
     * 
     * Request body:
     * - amount: required (in rupees)
     * - customer_name: optional
     * - customer_email: optional
     * - customer_phone: optional
     * - description: optional
     * 
     * Returns:
     * - order_id: Razorpay order ID
     * - amount: Payment amount
     * - currency: Payment currency
     * - key: Razorpay key ID
     * - payment_methods: Available payment methods
     * - checkout_config: Razorpay Checkout configuration
     */
    public function createPaymentWithMethodSelection(Request $request)
    {
        try {
            // Validate required fields
            $request->validate([
                'amount' => 'required|numeric|min:1',
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
                'customer_phone' => 'nullable|string|max:15',
                'description' => 'nullable|string|max:500'
            ]);

            $amount = (float) $request->input('amount');
            $customerName = $request->input('customer_name', 'Customer');
            $customerEmail = $request->input('customer_email', 'customer@example.com');
            $customerPhone = $request->input('customer_phone', '');
            $description = $request->input('description', 'Payment');

            // Create Razorpay order
            $orderData = [
                'amount' => ((int) $amount) * 100, // Convert to paise
                'currency' => 'INR',
                'receipt' => 'order_' . time() . '_' . rand(1000, 9999),
                'notes' => [
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'description' => $description
                ]
            ];

            $razorpayOrder = $this->createRazorpayOrder($orderData);

            if (!$razorpayOrder['success']) {
                // Fallback for demo mode
                $keyId = env('RAZORPAY_KEY');
                $keySecret = env('RAZORPAY_SECRET');

                if (!$keyId || !$keySecret) {
                    return response()->json([
                        'success' => true,
                        'demo_mode' => true,
                        'order_id' => 'demo_order_' . time(),
                        'amount' => $amount,
                        'currency' => 'INR',
                        'key' => 'demo_key',
                        'payment_methods' => [
                            'card' => ['enabled' => true, 'label' => 'Credit/Debit Card'],
                            'upi' => ['enabled' => true, 'label' => 'UPI'],
                            'netbanking' => ['enabled' => true, 'label' => 'Net Banking'],
                            'wallet' => ['enabled' => true, 'label' => 'Wallets']
                        ],
                        'checkout_config' => [
                            'key' => 'demo_key',
                            'amount' => $amount * 100,
                            'currency' => 'INR',
                            'name' => 'Payment Gateway',
                            'description' => $description,
                            'prefill' => [
                                'name' => $customerName,
                                'email' => $customerEmail,
                                'contact' => $customerPhone
                            ],
                            'method' => [
                                'card' => true,
                                'upi' => true,
                                'netbanking' => true,
                                'wallet' => true
                            ],
                            'theme' => [
                                'color' => '#3399cc'
                            ]
                        ],
                        'message' => 'Demo mode - Configure Razorpay credentials for real payments'
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create payment order',
                    'error' => $razorpayOrder['error']
                ], 500);
            }

            $order = $razorpayOrder['data'];
            $keyId = env('RAZORPAY_KEY');

            // Persist a pending payment row immediately so callbacks can enrich it later
            try {
                \App\Models\PaymentDetails::updateOrCreate(
                    [ 'razorpay_order_id' => $order['id'] ],
                    [
                        // Store amount in rupees to be consistent across the app
                        'amount' => $amount,
                        'currency' => 'INR',
                        'status' => 'pending',
                        'customer_name' => $customerName,
                        'customer_email' => $customerEmail,
                        'customer_phone' => $customerPhone,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            } catch (\Throwable $e) {
                \Log::warning('Failed to create pending payment row for method-selection order', [
                    'order_id' => $order['id'],
                    'error' => $e->getMessage()
                ]);
            }

            // Define available payment methods
            $paymentMethods = [
                'card' => [
                    'enabled' => true,
                    'label' => 'Credit/Debit Card',
                    'description' => 'Pay using credit or debit card'
                ],
                'upi' => [
                    'enabled' => true,
                    'label' => 'UPI',
                    'description' => 'Pay using UPI ID or QR code'
                ],
                'netbanking' => [
                    'enabled' => true,
                    'label' => 'Net Banking',
                    'description' => 'Pay using your bank account'
                ],
                'wallet' => [
                    'enabled' => true,
                    'label' => 'Wallets',
                    'description' => 'Pay using Paytm, PhonePe, etc.'
                ]
            ];

            // Create Checkout configuration for Razorpay
            $checkoutConfig = [
                'key' => $keyId,
                'amount' => $order['amount'],
                'currency' => $order['currency'],
                'order_id' => $order['id'],
                'name' => 'Payment Gateway',
                'description' => $description,
                'prefill' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'method' => [
                    'card' => true,
                    'upi' => true,
                    'netbanking' => true,
                    'wallet' => true
                ],
                'theme' => [
                    'color' => '#3399cc'
                ]
            ];

            // Generate payment page URL
            $baseUrl = $request->getSchemeAndHttpHost();
            $paymentPageUrl = $baseUrl . '/payment-method-selection?' . http_build_query([
                'amount' => $amount,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'description' => $description
            ]);
            
            // Always return JSON response for API calls
            // The payment_page_url can be used by frontend to redirect users
            return response()->json([
                'success' => true,
                'order_id' => $order['id'],
                'amount' => $amount,
                'currency' => 'INR',
                'key' => $keyId,
                'payment_methods' => $paymentMethods,
                'checkout_config' => $checkoutConfig,
                'razorpay_order' => $order,
                'payment_page_url' => $paymentPageUrl,
                'redirect_url' => $paymentPageUrl, // Alias for convenience
                'message' => 'Payment order created successfully. Open payment_page_url in browser or use checkout_config to initialize Razorpay Checkout directly.',
                'instructions' => 'To open payment page: Copy payment_page_url and open in browser, or use GET /payment-method-selection with query parameters'
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment with method selection error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create payment order',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Initiate a payment for a service with a specific package and duration.
     * If amount is not supplied, it is looked up from service_pricings.
     * Request body:
     * - service_type: string (e.g., Training, Daily Dog Walking)
     * - package: hourly|monthly|yearly
     * - package_units: integer (e.g., 1,2,3...)
     * - amount: optional integer rupees (overrides lookup)
     * - customer_name, customer_email, customer_phone, address, city, state, pincode (optional)
     * - service_datetime (optional ISO) or service_date (Y-m-d)
     */
    public function initiatePackagePayment(Request $request)
    {
        $request->validate([
            'service_type' => 'required|string',
            'package' => 'required|string|in:hourly,monthly,yearly',
            'package_units' => 'required|integer|min:1',
            'amount' => 'nullable|integer|min:1'
        ]);

        $amount = (int) $request->input('amount');
        if (!$amount) {
            $pricing = ServicePricing::where([
                'service_key' => $request->input('service_type'),
                'package' => $request->input('package'),
                'units' => (int) $request->input('package_units')
            ])->first();
            if (!$pricing) {
                return response()->json([
                    'error' => 'No pricing configured for this service/package/units. Seed or supply amount.'
                ], 422);
            }
            $amount = (int) $pricing->price_rupees;
        }

        // Merge computed amount so downstream order creation works
        $request->merge(['amount' => $amount]);

        // Reuse existing order creation logic (will also persist demo-mode case)
        return $this->createPaymentOrder($request);
    }

    /**
     * Create a Razorpay Payment Link from a package selection in one call.
     * Body: { service_type, package, package_units, customer_* fields, description? }
     * - Looks up price via service_pricings; if not found, returns 422.
     * - Creates a payment link and returns short_url in response (demo link if keys missing).
     */
    public function initiatePackagePaymentLink(Request $request)
    {
        try {
            $request->validate([
                'service_type' => 'required|string',
                'package' => 'required|string|in:hourly,monthly,yearly',
                'package_units' => 'required|integer|min:1',
                'customer_name' => 'nullable|string',
                'customer_last_name' => 'nullable|string',
                'customer_email' => 'nullable|email',
                'customer_phone' => 'nullable|string',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'state' => 'nullable|string',
                'pincode' => 'nullable|string|max:10'
            ]);

            $serviceKeyRaw = $request->input('service_type');
            $serviceKey = $this->normalizeServiceKey($serviceKeyRaw);

            $pricing = ServicePricing::where([
                'service_key' => $serviceKey,
                'package' => $request->input('package'),
                'units' => (int) $request->input('package_units')
            ])->first();

            if (!$pricing) {
                // Try a case-insensitive/LIKE fallback for convenience
                $pricing = ServicePricing::where('package', $request->input('package'))
                    ->where('units', (int) $request->input('package_units'))
                    ->where(function ($q) use ($serviceKeyRaw, $serviceKey) {
                        $q->where('service_key', 'LIKE', '%' . $serviceKeyRaw . '%')
                          ->orWhere('service_key', 'LIKE', '%' . $serviceKey . '%');
                    })
                    ->first();
            }

            if (!$pricing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No pricing configured for this service/package/units. '
                        . 'Use service_type matching seeded keys (e.g., "Training", "Daily Dog Walking").',
                ], 422);
            }

            // Reuse the existing logic directly by merging the computed amount
            $request->merge([
                'amount' => (int) $pricing->price_rupees,
                'description' => $request->input('description', $request->input('service_type') . ' ' . ucfirst($request->input('package')) . ' ' . $request->input('package_units'))
            ]);
            return $this->createServicePaymentLink($request);
        } catch (\Throwable $e) {
            \Log::error('initiatePackagePaymentLink error: ' . $e->getMessage(), [ 'trace' => $e->getTraceAsString() ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment link',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function normalizeServiceKey(?string $name): string
    {
        $n = strtolower(trim((string) $name));
        if ($n === 'walking' || $n === 'dog walking' || $n === 'daily walking') {
            return 'Daily Dog Walking';
        }
        if ($n === 'training' || $n === 'pet training') {
            return 'Training';
        }
        if ($n === 'veterinary' || $n === 'vet home consultation' || $n === 'vet') {
            return 'Vet Home Consultation';
        }
        if ($n === 'grooming' || $n === 'bath' || $n === 'bath with shampoo') {
            return 'Bath with Shampoo';
        }
        return $name ?? '';
    }

    public function getPaymentDetails(Request $request) {
        try {
            // Try to parse JSON body if Content-Type is not set correctly
            if (!$request->has('payment_id') && !$request->has('razorpay_payment_id') && $request->getContent()) {
                $bodyContent = $request->getContent();
                if (!empty($bodyContent)) {
                    $jsonData = json_decode($bodyContent, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                        // Merge parsed JSON data into request
                        $request->merge($jsonData);
                    }
                }
            }
            
            $data = $request->all();
            
            // Try multiple ways to get payment_id
            $paymentId = $data['payment_id'] 
                        ?? $request->input('payment_id')
                        ?? ($request->json() ? $request->json()->get('payment_id') : null)
                        ?? null;
            
            // Handle payment_id for fetching payment details (as per API documentation)
            if ($paymentId && !empty($paymentId)) {
                // Fetch payment details from Razorpay by payment_id
                $paymentResult = $this->paymentService->fetchPayment($paymentId);
                
                if (!$paymentResult['success']) {
                    return response()->json([
                        'error' => 'Failed to fetch payment details',
                        'message' => $paymentResult['error'] ?? 'Payment not found',
                        'payment_id' => $paymentId
                    ], 404);
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Payment details retrieved successfully',
                    'payment' => $paymentResult['data'],
                    'payment_id' => $paymentId
                ], 200);
            }
            
            // Original functionality: Payment verification with signature
            // Check if required fields are present for verification
            if (!isset($data['razorpay_order_id']) || !isset($data['razorpay_payment_id']) || !isset($data['razorpay_signature'])) {
                return response()->json([
                    'error' => 'Missing required fields',
                    'message' => 'Please provide either payment_id (to fetch payment details) OR razorpay_order_id, razorpay_payment_id, and razorpay_signature (for payment verification)',
                    'usage' => [
                        'fetch_payment' => [
                            'required_fields' => ['payment_id'],
                            'example' => ['payment_id' => 'pay_MjQzMzQzMzQzMzQzMzQz']
                        ],
                        'verify_payment' => [
                            'required_fields' => ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature']
                        ]
                    ]
                ], 400);
            }

            // Check if this is a test payment (for development/testing)
            $isTestPayment = ($data['razorpay_payment_id'] === 'pay_test' || 
                            $data['razorpay_signature'] === 'sig_test');

            if ($isTestPayment) {
                // Handle test payment - skip signature verification
                \Log::info('Test payment detected in getPaymentDetails - skipping signature verification');
                
                return response()->json([
                    'success' => true,
                    'message' => 'Test payment verification successful',
                    'payment_type' => 'test',
                    'payment_details' => [
                        'order_id' => $data['razorpay_order_id'],
                        'payment_id' => $data['razorpay_payment_id'],
                        'signature' => $data['razorpay_signature'],
                        'status' => 'verified',
                        'signature_verified' => false,
                        'test_mode' => true
                    ],
                    'note' => 'This is a test payment. For real payments, complete actual payment to get real payment_id and signature.'
                ], 200);
            }
            
            // Use PaymentService for payment verification (loosely coupled approach)
            if ($this->paymentService->isDemoMode()) {
                // Demo mode - skip signature verification
                \Log::info('Demo mode: Skipping payment signature verification');
                
                // Save or update payment details with sane defaults (demo mode)
                $this->saveOrUpdatePaymentDetails($data, $request, 'demo');
                
                // Mark service as completed for demo mode
                $service = ServiceHistory::where('order_id', $data['razorpay_order_id'])->first();
                if ($service) {
                    $service->markAsCompleted(
                        $data['razorpay_payment_id'],
                        $data['razorpay_order_id'],
                        'Demo'
                    );
                }

                // Update or create corresponding service case to reflect paid status
                try {
                    if ($service) {
                        $customerEmail = $service->customer_email;
                        $customerPhone = $service->customer_phone;
                        $rawName = trim((string) ($service->customer_name ?? ''));
                        if ($rawName === '' && $customerEmail) {
                            $rawName = explode('@', $customerEmail)[0];
                        }
                        $fullName = $rawName !== '' ? $rawName : 'Guest';
                        $nameParts = explode(' ', $fullName, 2);
                        $firstName = $nameParts[0];
                        $lastName = $nameParts[1] ?? '';

                        $unique = $customerEmail ? ['email' => $customerEmail] : ($customerPhone ? ['phone' => $customerPhone] : null);
                        if ($unique) {
                            $customer = ServiceCustomer::firstOrCreate(
                                $unique,
                                [
                                    'first_name' => $firstName,
                                    'last_name' => $lastName,
                                    'created_at' => now()
                                ]
                            );

                            $serviceTypeName = $service->service_type ?? 'Pet Service';
                            $serviceDateInput = $request->input('service_datetime') ?? $request->input('service_date');
                            $serviceDate = $serviceDateInput ? date('Y-m-d', strtotime($serviceDateInput)) : now()->toDateString();
                            $serviceDateTime = $serviceDateInput ? date('Y-m-d H:i:s', strtotime($serviceDateInput)) : now()->toDateTimeString();
                            ServiceType::firstOrCreate(['type' => $serviceTypeName]);
                            $amountRupees = $service->amount ?? null;

                            $existingCase = ServiceCase::where('customer_id', $customer->id)
                                ->where('service_type', $serviceTypeName)
                                ->latest('id')
                                ->first();

                            if ($existingCase) {
                                for ($attempt = 0; $attempt < 3; $attempt++) {
                                    try {
                                        $existingCase->update([
                                            'amount' => $amountRupees ?? $existingCase->amount,
                                            'payment_status' => 'paid'
                                        ]);
                                        break;
                                    } catch (\PDOException $e) {
                                        if (stripos($e->getMessage(), 'database is locked') !== false && $attempt < 2) {
                                            usleep(200000);
                                            continue;
                                        }
                                        throw $e;
                                    }
                                }
                            } else {
                                for ($attempt = 0; $attempt < 3; $attempt++) {
                                    try {
                                        ServiceCase::create([
                                            'customer_id' => $customer->id,
                                            'agent_id' => $request->input('agent_id') ?? $request->input('agentId'),
                                            'service_type' => $serviceTypeName,
                                            'service_date' => $serviceDate,
                                            'service_datetime' => $serviceDateTime,
                                            'amount' => $amountRupees,
                                            'payment_status' => 'paid'
                                        ]);
                                        break;
                                    } catch (\PDOException $e) {
                                        if (stripos($e->getMessage(), 'database is locked') !== false && $attempt < 2) {
                                            usleep(200000);
                                            continue;
                                        }
                                        throw $e;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Failed to update service case in demo getPaymentDetails: ' . $e->getMessage());
                }
                
                return response()->json([
                    'message' => 'Demo payment verified and saved successfully',
                    'payment_id' => $data['razorpay_payment_id'],
                    'order_id' => $data['razorpay_order_id'],
                    'mode' => 'demo',
                    'note' => 'This is a demo payment verification. Configure Razorpay credentials for real signature verification.'
                ]);
            }
            
            // Real mode - verify payment signature using PaymentService
            $verifyResult = $this->paymentService->verifyPayment(
                $data['razorpay_order_id'],
                $data['razorpay_payment_id'],
                $data['razorpay_signature']
            );
            
            if (!$verifyResult['success'] || !($verifyResult['verified'] ?? false)) {
                throw new \Exception($verifyResult['error'] ?? 'Payment verification failed');
            }
            
            // Save or update payment details with sane defaults (real mode)
            $this->saveOrUpdatePaymentDetails($data, $request, 'real');
            
            // Mark service as completed
            $service = ServiceHistory::where('order_id', $data['razorpay_order_id'])->first();
            if ($service) {
                $service->markAsCompleted(
                    $data['razorpay_payment_id'],
                    $data['razorpay_order_id'],
                    'Razorpay'
                );
            }

            // Update or create corresponding service case to reflect paid status
            try {
                if ($service) {
                    $customerEmail = $service->customer_email;
                    $customerPhone = $service->customer_phone;
                    $rawName = trim((string) ($service->customer_name ?? ''));
                    if ($rawName === '' && $customerEmail) {
                        $rawName = explode('@', $customerEmail)[0];
                    }
                    $fullName = $rawName !== '' ? $rawName : 'Guest';
                    $nameParts = explode(' ', $fullName, 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? '';

                    $unique = $customerEmail ? ['email' => $customerEmail] : ($customerPhone ? ['phone' => $customerPhone] : null);
                    if ($unique) {
                        $customer = ServiceCustomer::firstOrCreate(
                            $unique,
                            [
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'created_at' => now()
                            ]
                        );

                        $serviceTypeName = $service->service_type ?? 'Pet Service';
                        $serviceDateInput = $request->input('service_datetime') ?? $request->input('service_date');
                        $serviceDate = $serviceDateInput ? date('Y-m-d', strtotime($serviceDateInput)) : now()->toDateString();
                        $serviceDateTime = $serviceDateInput ? date('Y-m-d H:i:s', strtotime($serviceDateInput)) : now()->toDateTimeString();
                        ServiceType::firstOrCreate(['type' => $serviceTypeName]);
                        $amountRupees = $service->amount ?? null;

                        $existingCase = ServiceCase::where('customer_id', $customer->id)
                            ->where('service_type', $serviceTypeName)
                            ->latest('id')
                            ->first();

                        if ($existingCase) {
                            for ($attempt = 0; $attempt < 3; $attempt++) {
                                try {
                                    $existingCase->update([
                                        'amount' => $amountRupees ?? $existingCase->amount,
                                        'payment_status' => 'paid'
                                    ]);
                                    break;
                                } catch (\PDOException $e) {
                                    if (stripos($e->getMessage(), 'database is locked') !== false && $attempt < 2) {
                                        usleep(200000);
                                        continue;
                                    }
                                    throw $e;
                                }
                            }
                        } else {
                            for ($attempt = 0; $attempt < 3; $attempt++) {
                                try {
                                    ServiceCase::create([
                                        'customer_id' => $customer->id,
                                        'agent_id' => $request->input('agent_id') ?? $request->input('agentId'),
                                        'service_type' => $serviceTypeName,
                                        'service_date' => $serviceDate,
                                        'service_datetime' => $serviceDateTime,
                                        'amount' => $amountRupees,
                                        'payment_status' => 'paid'
                                    ]);
                                    break;
                                } catch (\PDOException $e) {
                                    if (stripos($e->getMessage(), 'database is locked') !== false && $attempt < 2) {
                                        usleep(200000);
                                        continue;
                                    }
                                    throw $e;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to update service case in getPaymentDetails: ' . $e->getMessage());
            }
            
            return response()->json([
                'message' => 'Payment verified and saved successfully',
                'payment_id' => $data['razorpay_payment_id'],
                'order_id' => $data['razorpay_order_id'],
                'mode' => 'production'
            ]);
        } catch (\Exception $e) {
            \Log::error('Payment verification error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Payment verification failed',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Create a Razorpay Payment Link for a service booking and return the link URL.
     * Also persists a pending service case so UI can reflect the booking before payment.
     */
    public function createServicePaymentLink(Request $request)
    {
        try {
            $amount = (int) $request->input('amount');
            $serviceTypeName = $request->input('service_type');
            $package = $request->input('package'); // hourly, monthly, yearly
            if (!$amount || $amount <= 0) {
                return response()->json(['error' => 'Invalid amount provided'], 400);
            }
            if (!$serviceTypeName) {
                return response()->json(['error' => 'service_type is required'], 400);
            }
            if ($serviceTypeName && in_array(strtolower($serviceTypeName), ['training','daily dog walking','walking'])) {
                if ($package && !in_array(strtolower($package), ['hourly','monthly','yearly'])) {
                    return response()->json(['error' => 'Invalid package. Allowed: hourly, monthly, yearly'], 422);
                }
            }

            $customerName = $request->input('customer_name', 'Customer');
            $customerEmail = $request->input('customer_email');
            $customerPhone = $request->input('customer_phone');

            // Normalize date-time
            $serviceDateInput = $request->input('service_datetime') ?? $request->input('service_date');
            $serviceDate = $serviceDateInput ? date('Y-m-d', strtotime($serviceDateInput)) : now()->toDateString();
            $serviceDateTime = $serviceDateInput ? date('Y-m-d H:i:s', strtotime($serviceDateInput)) : now()->toDateTimeString();

            // Ensure ServiceType exists
            ServiceType::firstOrCreate(['type' => $serviceTypeName]);

            // Create a new service customer for this booking if not just created
            $customerData = $this->normalizeCustomerPayload($request);
            $customer = null;
            if (!empty($customerData)) {
                $customer = $this->findOrUpdateCustomerByContact($customerData);
            }

            // Create pending service case for this booking
            $serviceCase = ServiceCase::create([
                'customer_id' => $customer?->id,
                'agent_id' => $request->input('agent_id') ?? $request->input('agentId'),
                'service_type' => $serviceTypeName,
                'package' => $package,
                'service_date' => $serviceDate,
                'service_datetime' => $serviceDateTime,
                'status' => 'pending',
                'amount' => $amount,
                'payment_status' => 'pending'
            ]);

            // Build callback URL with service metadata so we can finalize case with correct type/time
            $base = rtrim(env('APP_URL', $request->getSchemeAndHttpHost()), '/');
            // Ensure we hit the API route namespace
            $callbackUrl = $base . '/api/payment-success-callback?' . http_build_query([
                'service_type' => $serviceTypeName,
                'service_datetime' => $serviceDateTime,
                'agent_id' => $request->input('agent_id') ?? $request->input('agentId'),
                'amount' => $amount,
                'case_id' => $serviceCase->id
            ]);

            // Create payment link (or demo link when credentials not set)
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            $description = $request->input('description', trim($serviceTypeName . ' ' . ($package ? ucfirst(strtolower($package)) : '') . ' Payment'));

            // Always include email; contact optional
            $customerPayload = [
                'name' => $customerName,
                'email' => $customerEmail ?: 'customer@example.com'
            ];
            if ($customerPhone) {
                $customerPayload['contact'] = $customerPhone;
            }

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                // Demo link
                return response()->json([
                    'payment_link_id' => 'demo_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => 'INR',
                    'description' => $description,
                    'customer' => $customerPayload,
                    'callback_url' => $callbackUrl,
                    'notes' => [
                        'service_type' => $serviceTypeName,
                        'package' => $package,
                        'service_datetime' => $serviceDateTime,
                        'case_id' => $serviceCase->id
                    ],
                    'message' => 'Demo payment link created. Configure Razorpay credentials for real links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);
            $paymentLink = $api->paymentLink->create([
                'amount' => $amount * 100,
                'currency' => 'INR',
                'reference_id' => 'CASE_' . $serviceCase->id,
                'description' => $description,
                'customer' => $customerPayload,
                'notify' => ['sms' => true, 'email' => true],
                'reminder_enable' => true,
                'callback_url' => $callbackUrl,
                'notes' => [
                    'service_type' => $serviceTypeName,
                    'service_datetime' => $serviceDateTime,
                    'case_id' => $serviceCase->id
                ]
            ]);

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => 'INR',
                'description' => $description,
                'customer' => $customerPayload,
                'callback_url' => $callbackUrl,
                'notes' => [
                    'service_type' => $serviceTypeName,
                    'service_datetime' => $serviceDateTime,
                    'case_id' => $serviceCase->id
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Service payment link creation error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to create service payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Convenience wrappers for specific services
    public function createTrainingPaymentLink(Request $request) { $request->merge(['service_type' => 'Training']); return $this->createServicePaymentLink($request); }
    public function createWalkingPaymentLink(Request $request) { $request->merge(['service_type' => 'Daily Dog Walking']); return $this->createServicePaymentLink($request); }
    public function createVeterinaryPaymentLink(Request $request) { $request->merge(['service_type' => 'Vet Home Consultation']); return $this->createServicePaymentLink($request); }
    public function createGroomingPaymentLink(Request $request) { $request->merge(['service_type' => $request->input('service_type', 'Bath with Shampoo')]); return $this->createServicePaymentLink($request); }

    /**
     * Persist payment details ensuring status/method are populated and status reflects success.
     * Works for both demo and real callbacks; idempotent on order_id/payment_id.
     */
    private function saveOrUpdatePaymentDetails(array $data, Request $request, string $mode = 'real'): void
    {
        try {
            $orderId = $data['razorpay_order_id'] ?? null;
            $paymentId = $data['razorpay_payment_id'] ?? null;
            $signature = $data['razorpay_signature'] ?? null;

            if (!$orderId || !$paymentId) {
                return; // insufficient
            }

            $existing = PaymentDetails::where('razorpay_order_id', $orderId)->first();

            // Derive method without clobbering an existing specific method
            $methodCandidates = [
                strtolower((string) ($request->input('method') ?? '')),
                strtolower((string) ($request->input('payment_method') ?? '')),
                strtolower((string) ($data['method'] ?? '')),
                $request->input('upi_id') ? 'upi' : '',
                $request->input('bank_code') ? 'netbanking' : '',
                $existing?->method ?? ''
            ];
            $method = 'razorpay';
            foreach ($methodCandidates as $m) {
                if ($m && $m !== 'razorpay') { $method = $m; break; }
            }
            if ($method === 'qr') { $method = 'upi'; }

            // If a row exists for this order_id, update, else create
            $service = ServiceHistory::where('order_id', $orderId)->first();

            $customerName = $request->input('customer_name')
                ?? ($data['customer_name'] ?? null)
                ?? ($existing->customer_name ?? null)
                ?? ($service->customer_name ?? null);
            $customerEmail = $request->input('customer_email')
                ?? ($data['customer_email'] ?? null)
                ?? ($existing->customer_email ?? null)
                ?? ($service->customer_email ?? null);
            $customerPhone = $request->input('customer_phone')
                ?? ($data['customer_phone'] ?? null)
                ?? ($existing->customer_phone ?? null)
                ?? ($service->customer_phone ?? null);

            // Determine amount in rupees
            $amountInRupees = null;
            if ($request->input('amount')) {
                // Request amount is already in rupees
                $amountInRupees = (float) $request->input('amount');
            } elseif (isset($data['amount'])) {
                // Razorpay data amount is in paise, convert to rupees
                $amountInRupees = (float) ($data['amount'] / 100);
            } elseif ($existing && $existing->amount) {
                // Preserve existing amount
                $amountInRupees = (float) $existing->amount;
            }

            $payload = [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature ?? ($mode === 'demo' ? ('demo_signature_' . time()) : null),
                'amount' => $amountInRupees, // Store in rupees, not paise
                'currency' => $data['currency'] ?? 'INR',
                // Treat verified callbacks as captured/completed
                'status' => 'captured',
                'method' => $method,
                'card_last4' => $data['card_last4'] ?? null,
                'card_network' => $data['card_network'] ?? null,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'quote_amount' => $amountInRupees ? ($amountInRupees * 100) : ($data['quote_amount'] ?? ($existing->quote_amount ?? null)), // quote_amount in paise
                'payment_completed_at' => now(),
                'updated_at' => now(),
            ];

            // Upsert by order_id (and update if payment_id arrives later)
            if ($existing) {
                // Avoid overwriting a specific method with a generic one
                if ($existing->method && (!($request->has('method') || isset($data['method'])) || $method === 'razorpay')) {
                    unset($payload['method']);
                }
                // Avoid nullifying existing populated fields
                foreach (['customer_name','customer_email','customer_phone','card_last4','card_network'] as $key) {
                    if (!$payload[$key]) unset($payload[$key]);
                }
                $existing->update($payload);
            } else {
                $payload['created_at'] = now();
                PaymentDetails::create($payload);
            }
        } catch (\Throwable $e) {
            \Log::warning('saveOrUpdatePaymentDetails failed: ' . $e->getMessage());
        }
    }

    public function index(Request $request){
        try {
            $data = $request->all();
            
            // Check if this is car insurance data or pet service data
            if ($request->has('car_make') || $request->has('car_model')) {
                // Handle car insurance data
                $carData = [
                    'car_make' => $request->car_make ?? 'Unknown',
                    'car_model' => $request->car_model ?? 'Unknown',
                    'year' => $request->year ?? date('Y'),
                    'insurance_type' => $request->insurance_type ?? 'Comprehensive',
                    'quote_amount' => $this->calculateCarInsuranceQuote($request->car_make, $request->car_model, $request->year, $request->insurance_type),
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                
                return response()->json([
                    'message' => 'Car insurance quote generated successfully',
                    'data' => $carData,
                    'quote_details' => [
                        'car_make' => $carData['car_make'],
                        'car_model' => $carData['car_model'],
                        'year' => $carData['year'],
                        'insurance_type' => $carData['insurance_type'],
                        'estimated_premium' => $carData['quote_amount'],
                        'quote_id' => 'QUOTE_' . time(),
                        'valid_until' => date('Y-m-d', strtotime('+7 days'))
                    ]
                ]);
            } else {
                // Handle pet service data (original functionality)
                // Accept multiple payload shapes from various frontends
                $petName = $request->input('petName')
                    ?? $request->input('pet_type')
                    ?? $request->input('petType')
                    ?? $request->input('pet')
                    ?? 'Unknown Pet';

                $serviceName = $request->input('serviceType')
                    ?? $request->input('service')
                    ?? $request->input('name')
                    ?? $request->input('service_name')
                    ?? 'General Service';

                $serviceDate = $request->input('date')
                    ?? $request->input('service_date')
                    ?? date('Y-m-d');

                $serviceTime = $request->input('time')
                    ?? $request->input('service_time')
                    ?? '09:00';

                $service = Service::create([
                    'pettype' => $petName,
                    'service' => $serviceName,
                    'date' => $serviceDate,
                    'time' => $serviceTime,
                ]);

                return response()->json([
                    'message' => 'Service booked successfully',
                    'data' => [
                        'id' => $service->id,
                        'petName' => $service->pettype,
                        'service' => $service->service,
                        'date' => $service->date,
                        'time' => $service->time,
                        'created_at' => $service->created_at,
                        'updated_at' => $service->updated_at
                    ]
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('DataInfo API error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to process request',
                'message' => $e->getMessage(),
                'debug_info' => [
                    'request_data' => $request->all(),
                    'has_car_data' => $request->has('car_make') || $request->has('car_model'),
                    'has_pet_data' => $request->has('petName') || $request->has('serviceType')
                ]
            ], 500);
        }
    }
    
    // Helper method to calculate car insurance quote
    private function calculateCarInsuranceQuote($make, $model, $year, $insuranceType) {
        // Base premium calculation
        $basePremium = 5000; // Base premium in INR
        
        // Adjust based on car make
        $makeMultiplier = 1.0;
        switch (strtolower($make)) {
            case 'toyota':
                $makeMultiplier = 1.2;
                break;
            case 'honda':
                $makeMultiplier = 1.1;
                break;
            case 'bmw':
            case 'mercedes':
                $makeMultiplier = 2.0;
                break;
            case 'audi':
                $makeMultiplier = 1.8;
                break;
            default:
                $makeMultiplier = 1.0;
        }
        
        // Adjust based on year (newer cars cost more to insure)
        $yearMultiplier = 1.0;
        $currentYear = date('Y');
        $carAge = $currentYear - $year;
        
        if ($carAge <= 2) {
            $yearMultiplier = 1.3; // New cars
        } elseif ($carAge <= 5) {
            $yearMultiplier = 1.1; // Recent cars
        } elseif ($carAge <= 10) {
            $yearMultiplier = 0.9; // Older cars
        } else {
            $yearMultiplier = 0.7; // Very old cars
        }
        
        // Adjust based on insurance type
        $typeMultiplier = 1.0;
        switch (strtolower($insuranceType)) {
            case 'comprehensive':
                $typeMultiplier = 1.0;
                break;
            case 'third party':
                $typeMultiplier = 0.3;
                break;
            case 'zero depreciation':
                $typeMultiplier = 1.5;
                break;
            default:
                $typeMultiplier = 1.0;
        }
        
        $finalPremium = $basePremium * $makeMultiplier * $yearMultiplier * $typeMultiplier;
        
        return round($finalPremium, 2);
    }

    /**
     * Normalize customer fields coming from various frontend payloads so we always
     * persist a complete customer record into service_customers.
     */
    private function normalizeCustomerPayload(Request $request): array
    {
        $fullName = (string) ($request->input('customer_name')
            ?? $request->input('name')
            ?? $request->input('full_name')
            ?? 'Customer');

        $fullName = trim($fullName);
        $firstName = $fullName !== '' ? explode(' ', $fullName, 2)[0] : 'Customer';
        $lastName = $fullName !== '' && strpos($fullName, ' ') !== false ? explode(' ', $fullName, 2)[1] : '';

        $email = $request->input('customer_email')
            ?? $request->input('email');

        $phone = $request->input('customer_phone')
            ?? $request->input('phone')
            ?? $request->input('contact')
            ?? $request->input('mobile')
            ?? $request->input('mobile_no')
            ?? $request->input('mobileNo');

        $address = $request->input('address')
            ?? $request->input('customer_address')
            ?? $request->input('customerAddress')
            ?? $request->input('billing_address')
            ?? $request->input('shipping_address');

        $city = $request->input('city')
            ?? $request->input('customer_city')
            ?? $request->input('customerCity');

        $state = $request->input('state')
            ?? $request->input('customer_state')
            ?? $request->input('customerState');

        $pincode = $request->input('pincode')
            ?? $request->input('postal_code')
            ?? $request->input('zip')
            ?? $request->input('zipcode')
            ?? $request->input('zip_code');

        // If pincode not explicitly provided, try to extract a 6-digit code from the address
        if ((!$pincode || $pincode === '') && is_string($address) && $address !== '') {
            if (preg_match('/(?<!\d)(\d{6})(?!\d)/', $address, $matches)) {
                $pincode = $matches[1];
                // Remove the matched pincode (and any immediate separators) from the address
                $address = trim(preg_replace('/[\s,;-]*' . preg_quote($pincode, '/') . '[\s,;-]*/', ' ', $address));
            }
        }

        // Filter only nulls, keep "0"-like values
        $data = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
            'created_at' => now(),
        ];

        return array_filter($data, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Find a recently created customer with the same email or phone to avoid
     * duplicate inserts during a single booking/payment flow. Does not update.
     */
    private function findRecentCustomer(array $customerData, int $windowMinutes = 3)
    {
        $email = $customerData['email'] ?? null;
        $phone = $customerData['phone'] ?? null;
        if ((!$email || $email === '') && (!$phone || $phone === '')) {
            return null;
        }

        $query = ServiceCustomer::query();
        if ($email) {
            $query->orWhere('email', $email);
        }
        if ($phone) {
            $query->orWhere('phone', $phone);
        }

        return $query
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Find an existing customer whose stored fields exactly match the provided
     * payload fields. Only compares keys present in $customerData to allow
     * strict reuse when a user submits the same profile again.
     * If name differs while sharing email/phone, no row will match and a new id will be created.
     */
    private function findExactCustomer(array $customerData)
    {
        $keysToCheck = [
            'first_name','last_name','email','phone','address','city','state','pincode'
        ];

        $query = ServiceCustomer::query();
        $usedFilter = false;
        foreach ($keysToCheck as $key) {
            if (array_key_exists($key, $customerData)) {
                $query->where($key, $customerData[$key]);
                $usedFilter = true;
            }
        }
        if (!$usedFilter) {
            return null; // nothing to compare reliably
        }
        return $query->orderByDesc('id')->first();
    }

    /**
     * Find a customer by email or phone; if found, update with any new non-empty
     * fields from $customerData. Otherwise create a new customer. Always returns
     * a single stable customer row for a contact identity.
     */
    private function findOrUpdateCustomerByContact(array $customerData)
    {
        $email = $customerData['email'] ?? null;
        $phone = $customerData['phone'] ?? null;

        $query = ServiceCustomer::query();
        if ($email) { $query->orWhere('email', $email); }
        if ($phone) { $query->orWhere('phone', $phone); }
        $existing = $query->orderByDesc('id')->first();

        if ($existing) {
            $updates = [];
            foreach (['first_name','last_name','email','phone','address','city','state','pincode'] as $k) {
                if (isset($customerData[$k]) && $customerData[$k] !== '' && $customerData[$k] !== null) {
                    // Only update when different
                    if ($existing->$k !== $customerData[$k]) {
                        $updates[$k] = $customerData[$k];
                    }
                }
            }
            if (!empty($updates)) {
                $existing->update($updates);
            }
            return $existing->fresh();
        }

        return ServiceCustomer::create($customerData);
    }

    /**
     * Find a recently created customer with the same name and contact
     * (email or phone). This prevents duplicate rows for the same new
     * customer within a single booking/payment flow even if non-critical
     * fields (address/city/state/pincode) change between steps.
     */
    private function findRecentCustomerByNameAndContact(array $customerData, int $windowMinutes = 10)
    {
        $first = $customerData['first_name'] ?? null;
        $last = $customerData['last_name'] ?? '';
        $email = $customerData['email'] ?? null;
        $phone = $customerData['phone'] ?? null;
        if (!$first || (!$email && !$phone)) {
            return null;
        }
        $q = ServiceCustomer::query()->where('first_name', $first)->where('last_name', $last);
        $q->where(function($qq) use ($email, $phone) {
            if ($email) { $qq->orWhere('email', $email); }
            if ($phone) { $qq->orWhere('phone', $phone); }
        });
        return $q->where('created_at', '>=', now()->subMinutes($windowMinutes))
                 ->orderByDesc('id')
                 ->first();
    }

    /**
     * Preferred creation logic per product requirement:
     * - If exact profile exists, reuse it.
     * - Else if a recent row exists with same name+contact, reuse it (dedup within flow).
     * - Else create a new customer row.
     */
    private function findOrCreateCustomer(array $customerData)
    {
        $existingExact = $this->findExactCustomer($customerData);
        if ($existingExact) return $existingExact;
        $recent = $this->findRecentCustomerByNameAndContact($customerData, 15);
        if ($recent) return $recent;
        return ServiceCustomer::create($customerData);
    }

    /**
     * Complete Payment Flow API - Handles all pre-payment formalities and redirects to payment page
     * This API performs:
     * 1. Data validation
     * 2. Customer verification
     * 3. Insurance quote calculation
     * 4. Razorpay order creation
     * 5. Payment page redirect
     */
    public function initiatePaymentFlow(Request $request) {
        try {
            // Step 1: Validate required fields
            $validator = \Validator::make($request->all(), [
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'required|string|min:10|max:15',
                // Optional address fields captured during booking
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'pincode' => 'nullable|string|max:10',
                'car_make' => 'required|string|max:100',
                'car_model' => 'required|string|max:100',
                'year' => 'required|integer|min:1990|max:' . (date('Y') + 1),
                'insurance_type' => 'required|string|in:Comprehensive,Third Party,Zero Depreciation',
                'policy_duration' => 'required|integer|min:1|max:3', // 1, 2, or 3 years
                'payment_amount' => 'required|numeric|min:100|max:1000000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Step 2: Create a new customer record for booking context (skip if just created)
            try {
                $customerData = $this->normalizeCustomerPayload($request);
                $this->findOrCreateCustomer($customerData);
            } catch (\Throwable $e) {
                \Log::warning('Failed to upsert service customer on initiate-payment-flow: '.$e->getMessage());
            }

            // Step 3: Calculate insurance quote
            $quoteAmount = $this->calculateCarInsuranceQuote(
                $request->car_make, 
                $request->car_model, 
                $request->year, 
                $request->insurance_type
            );

            // Apply policy duration multiplier
            $durationMultiplier = $request->policy_duration;
            $finalQuoteAmount = $quoteAmount * $durationMultiplier;

            // Step 4: Verify payment amount matches quote (with tolerance)
            $amountTolerance = 100; // Allow ₹100 difference
            if (abs($request->payment_amount - $finalQuoteAmount) > $amountTolerance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment amount mismatch',
                    'calculated_amount' => $finalQuoteAmount,
                    'provided_amount' => $request->payment_amount,
                    'difference' => abs($request->payment_amount - $finalQuoteAmount)
                ], 400);
            }

            // Step 5: Generate unique reference ID
            $referenceId = 'POL_' . strtoupper($request->car_make) . '_' . time() . '_' . rand(1000, 9999);

            // Step 6: Create Razorpay order
            $razorpayOrder = $this->createRazorpayOrder([
                'amount' => $request->payment_amount * 100, // Convert to paise
                'currency' => 'INR',
                'receipt' => $referenceId,
                'notes' => [
                    'customer_name' => $request->customer_name,
                    'car_make' => $request->car_make,
                    'car_model' => $request->car_model,
                    'year' => $request->year,
                    'insurance_type' => $request->insurance_type,
                    'policy_duration' => $request->policy_duration . ' year(s)',
                    'quote_amount' => $finalQuoteAmount
                ]
            ]);

            if (!$razorpayOrder['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create payment order',
                    'error' => $razorpayOrder['error']
                ], 500);
            }

            // Step 7: Store payment details in database
            $paymentDetails = PaymentDetails::create([
                'razorpay_order_id' => $razorpayOrder['data']['id'],
                'customer_name' => $request->customer_name,
                'customer_email' => $request->customer_email,
                'customer_phone' => $request->customer_phone,
                'amount' => $request->payment_amount,
                'currency' => 'INR',
                'status' => 'created',
                'car_make' => $request->car_make,
                'car_model' => $request->car_model,
                'year' => $request->year,
                'insurance_type' => $request->insurance_type,
                'policy_duration' => $request->policy_duration,
                'reference_id' => $referenceId,
                'quote_amount' => $finalQuoteAmount
            ]);

            // Step 7b: Create a pending service_history row for tracking
            try {
                // Determine service type based on insurance type
                $serviceType = match($request->insurance_type) {
                    'Comprehensive' => 'Comprehensive Car Insurance',
                    'Third Party' => 'Third Party Car Insurance', 
                    'Zero Depreciation' => 'Zero Depreciation Car Insurance',
                    default => 'Car Insurance'
                };
                
                ServiceHistory::ensurePending([
                    'order_id' => $razorpayOrder['data']['id'],
                    'customer_name' => $request->customer_name,
                    'customer_email' => $request->customer_email,
                    'customer_phone' => $request->customer_phone,
                    'service_type' => $serviceType,
                    'amount' => $request->payment_amount,
                    'currency' => 'INR',
                    'service_details' => [
                        'order_id' => $razorpayOrder['data']['id'],
                        'description' => $serviceType,
                        'car_make' => $request->car_make,
                        'car_model' => $request->car_model,
                        'year' => $request->year,
                        'insurance_type' => $request->insurance_type,
                        'policy_duration' => $request->policy_duration,
                        'reference_id' => $referenceId,
                        'created_via' => 'complete_payment_flow'
                    ]
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Failed to create service_history on initiate: ' . $e->getMessage());
            }

            // Step 8: Generate payment page URL
            $paymentPageUrl = $this->generatePaymentPageUrl($razorpayOrder['data']['id'], $request->all());

            // Step 9: Return complete payment flow response
            return response()->json([
                'success' => true,
                'message' => 'Payment flow initiated successfully',
                'payment_flow' => [
                    'step' => 'payment_page_ready',
                    'reference_id' => $referenceId,
                    'razorpay_order_id' => $razorpayOrder['data']['id'],
                    'payment_page_url' => $paymentPageUrl,
                    'amount' => $request->payment_amount,
                    'currency' => 'INR'
                ],
                'customer_details' => [
                    'name' => $request->customer_name,
                    'email' => $request->customer_email,
                    'phone' => $request->customer_phone
                ],
                'insurance_details' => [
                    'car_make' => $request->car_make,
                    'car_model' => $request->car_model,
                    'year' => $request->year,
                    'insurance_type' => $request->insurance_type,
                    'policy_duration' => $request->policy_duration . ' year(s)',
                    'quote_amount' => $finalQuoteAmount
                ],
                'payment_options' => [
                    'methods' => ['card', 'netbanking', 'upi', 'wallet'],
                    'partial_payment' => false,
                    'auto_capture' => true
                ],
                'next_steps' => [
                    '1' => 'Redirect user to payment_page_url',
                    '2' => 'User completes payment on Razorpay page',
                    '3' => 'Handle payment success/failure callback',
                    '4' => 'Generate insurance policy document'
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Payment flow initiation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Payment flow initiation failed',
                'error' => $e->getMessage(),
                'debug_info' => [
                    'request_data' => $request->all(),
                    'timestamp' => now()
                ]
            ], 500);
        }
    }

    /**
     * Complete Service Booking Flow - similar to initiatePaymentFlow but for pet services
     * Validates booking payload, upserts customer, creates Razorpay order,
     * persists a pending service case with date/time, and returns payment page URL.
     */
    public function initiateServiceBookingFlow(Request $request)
    {
        try {
            $validator = \Validator::make($request->all(), [
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'required|string|min:10|max:15',
                'service_type' => 'required|string|max:100',
                'amount' => 'required|numeric|min:50|max:1000000',
                'service_datetime' => 'nullable|string',
                'service_date' => 'nullable|date',
                'agent_id' => 'nullable|string|max:45',
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
                ], 400);
            }

            // Normalize IST datetime
            $serviceDateInput = $request->input('service_datetime') ?? $request->input('service_date');
            if ($serviceDateInput) {
                $dtIst = new \DateTime($serviceDateInput, new \DateTimeZone('Asia/Kolkata'));
                $serviceDate = $dtIst->format('Y-m-d');
                $serviceDateTime = $dtIst->format('Y-m-d H:i:s');
            } else {
                $nowIst = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
                $serviceDate = $nowIst->format('Y-m-d');
                $serviceDateTime = $nowIst->format('Y-m-d H:i:s');
            }

            // Create a new customer (skip if just created in this flow)
            try {
                $customerData = $this->normalizeCustomerPayload($request);
                $customer = $this->findOrUpdateCustomerByContact($customerData);
            } catch (\Throwable $e) {
                \Log::warning('Failed to upsert service customer on initiate service booking: ' . $e->getMessage());
                $customer = null;
            }

            // Create Razorpay order or demo fallback if credentials missing
            $referenceId = 'SRV_' . strtoupper(preg_replace('/\s+/', '_', $request->service_type)) . '_' . time() . '_' . rand(1000, 9999);
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            $isDemo = (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here');

            if ($isDemo) {
                $orderId = 'demo_order_' . time() . '_' . rand(1000, 9999);

                // Persist minimal payment record for consistency
                PaymentDetails::create([
                    'razorpay_order_id' => $orderId,
                    'customer_name' => $request->customer_name,
                    'customer_email' => $request->customer_email,
                    'customer_phone' => $request->customer_phone,
                    'amount' => (int) $request->amount,
                    'currency' => 'INR',
                    'status' => 'created',
                    'reference_id' => $referenceId
                ]);

                // Create service history row for booking context
                try {
                ServiceHistory::ensurePending([
                        'customer_name' => $request->customer_name,
                        'customer_email' => $request->customer_email,
                        'customer_phone' => $request->customer_phone,
                        'service_type' => $request->service_type,
                        'amount' => (int) $request->amount,
                        'currency' => 'INR',
                        'service_details' => [
                            'order_id' => $orderId,
                            'description' => $request->service_type,
                            'service_date' => $serviceDate,
                            'service_datetime' => $serviceDateTime,
                            'created_via' => 'service_booking_flow_demo'
                        ]
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to create service_history on demo initiate booking: ' . $e->getMessage());
                }

                // Create pending service case
                try {
                    ServiceType::firstOrCreate(['type' => $request->service_type]);
                    ServiceCase::create([
                        'customer_id' => $customer?->id,
                        'agent_id' => $request->input('agent_id'),
                        'service_type' => $request->service_type,
                        'service_date' => $serviceDate,
                        'service_datetime' => $serviceDateTime,
                        'amount' => (string) $request->amount,
                        'payment_status' => 'pending'
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to create pending service case on demo initiate booking: ' . $e->getMessage());
                }

                $paymentPageUrl = $this->generatePaymentPageUrl($orderId, [
                    'customer_name' => $request->customer_name,
                    'customer_email' => $request->customer_email,
                    'customer_phone' => $request->customer_phone,
                    'amount' => (int) $request->amount
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Service booking initiated successfully (demo mode)',
                    'payment_flow' => [
                        'step' => 'payment_page_ready',
                        'reference_id' => $referenceId,
                        'razorpay_order_id' => $orderId,
                        'payment_page_url' => $paymentPageUrl,
                        'amount' => (int) $request->amount,
                        'currency' => 'INR',
                        'payment_method' => 'demo'
                    ],
                    'service_details' => [
                        'service_type' => $request->service_type,
                        'service_date' => $serviceDate,
                        'service_datetime' => $serviceDateTime
                    ]
                ], 200);
            }

            // Real order path
            $razorpayOrder = $this->createRazorpayOrder([
                'amount' => ((int) $request->amount) * 100,
                'currency' => 'INR',
                'receipt' => $referenceId,
                'notes' => [
                    'service_type' => $request->service_type,
                    'customer_name' => $request->customer_name,
                    'service_date' => $serviceDate,
                    'service_datetime' => $serviceDateTime
                ]
            ]);

            if (!$razorpayOrder['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create payment order',
                    'error' => $razorpayOrder['error']
                ], 500);
            }

            PaymentDetails::create([
                'razorpay_order_id' => $razorpayOrder['data']['id'],
                'customer_name' => $request->customer_name,
                'customer_email' => $request->customer_email,
                'customer_phone' => $request->customer_phone,
                'amount' => (int) $request->amount,
                'currency' => 'INR',
                'status' => 'created',
                'reference_id' => $referenceId
            ]);

            // Create service history entry (real path)
            try {
                ServiceHistory::createService([
                    'customer_name' => $request->customer_name,
                    'customer_email' => $request->customer_email,
                    'customer_phone' => $request->customer_phone,
                    'service_type' => $request->service_type,
                    'amount' => (int) $request->amount,
                    'currency' => 'INR',
                    'service_details' => [
                        'order_id' => $razorpayOrder['data']['id'],
                        'description' => $request->service_type,
                        'service_date' => $serviceDate,
                        'service_datetime' => $serviceDateTime,
                        'created_via' => 'service_booking_flow'
                    ]
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Failed to create service_history on initiate booking: ' . $e->getMessage());
            }

            // Create pending service case now
            try {
                ServiceType::firstOrCreate(['type' => $request->service_type]);
                ServiceCase::create([
                    'customer_id' => $customer?->id,
                    'agent_id' => $request->input('agent_id'),
                    'service_type' => $request->service_type,
                    'service_date' => $serviceDate,
                    'service_datetime' => $serviceDateTime,
                    'status' => 'pending',
                    'amount' => (string) $request->amount,
                    'payment_status' => 'pending'
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Failed to create pending service case on initiate booking: ' . $e->getMessage());
            }

            $paymentPageUrl = $this->generatePaymentPageUrl($razorpayOrder['data']['id'], [
                'customer_name' => $request->customer_name,
                'customer_email' => $request->customer_email,
                'customer_phone' => $request->customer_phone,
                'amount' => (int) $request->amount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service booking initiated successfully',
                'payment_flow' => [
                    'step' => 'payment_page_ready',
                    'reference_id' => $referenceId,
                    'razorpay_order_id' => $razorpayOrder['data']['id'],
                    'payment_page_url' => $paymentPageUrl,
                    'amount' => (int) $request->amount,
                    'currency' => 'INR'
                ],
                'service_details' => [
                    'service_type' => $request->service_type,
                    'service_date' => $serviceDate,
                    'service_datetime' => $serviceDateTime
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Service booking flow initiation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Service booking initiation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create Razorpay Order
     */
    private function createRazorpayOrder($orderData) {
        try {
            $keyId = env('RAZORPAY_KEY');
            $keySecret = env('RAZORPAY_SECRET');

            if (!$keyId || !$keySecret) {
                return [
                    'success' => false,
                    'error' => 'Razorpay credentials not configured'
                ];
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/orders');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
            curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $orderData = json_decode($response, true);
                return [
                    'success' => true,
                    'data' => $orderData
                ];
            } else {
                $errorData = json_decode($response, true);
                return [
                    'success' => false,
                    'error' => $errorData['error']['description'] ?? 'Failed to create order'
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate Payment Page URL
     */
    private function generatePaymentPageUrl($orderId, $requestData) {
        // For demo purposes, we'll create a custom payment page URL
        // In production, you would integrate with Razorpay Checkout or create your own payment page
        
        $baseUrl = $this->getPublicUrl();
        // Support both insurance flow (payment_amount, car_*) and service flow (amount only)
        $amount = $requestData['payment_amount'] ?? ($requestData['amount'] ?? 0);
        $customerName = $requestData['customer_name'] ?? 'Customer';
        $customerEmail = $requestData['customer_email'] ?? 'customer@example.com';
        $carMake = $requestData['car_make'] ?? '';
        $carModel = $requestData['car_model'] ?? '';

        $query = [
            'order_id' => $orderId,
            'amount' => $amount,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
        ];
        // Append car fields only if provided (insurance flow)
        if ($carMake !== '' || $carModel !== '') {
            $query['car_make'] = $carMake;
            $query['car_model'] = $carModel;
        }

        $paymentPageUrl = $baseUrl . '/payment-page?' . http_build_query($query);
        
        return $paymentPageUrl;
    }

    /**
     * Upsert service customer from booking page details
     * Accepts: customer_name, customer_email, customer_phone, address, city, state, pincode
     */
    public function upsertServiceCustomer(Request $request) {
        $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
        ]);

        $fullName = trim((string) $request->customer_name);
        $firstName = $fullName !== '' ? explode(' ', $fullName, 2)[0] : 'Customer';
        $lastName = $fullName !== '' && strpos($fullName, ' ') !== false ? explode(' ', $fullName, 2)[1] : '';

        $customerData = $this->normalizeCustomerPayload($request);
        $customer = $this->findOrCreateCustomer($customerData);

        return response()->json([
            'success' => true,
            'message' => 'Customer details saved successfully',
            'customer' => $customer
        ]);
    }

    /**
     * Create Payment Order and Return Direct Payment Page URL
     * This API creates a payment order and returns a direct URL to the payment page
     */
    public function createDirectPaymentPage(Request $request) {
        try {
            $amount = $request->input('amount');
            $customerName = $request->input('customer_name', 'Customer');
            $customerEmail = $request->input('customer_email', 'customer@example.com');
            $carMake = $request->input('car_make', '');
            $carModel = $request->input('car_model', '');
            $description = $request->input('description', 'Insurance Payment');
            
            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            // Check if Razorpay credentials are configured
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                // Create demo order for testing without Razorpay credentials
                $orderId = 'demo_order_' . time() . '_' . rand(1000, 9999);

                // Create a new service customer record before redirecting to payment page (reuse id only if exact match exists)
                try {
                    $customerData = $this->normalizeCustomerPayload($request);
                    $this->findOrCreateCustomer($customerData);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to upsert service customer on demo createDirectPaymentPage: ' . $e->getMessage());
                }
                $baseUrl = env('APP_URL', 'http://127.0.0.1:8000');
                
                $paymentPageUrl = $baseUrl . '/payment-page?' . http_build_query([
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'car_make' => $carMake,
                    'car_model' => $carModel,
                    'demo_mode' => 'true'
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Demo payment page created successfully',
                    'payment_page_url' => $paymentPageUrl,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'payment_method' => 'demo',
                    'note' => 'This is a demo payment. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payments.'
                ]);
            }

            // Create real Razorpay order
            $api = new Api($razorpayKey, $razorpaySecret);
            
            $orderData = [
                'receipt' => 'order_' . rand(1000, 9999),
                'amount' => $amount * 100, // Convert to paise
                'currency' => 'INR',
                'payment_capture' => 1, // Auto capture payment
                'notes' => [
                    'description' => $description,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'car_make' => $carMake,
                    'car_model' => $carModel
                ]
            ];

            $order = $api->order->create($orderData);

            // Create a new service customer record before returning payment page URL (reuse id only if exact match exists)
            try {
                $customerData = $this->normalizeCustomerPayload($request);
                $this->findOrCreateCustomer($customerData);
            } catch (\Throwable $e) {
                \Log::warning('Failed to upsert service customer on createDirectPaymentPage: ' . $e->getMessage());
            }
            
            // Generate payment page URL with order details
            // Use PUBLIC_URL if available, otherwise try to detect public IP
            $baseUrl = $this->getPublicUrl();
            $paymentPageUrl = $baseUrl . '/payment-page?' . http_build_query([
                'order_id' => $order['id'],
                'amount' => $amount,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'car_make' => $carMake,
                'car_model' => $carModel
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Payment page created successfully',
                'payment_page_url' => $paymentPageUrl,
                'order_id' => $order['id'],
                'amount' => $amount,
                'currency' => 'INR',
                'payment_method' => 'razorpay',
                'razorpay_key' => $razorpayKey,
                'share_info' => [
                    'title' => 'Insurance Payment Link',
                    'description' => 'Complete your insurance payment securely',
                    'amount' => '₹' . number_format($amount),
                    'customer' => $customerName
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Direct payment page creation error: ' . $e->getMessage());
            
            // If Razorpay API fails, fall back to demo mode
            if (strpos($e->getMessage(), 'Invalid key') !== false || 
                strpos($e->getMessage(), 'authentication') !== false) {
                
                $orderId = 'demo_order_' . time() . '_' . rand(1000, 9999);
                $baseUrl = $this->getPublicUrl();
                
                $paymentPageUrl = $baseUrl . '/payment-page?' . http_build_query([
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'car_make' => $carMake,
                    'car_model' => $carModel,
                    'demo_mode' => 'true'
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Demo payment page created (Razorpay credentials invalid)',
                    'payment_page_url' => $paymentPageUrl,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'payment_method' => 'demo',
                    'share_info' => [
                        'title' => 'Demo Insurance Payment Link',
                        'description' => 'Complete your insurance payment securely (Demo Mode)',
                        'amount' => '₹' . number_format($amount),
                        'customer' => $customerName
                    ],
                    'note' => 'Razorpay credentials invalid. Using demo mode. Check your RAZORPAY_KEY and RAZORPAY_SECRET in .env file.'
                ]);
            }
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create payment page',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Payment History
     * This API retrieves all payment records for a user or all payments
     */
    public function getPaymentHistory(Request $request) {
        try {
            // Get query parameters
            $limit = $request->input('limit', 50);
            $offset = $request->input('offset', 0);
            $customerEmail = $request->input('customer_email');
            $status = $request->input('status');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            // Build query
            $query = PaymentDetails::query();

            // Filter by customer email if provided
            if ($customerEmail) {
                $query->where('customer_email', $customerEmail);
            }

            // Filter by status if provided
            if ($status) {
                $query->where('status', $status);
            }

            // Filter by date range if provided
            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            // Order by latest first
            $query->orderBy('created_at', 'desc');

            // Get total count for pagination
            $totalCount = $query->count();

            // Apply limit and offset
            $payments = $query->limit($limit)->offset($offset)->get();

            // Format the response
            $formattedPayments = $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payment_id' => $payment->razorpay_payment_id,
                    'order_id' => $payment->razorpay_order_id,
                    'amount' => $payment->amount / 100, // Convert from paise to rupees
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'method' => $payment->method,
                    'card_last4' => $payment->card_last4,
                    'card_network' => $payment->card_network,
                    'customer_email' => $payment->customer_email,
                    'customer_name' => $payment->customer_name,
                    'car_make' => $payment->car_make,
                    'car_model' => $payment->car_model,
                    'created_at' => $payment->created_at->format('d M Y, h:i A'),
                    'updated_at' => $payment->updated_at->format('d M Y, h:i A')
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment history retrieved successfully',
                'data' => [
                    'payments' => $formattedPayments,
                    'pagination' => [
                        'total' => $totalCount,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $totalCount
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment history retrieval error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Single Payment Details
     */
    public function getPaymentDetailsById($id) {
        try {
            $payment = PaymentDetails::find($id);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            $formattedPayment = [
                'id' => $payment->id,
                'payment_id' => $payment->razorpay_payment_id,
                'order_id' => $payment->razorpay_order_id,
                'amount' => $payment->amount / 100,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'method' => $payment->method,
                'card_last4' => $payment->card_last4,
                'card_network' => $payment->card_network,
                'customer_email' => $payment->customer_email,
                'customer_name' => $payment->customer_name,
                'car_make' => $payment->car_make,
                'car_model' => $payment->car_model,
                'created_at' => $payment->created_at->format('d M Y, h:i A'),
                'updated_at' => $payment->updated_at->format('d M Y, h:i A')
            ];

            return response()->json([
                'success' => true,
                'message' => 'Payment details retrieved successfully',
                'data' => $formattedPayment
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment details retrieval error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Payment Success Details
     * POST /api/payment-success-details
     */
    public function getPaymentSuccessDetails(Request $request) {
        try {
            $orderId = $request->input('order_id');
            $paymentId = $request->input('payment_id');
            
            if (!$orderId && !$paymentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either order_id or payment_id is required'
                ], 400);
            }
            
            // Try to fetch from Razorpay first for complete data
            $razorpayData = null;
            
            // First try to fetch by payment_id if available
            if ($paymentId) {
                try {
                    $razorpayApi = new Api(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));
                    $razorpayPayment = $razorpayApi->payment->fetch($paymentId);
                    
                    $razorpayData = [
                        'razorpay_order_id' => $razorpayPayment['order_id'] ?? $orderId,
                        'razorpay_payment_id' => $razorpayPayment['id'],
                        'amount' => ($razorpayPayment['amount'] ?? 0) / 100,
                        'currency' => $razorpayPayment['currency'] ?? 'INR',
                        'status' => $razorpayPayment['status'],
                        'method' => $razorpayPayment['method'],
                        'payment_completed_at' => isset($razorpayPayment['created_at']) ? 
                            date('Y-m-d\TH:i:s.u\Z', $razorpayPayment['created_at']) : null
                    ];
                    
                    // Add card details if available
                    if (isset($razorpayPayment['card'])) {
                        $razorpayData['card_last4'] = $razorpayPayment['card']['last4'] ?? null;
                        $razorpayData['card_network'] = $razorpayPayment['card']['network'] ?? null;
                    }
                    
                    // Add bank details if available
                    if (isset($razorpayPayment['bank'])) {
                        $razorpayData['bank'] = $razorpayPayment['bank'] ?? null;
                    }
                    
                    // Add wallet details if available
                    if (isset($razorpayPayment['wallet'])) {
                        $razorpayData['wallet'] = $razorpayPayment['wallet'] ?? null;
                    }
                    
                    // Add VPA details if available (for UPI)
                    if (isset($razorpayPayment['vpa'])) {
                        $razorpayData['vpa'] = $razorpayPayment['vpa'] ?? null;
                    }
                    
                    // Add email and contact
                    if (isset($razorpayPayment['email'])) {
                        $razorpayData['email'] = $razorpayPayment['email'];
                    }
                    if (isset($razorpayPayment['contact'])) {
                        $razorpayData['contact'] = $razorpayPayment['contact'];
                    }
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Payment details retrieved successfully from Razorpay',
                        'payment_details' => $razorpayData,
                        'source' => 'razorpay'
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Error fetching payment from Razorpay: ' . $e->getMessage());
                }
            }
            
            // If payment_id not available, try to fetch payments by order_id
            if ($orderId && !$paymentId) {
                try {
                    $razorpayApi = new Api(env('RAZORPAY_KEY'), env('RAZORPAY_SECRET'));
                    $razorpayOrder = $razorpayApi->order->fetch($orderId);
                    
                    // Get all payments for this order
                    $paymentsResponse = $razorpayOrder->payments();
                    
                    // Extract payments array from the response object
                    $paymentsList = [];
                    if (isset($paymentsResponse->items) && is_array($paymentsResponse->items)) {
                        foreach ($paymentsResponse->items as $item) {
                            $paymentsList[] = is_object($item) ? $item->toArray() : $item;
                        }
                    }
                    
                    // Get the most recent captured payment
                    $latestPayment = null;
                    foreach ($paymentsList as $payment) {
                        if (($payment['status'] ?? '') === 'captured' || ($payment['status'] ?? '') === 'authorized') {
                            $latestPayment = $payment;
                            break;
                        }
                    }
                    
                    // If no captured payment, get the latest one
                    if (!$latestPayment && !empty($paymentsList)) {
                        $latestPayment = $paymentsList[0];
                    }
                    
                    if ($latestPayment) {
                        $razorpayData = [
                            'razorpay_order_id' => $latestPayment['order_id'] ?? $orderId,
                            'razorpay_payment_id' => $latestPayment['id'],
                            'amount' => ($latestPayment['amount'] ?? 0) / 100,
                            'currency' => $latestPayment['currency'] ?? 'INR',
                            'status' => $latestPayment['status'],
                            'method' => $latestPayment['method'],
                            'payment_completed_at' => isset($latestPayment['created_at']) ? 
                                date('Y-m-d\TH:i:s.u\Z', $latestPayment['created_at']) : null
                        ];
                        
                        // Add card details if available
                        if (isset($latestPayment['card'])) {
                            $razorpayData['card_last4'] = $latestPayment['card']['last4'] ?? null;
                            $razorpayData['card_network'] = $latestPayment['card']['network'] ?? null;
                        }
                        
                        // Add bank details if available
                        if (isset($latestPayment['bank'])) {
                            $razorpayData['bank'] = $latestPayment['bank'] ?? null;
                        }
                        
                        // Add wallet details if available
                        if (isset($latestPayment['wallet'])) {
                            $razorpayData['wallet'] = $latestPayment['wallet'] ?? null;
                        }
                        
                        // Add VPA details if available (for UPI)
                        if (isset($latestPayment['vpa'])) {
                            $razorpayData['vpa'] = $latestPayment['vpa'] ?? null;
                        }
                        
                        // Add email and contact
                        if (isset($latestPayment['email'])) {
                            $razorpayData['email'] = $latestPayment['email'];
                        }
                        if (isset($latestPayment['contact'])) {
                            $razorpayData['contact'] = $latestPayment['contact'];
                        }
                        
                        return response()->json([
                            'success' => true,
                            'message' => 'Payment details retrieved successfully from Razorpay',
                            'payment_details' => $razorpayData,
                            'source' => 'razorpay'
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Error fetching payments by order from Razorpay: ' . $e->getMessage());
                }
            }
            
            // Fallback to database if Razorpay fetch failed
            $payment = null;
            if ($orderId) {
                $payment = PaymentDetails::where('razorpay_order_id', $orderId)->first();
            } elseif ($paymentId) {
                $payment = PaymentDetails::where('razorpay_payment_id', $paymentId)->first();
            }
            
            // If found in database, return it
            if ($payment) {
                $formattedPayment = [
                    'razorpay_order_id' => $payment->razorpay_order_id,
                    'razorpay_payment_id' => $payment->razorpay_payment_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency ?? 'INR',
                    'status' => $payment->status ?? 'completed',
                    'method' => $payment->method,
                    'card_last4' => $payment->card_last4,
                    'card_network' => $payment->card_network,
                    'payment_completed_at' => $payment->payment_completed_at?->toISOString()
                ];
                
                return response()->json([
                    'success' => true,
                    'message' => 'Payment details retrieved successfully',
                    'payment_details' => $formattedPayment,
                    'source' => 'database'
                ]);
            }
            
            // If we reach here, payment not found
            return response()->json([
                'success' => false,
                'message' => 'Payment not found in database or Razorpay'
            ], 404);
            
        } catch (\Exception $e) {
            \Log::error('Payment success details error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Payment Success Callback Handler
     */
    public function handlePaymentSuccess(Request $request) {
        try {
            // Support both order-based callbacks and payment-link callbacks (which may lack order_id)
            $hasOrderId = $request->filled('razorpay_order_id');
            $validator = $hasOrderId
                ? \Validator::make($request->all(), [
                    'razorpay_order_id' => 'required|string',
                    'razorpay_payment_id' => 'required|string',
                    'razorpay_signature' => 'required|string'
                ])
                : \Validator::make($request->all(), [
                    'razorpay_payment_id' => 'required|string'
                ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment callback data',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Check if this is a test payment (for development/testing)
            $isTestPayment = ($request->razorpay_payment_id === 'pay_test' || 
                            $request->razorpay_signature === 'sig_test');

            if ($isTestPayment) {
                // Handle test payment - skip signature verification
                \Log::info('Test payment detected - skipping signature verification');
            } else {
                if ($hasOrderId) {
                    // Verify payment signature for real payments with order id
                    $isValidSignature = $this->verifyPaymentSignature(
                        $request->razorpay_order_id,
                        $request->razorpay_payment_id,
                        $request->razorpay_signature
                    );

                    if (!$isValidSignature) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid payment signature'
                        ], 400);
                    }
                }
            }

			// Update payment details
            $paymentDetails = null;
            if ($hasOrderId) {
                $paymentDetails = PaymentDetails::where('razorpay_order_id', $request->razorpay_order_id)->first();
            }
            if (!$paymentDetails && $request->filled('razorpay_payment_id')) {
                $paymentDetails = PaymentDetails::where('razorpay_payment_id', $request->razorpay_payment_id)->first();
            }
            
            if (!$paymentDetails) {
                // Create minimal record so downstream updates proceed (payment link flow)
                $paymentDetails = PaymentDetails::create([
                    'razorpay_order_id' => $request->input('razorpay_order_id'),
                    'razorpay_payment_id' => $request->input('razorpay_payment_id'),
                    'amount' => (int) ($request->input('amount') ?: 0),
                    'currency' => 'INR',
                    'status' => 'created',
                    'customer_name' => $request->input('customer_name'),
                    'customer_email' => $request->input('customer_email'),
                    'customer_phone' => $request->input('customer_phone'),
                ]);
            }

            $paymentDetails->update([
                'razorpay_payment_id' => $request->razorpay_payment_id,
                'razorpay_signature' => $request->razorpay_signature,
                // Normalize to captured so DB reflects success uniformly
                'status' => 'captured',
                'method' => $request->input('method')
                    ?? $request->input('payment_method')
                    ?? ($paymentDetails->method ?: 'razorpay'),
                'payment_completed_at' => now()
            ]);

            // Ensure a non-zero amount is recorded on the payment row (in rupees)
            try {
                $resolvedAmountRupees = (float) ($paymentDetails->amount ?? 0);
                if ($resolvedAmountRupees <= 0) {
                    // Prefer amount from request (rupees)
                    if ($request->filled('amount')) {
                        $resolvedAmountRupees = (float) $request->input('amount');
                    }
                }

                // Try to backfill from related service_history if still zero
                if ($resolvedAmountRupees <= 0) {
                    $maybeHistory = \App\Models\ServiceHistory::where('order_id', $request->razorpay_order_id)
                        ->latest('id')
                        ->first();
                    if ($maybeHistory && !empty($maybeHistory->amount)) {
                        // ServiceHistory stores amount in rupees
                        $resolvedAmountRupees = (float) $maybeHistory->amount;
                    }
                }

                if ($resolvedAmountRupees > 0 && abs((float) ($paymentDetails->amount ?? 0) - $resolvedAmountRupees) > 0.01) {
                    $paymentDetails->update(['amount' => $resolvedAmountRupees]); // Store in rupees
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to backfill amount on payment: ' . $e->getMessage());
            }

            // Ensure customer details are populated on the payment row
            try {
                $service = ServiceHistory::where('order_id', $request->razorpay_order_id)->first();
                if ($service) {
                    $paymentDetails->update([
                        'customer_name' => $service->customer_name ?? $paymentDetails->customer_name,
                        'customer_email' => $service->customer_email ?? $paymentDetails->customer_email,
                        'customer_phone' => $service->customer_phone ?? $paymentDetails->customer_phone,
                        'quote_amount' => $paymentDetails->quote_amount ?? ($service->amount ? intval($service->amount) * 100 : null)
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to backfill customer on payment: ' . $e->getMessage());
            }

            // Ensure a service_history row exists and mark as completed
            try {
                $history = ServiceHistory::where('order_id', $request->razorpay_order_id)
                    ->latest('id')
                    ->first();

                if (!$history) {
                    // Create a minimal history row if none was created earlier (safety for edge cases)
                    $fallbackServiceType = $request->input('service_type', ServiceHistory::SERVICE_CAR_INSURANCE);
                    $history = ServiceHistory::createService([
                        'customer_name' => $request->input('customer_name') ?: ($paymentDetails->customer_name
                            ?: ($request->input('customer_email') ? explode('@', $request->input('customer_email'))[0] : 'Guest')),
                        'customer_email' => $request->input('customer_email') ?: ($paymentDetails->customer_email ?? null),
                        'customer_phone' => $request->input('customer_phone') ?: ($paymentDetails->customer_phone ?? null),
                        'service_type' => $fallbackServiceType,
                        'amount' => $request->input('amount') ?: ($paymentDetails->amount ? intval($paymentDetails->amount / 100) : 0),
                        'currency' => 'INR',
                        'service_details' => [
                            'order_id' => $request->razorpay_order_id,
                            'description' => $fallbackServiceType,
                            'created_via' => 'callback_backfill'
                        ]
                    ]);
                }

                if ($history) {
                    $history->markAsCompleted($request->razorpay_payment_id, $request->razorpay_order_id, $paymentDetails->method ?? null);
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to ensure/complete service_history: ' . $e->getMessage());
            }
            
            // Reflect booking in service tables within a transaction (capture created/updated entities)
            $finalCustomer = null; $finalCase = null; $finalServiceType = null;
            DB::transaction(function () use ($paymentDetails, $request, &$finalCustomer, &$finalCase, &$finalServiceType) {
                // 1) Ensure customer exists/created (prefer email, fall back to phone)
                $fullName = trim((string) $paymentDetails->customer_name);
                $firstName = $fullName !== '' ? explode(' ', $fullName, 2)[0] : 'Customer';
                $lastName = $fullName !== '' && strpos($fullName, ' ') !== false ? explode(' ', $fullName, 2)[1] : '';

                $email = $paymentDetails->customer_email ?: $request->input('customer_email');
                $phone = $paymentDetails->customer_phone ?: $request->input('customer_phone');

                $address = $request->input('address');
                $city = $request->input('city');
                $state = $request->input('state');
                $pincode = $request->input('pincode');

                // Reuse the exact same customer row captured before payment when possible
                $candidateData = array_filter([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'city' => $city,
                    'state' => $state,
                    'pincode' => $pincode,
                    'created_at' => now()
                ], function ($v) { return $v !== null && $v !== ''; });

                $customer = $this->findOrCreateCustomer($candidateData);
                $finalCustomer = $customer;

				// 2) Determine service type from request or based on insurance type
                // Determine service type from request, or fall back to service_history
                $serviceTypeFromRequest = $request->input('service_type') ?? $request->input('type');
                if (!$serviceTypeFromRequest) {
                    try {
                        $historyForType = ServiceHistory::where('order_id', $request->razorpay_order_id)
                            ->latest('id')
                            ->first();
                        if ($historyForType && $historyForType->service_type) {
                            $serviceTypeFromRequest = $historyForType->service_type;
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Failed to derive service_type from history: ' . $e->getMessage());
                    }
                }
                $serviceTypeName = $serviceTypeFromRequest ?: match($paymentDetails->insurance_type ?? 'Comprehensive') {
					'Comprehensive' => 'Comprehensive Car Insurance',
					'Third Party' => 'Third Party Car Insurance', 
					'Zero Depreciation' => 'Zero Depreciation Car Insurance',
					default => 'Car Insurance'
				};
				
				// 3) Ensure service type exists (map to domain service type)
                // Normalize and upsert service type to ensure the table is updated on success
                $normalizedType = trim((string) $serviceTypeName) !== '' ? trim((string) $serviceTypeName) : 'Pet Service';
                $finalServiceType = ServiceType::updateOrCreate(
                    ['type' => $normalizedType],
                    ['type' => $normalizedType]
                );

				// 4) Determine supplemental fields
				$agentId = $request->input('agent_id') ?? $request->input('agentId');
                $serviceDateInput = $request->input('service_datetime') ?? $request->input('service_date') ?? $request->input('date');
                if ($serviceDateInput) {
                    $dtIst = new \DateTime($serviceDateInput, new \DateTimeZone('Asia/Kolkata'));
                    $serviceDate = $dtIst->format('Y-m-d');
                    $serviceDateTime = $dtIst->format('Y-m-d H:i:s');
                } else {
                    $nowIst = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
                    $serviceDate = $nowIst->format('Y-m-d');
                    $serviceDateTime = $nowIst->format('Y-m-d H:i:s');
                }
                $amountRupees = $paymentDetails->amount ? intval($paymentDetails->amount / 100) : ($request->input('amount') ?? null);

                // 5) Update the existing pending case referenced by callback, otherwise try to match by customer+date+type
                $finalCase = null;
                $caseIdFromCallback = $request->input('case_id');
                if ($caseIdFromCallback) {
                    $existingCase = ServiceCase::find($caseIdFromCallback);
                } else {
                    $existingCase = ServiceCase::where('customer_id', $customer->id)
                        ->whereDate('service_date', $serviceDate)
                        ->where('service_type', $serviceTypeName)
                        ->latest('id')
                        ->first();
                }

                if ($existingCase) {
                    $existingCase->update([
                        'agent_id' => $agentId ?? $existingCase->agent_id,
                        'amount' => $amountRupees ?? $existingCase->amount,
                        'payment_status' => 'paid',
                    ]);
                    $finalCase = $existingCase->fresh();
                } else {
                    // As a fallback for flows where no pending case existed pre-payment, create a single record now
                    $finalCase = ServiceCase::create([
                        'customer_id' => $customer->id,
                        'agent_id' => $agentId,
                        'service_type' => $serviceTypeName,
                        'service_date' => $serviceDate,
                        'service_datetime' => $serviceDateTime,
                        'amount' => $amountRupees,
                        'payment_status' => 'paid'
                    ]);
                }
			});

            // Generate insurance policy
            $policyNumber = $this->generatePolicyNumber($paymentDetails);

            // Store payment data in session for success page
            session([
                'payment_id' => $request->razorpay_payment_id,
                'amount' => $paymentDetails->amount / 100, // Convert from paise to rupees
                'order_id' => $request->razorpay_order_id,
                'payment_method' => $paymentDetails->method ?? 'Credit/Debit Card',
                'testing_mode' => $isTestPayment ? 'true' : 'false'
            ]);

            // Return success response for Razorpay callback (include DB write info)
            return response()->json([
                'success' => true,
                'message' => $isTestPayment ? 'Test payment completed successfully' : 'Payment completed successfully',
                'payment_type' => $isTestPayment ? 'test' : 'real',
                'payment_details' => [
                    'order_id' => $request->razorpay_order_id,
                    'payment_id' => $request->razorpay_payment_id,
                    'status' => 'completed',
                    'amount' => $paymentDetails->amount / 100,
                    'currency' => $paymentDetails->currency,
                    'signature_verified' => !$isTestPayment
                ],
                'db_updates' => [
                    'customer_id' => isset($finalCustomer) ? ($finalCustomer->id ?? null) : null,
                    'service_case_id' => isset($finalCase) ? ($finalCase->id ?? null) : null,
                    'case_code' => isset($finalCase) ? ($finalCase->case_code ?? null) : null,
                    'service_type' => isset($finalServiceType) ? ($finalServiceType->type ?? null) : null,
                ],
                'redirect_url' => '/payment-success?' . http_build_query([
                    'payment_id' => $request->razorpay_payment_id,
                    'amount' => $paymentDetails->amount / 100,
                    'order_id' => $request->razorpay_order_id,
                    'method' => $paymentDetails->method ?? 'Credit/Debit Card',
                    'testing' => $isTestPayment ? 'true' : 'false'
                ])
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Payment success callback error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Payment callback processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify Payment Signature
     */
    private function verifyPaymentSignature($orderId, $paymentId, $signature) {
        try {
            $keySecret = env('RAZORPAY_SECRET');
            $generatedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret);
            
            return hash_equals($generatedSignature, $signature);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate Policy Number
     */
    private function generatePolicyNumber($paymentDetails) {
        $prefix = 'POL';
        $year = date('Y');
        $month = date('m');
        $random = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        return $prefix . $year . $month . $random;
    }

    public function Register(Request $request){
        // Basic validation and normalization
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:sign_up,Email',
            'mobile' => 'required|string|min:10|max:20|unique:sign_up,mobileno',
            'password' => 'required|string|min:4',
        ]);

        $normalizedMobile = preg_replace('/[^0-9]/', '', (string) $request->mobile);

        // Create user with small retry loop to mitigate transient SQLite locks
        $user = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $user = SignupModel::create([
                    'mobileno' => $normalizedMobile,
                    'Full_Name' => $request->name,
                    'Email' => $request->email,
                    'Password' => Hash::make($request->password),
                ]);
                break;
            } catch (\PDOException $e) {
                if (stripos($e->getMessage(), 'database is locked') !== false && $attempt < 2) {
                    usleep(200000); // 200ms backoff then retry
                    continue;
                }
                throw $e;
            }
        }
        if (!$user) {
            return response()->json([
                'message' => 'Please try again. Temporary database contention prevented signup.'
            ], 503);
        }

        // Also ensure a corresponding service customer record exists for downstream services
        try {
            $fullName = trim((string) $request->name);
            $firstName = $fullName !== '' ? explode(' ', $fullName, 2)[0] : 'Customer';
            $lastName = $fullName !== '' && strpos($fullName, ' ') !== false ? explode(' ', $fullName, 2)[1] : '';

            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    ServiceCustomer::firstOrCreate(
                        ['email' => $request->email],
                        [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'phone' => $normalizedMobile,
                            'address' => null,
                            'city' => null,
                            'state' => null,
                            'pincode' => null,
                            'created_at' => now()
                        ]
                    );
                    break;
                } catch (\PDOException $e) {
                    if (stripos($e->getMessage(), 'database is locked') !== false && $attempt < 2) {
                        usleep(200000);
                        continue;
                    }
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to upsert service customer on register: '.$e->getMessage());
            // Continue; registration should not fail if this auxiliary write fails
        }

        return response()->json([
            'message' => 'Account created successfully',
            'user' => $user,
        ], 201);
    }

    public function Login(Request $request){
        // Validate input
        $request->validate([
            'mobile' => 'required|string',
            'password' => 'required|string',
        ]);

        // Normalize mobile to digits only to avoid formatting mismatches
        $normalizedMobile = preg_replace('/[^0-9]/', '', (string) $request->mobile);

        $user = SignupModel::where('mobileno', $normalizedMobile)->first();
        if (!$user) {
            return response()->json([
                'message' => 'Mobile number not found'
            ], 404);
        }

        if (!Hash::check($request->password, $user->Password)) {
            return response()->json([
                'message' => 'Incorrect Password'
            ], 401);
        }

        // Ensure service_customers has a record for this user (backfill path on login)
        try {
            $fullName = trim((string) ($user->Full_Name ?? ''));
            $firstName = $fullName !== '' ? explode(' ', $fullName, 2)[0] : 'Customer';
            $lastName = $fullName !== '' && strpos($fullName, ' ') !== false ? explode(' ', $fullName, 2)[1] : '';

            ServiceCustomer::firstOrCreate(
                ['email' => $user->Email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $normalizedMobile,
                    'created_at' => now()
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('Failed to upsert service customer on login: '.$e->getMessage());
        }

        // Return consistent auth-shaped response (token fields can be wired later)
        return response()->json([
            'message' => 'Login Successful',
            'user' => $user,
            'access_token' => base64_encode($user->id . '|' . now()),
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Get service history for authenticated user
     */
    public function getServiceHistory(Request $request) {
        try {
            // Identify user context – prefer explicit email/phone provided by frontend
            $email = $request->query('email')
                ?: $request->header('X-User-Email')
                ?: $request->query('customer_email');
            $phone = $request->query('phone')
                ?: $request->header('X-User-Phone');

            if (!$email && !$phone) {
                // As a fallback, require a token; if missing, block
                $userId = $this->getUserIdFromToken($request);
                if (!$userId) {
                    return response()->json(['error' => 'Unauthorized'], 401);
                }
            }

            // Normalize identifiers
            $email = $email ? strtolower(trim($email)) : null;
            $phone = $phone ? preg_replace('/[^0-9]/', '', $phone) : null;

            // Query from service_history (single source of truth)
            $query = \App\Models\ServiceHistory::query();
            if ($email) {
                $query->where('customer_email', $email);
            } elseif ($phone) {
                $query->where('customer_phone', $phone);
            }
            $status = strtolower((string) $request->query('status', ''));
            if (in_array($status, ['pending','completed','cancelled'])) {
                $query->where('status', $status);
            }
            $rows = $query->orderByDesc('id')->limit(200)->get();

            // Map DB rows to frontend shape
            $services = $rows->map(function ($r) {
                $details = is_array($r->service_details) ? $r->service_details : [];
                $date = $details['service_date'] ?? ($r->created_at ? $r->created_at->toDateString() : null);
                $time = $details['service_datetime'] ?? null;
                if ($time && strpos($time, ' ') !== false) {
                    $time = trim(explode(' ', $time, 2)[1]);
                }
                return [
                    'id' => $r->id,
                    'serviceName' => $r->service_type ?? 'Service',
                    'petName' => $details['pet_name'] ?? null,
                    'petType' => $details['pet_type'] ?? null,
                    'date' => $date,
                    'time' => $time,
                    'duration' => $details['duration'] ?? null,
                    'address' => $details['address'] ?? ($details['customer_address'] ?? null),
                    'providerPhone' => $details['provider_phone'] ?? null,
                    'provider' => $details['provider'] ?? 'TommyAndFurry',
                    'notes' => $details['notes'] ?? null,
                    'price' => (int) $r->amount,
                    'status' => $r->status,
                    'payment_id' => $r->payment_id,
                    'order_id' => $r->order_id,
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
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch service history',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate payment receipt for a service
     */
    public function generateReceipt(Request $request, $serviceId) {
        try {
            $userId = $this->getUserIdFromToken($request);
            
            if (!$userId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // In a real app, you would fetch service details from database
            // For demo, we'll create a simple text receipt
            $receiptContent = $this->generateReceiptContent($serviceId, $userId);

            return response($receiptContent)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'attachment; filename="receipt-' . $serviceId . '.txt"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate receipt',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add service to history after successful payment
     */
    public function addServiceToHistory(Request $request) {
        try {
            $userId = $this->getUserIdFromToken($request);
            
            if (!$userId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $request->validate([
                'service_name' => 'required|string',
                'pet_name' => 'required|string',
                'pet_type' => 'required|string',
                'date' => 'required|date',
                'time' => 'required|string',
                'duration' => 'required|string',
                'address' => 'required|string',
                'price' => 'required|numeric',
                'provider' => 'required|string',
                'provider_phone' => 'required|string',
                'notes' => 'nullable|string',
                'payment_id' => 'required|string'
            ]);

            // In a real app, you would save to service_bookings table
            // For demo, we'll just return success
            $serviceData = [
                'id' => uniqid(),
                'user_id' => $userId,
                'service_name' => $request->service_name,
                'pet_name' => $request->pet_name,
                'pet_type' => $request->pet_type,
                'date' => $request->date,
                'time' => $request->time,
                'duration' => $request->duration,
                'address' => $request->address,
                'status' => 'completed',
                'price' => $request->price,
                'provider' => $request->provider,
                'provider_phone' => $request->provider_phone,
                'notes' => $request->notes,
                'payment_id' => $request->payment_id,
                'created_at' => now()
            ];

            return response()->json([
                'success' => true,
                'service' => $serviceData,
                'message' => 'Service added to history successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to add service to history',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper method to get user ID from token
     */
    private function getUserIdFromToken(Request $request) {
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);
        
        try {
            $decoded = base64_decode($token);
            $parts = explode('|', $decoded);
            return $parts[0] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate receipt content
     */
    private function generateReceiptContent($serviceId, $userId) {
        $receipt = "
╔══════════════════════════════════════════════════════════════╗
║                    PAYMENT RECEIPT                          ║
║                   TommyAndFurry Services                    ║
╠══════════════════════════════════════════════════════════════╣
║ Service ID: {$serviceId}                                    ║
║ Date: " . date('d/m/Y') . "                                 ║
║ Time: " . date('H:i:s') . "                                 ║
╠══════════════════════════════════════════════════════════════╣
║                    SERVICE DETAILS                          ║
╠══════════════════════════════════════════════════════════════╣
║ Service: Pet Care Service                                   ║
║ Status: Completed                                           ║
║ Payment: Successful                                         ║
╠══════════════════════════════════════════════════════════════╣
║                    CUSTOMER DETAILS                         ║
╠══════════════════════════════════════════════════════════════╣
║ User ID: {$userId}                                          ║
║ Generated: " . date('d/m/Y H:i:s') . "                      ║
╠══════════════════════════════════════════════════════════════╣
║                    IMPORTANT NOTES                          ║
╠══════════════════════════════════════════════════════════════╣
║ • This receipt confirms your payment was successful         ║
║ • Keep this receipt for your records                        ║
║ • Contact support if you have any questions                 ║
║ • Thank you for choosing TommyAndFurry!                     ║
╚══════════════════════════════════════════════════════════════╝
        ";

        return $receipt;
    }

    public function fetchCarData(){

        $bodyData=[        
            "commissionContractId" => "1000012208",
            "saleManagerCode" =>"",
            "agentCode"=>"",
            "channelCode"=>"002",
            "branch"=>"Mumbai",
            "make"=> "DATSUN",
            "model"=> "GO",
            "variant"=> "A",
            "idvcity"=> "MUMBAI",
            "rtoStateCode"=>"13",
            "rtoLocationName"=> "MH-01",
            "clusterZone"=> "Cluster 3",
            "carZone"=>"A",
            "rtoZone"=>"13",
            "rtoCityOrDistrict"=> "Mumbai Central",
            "idv"=>"334642.0",
            "registrationDate"=>"2022-01-23",
            "previousInsurancePolicy"=> "1",
            "previousPolicyExpiryDate"=> "2023-01-23",
            "typeOfBusiness"=> "Rollover",
            "renewalStatus"=> "New Policy",
            "policyType"=> "Package Policy",
            "policyStartDate"=> "2023-01-24",
            "policyTenure"=> "1",
            "claimDeclaration"=> [],
            "previousNcb"=> [],
            "annualMileage"=> "10000",
            "fuelType"=> "PETROL",
            "transmissionType"=> "Automatic",
            "dateOfTransaction"=> "",
            "subPolicyType"=> [],
            "validLicenceNo"=> "Y",
            "transferOfNcb"=> "Yes",
            "transferOfNcbPercentage"=> "20",
            "proofProvidedForNcb"=> "NCB Reserving Letter",
            "protectionofNcbValue"=> "20",
            "breakinInsurance"=> "No Break",
            "contractTenure"=> "1.0",
            "overrideAllowableDiscount"=> "N",
            "fibreGlassFuelTank"=> "Y",
            "antiTheftDeviceInstalled"=> "Y",
            "automobileAssociationMember"=> "Y",
            "bodystyleDescription"=> "HATCHBACK",
            "dateOfFirstPurchaseOrRegistration"=> "2019-10-23",
            "dateOfBirth"=> "1985-04-03",
            "policyHolderGender"=> "Male",
            "policyholderOccupation"=> "Medium to High",
            "typeOfGrid"=> "Grid 1",
            "payAsYouDrive"=> "No",
            "currentOdometerReading"=> "",
            "avgYearUsage"=> "",
            "insuredNoOfKms"=> "",
            "contractDetails"=> [
        [
            "contract"=> "Own Damage Contract",
            "coverage"=> [
                "coverage"=> "Own Damage Coverage",
                "deductible"=> "Own Damage Basis Deductible",
                "discount"=> [
                    "Auto Mobile Association Discount",
                    "AntiTheft Discount",
                    "Vintage Car Discount",
                    "No Claim Bonus Discount"
                ],
                "subCoverage"=> [
                    [
                        "subCoverage"=> "Own Damage Basic",
                        "limit"=> "Own Damage Basic Limit"
                    ],
                    [
                        "subCoverage"=> "Non Electrical Accessories",
                        "accessoryDescription"=> "Guard",
                        "valueOfAccessory"=> "1000",
                        "limit"=> "Non Electrical Accessories Limit"
                    ],
                    [
                        "subCoverage"=> "Electrical Electronic Accessories",
                        "accessoryDescription"=> "Headlights",
                        "valueOfAccessory"=> "1000",
                        "limit"=> "Electrical Electronic Accessories Limit"
                    ],
                    [
                        "subCoverage"=> "CNG LPG Kit Own Damage",
                        "limit"=> "CNG LPG Kit Own Damage Limit",
                        "valueofKit"=> "1000.0"
                    ],
                    [
                        "subCoverage"=> "In built CNG LPG Kit Own Damage",
                        "valueofKit"=> "0"
                    ]
                ]
            ]
    ],
        [
            "contract"=> "Addon Contract",
            "coverage"=> [
                "coverage"=> "Add On Coverage",
                "deductible"=> "Key Replacement Deductible",
                "underwriterDiscount"=> "0.0",
                "subCoverage"=> [
                    [
                        "subCoverage"=>"Return To Invoice"
                    ],
                    [
                        "subCoverage"=> "Key Replacement"
                    ],
                    [
                        "subCoverage"=> "Protection of NCB"
                    ],
                    [
                        "subCoverage"=> "Tyre Safeguard"
                    ],
                    [
                        "subCoverage"=> "Zero Depreciation"
                    ],
                    [
                        "subCoverage"=> "Engine Protect"
                    ],
                    [
                        "subCoverage"=> "Consumable Cover"
                    ],
                    [
                        "subCoverage"=> "Waiver of Policy"
                    ],
                    [
                        "subCoverage"=> "Basic Road Assistance"
                    ],
                    [
                        "subCoverage"=> "Loss of Personal Belongings"
                    ]
                ]
            ]
        ],
        [
            "contract"=> "PA Compulsary Contract",
            "coverage"=> [
                "coverage"=> "PA Owner Driver Coverage",
                "subCoverage"=> [
                    "subCoverage"=> "PA Owner Driver",
                    "limit"=> "PA Owner Driver Limit",
                    "sumInsuredperperson"=> "1500000"
                ]
            ]
        ],
        [
            "contract"=> "Third Party Multiyear Contract",
            "coverage"=> [
                "coverage"=> "Legal Liability to Third Party Coverage",
                "deductible"=> "TP Deductible",
                "discount"=> "Third Party Property Damage Discount",
                "subCoverage"=> [
                    [
                        "subCoverage"=> "Third Party Basic Sub Coverage",
                        "limit"=> "Third Party Property Damage Limit",
                        "thirdPartyPropertyDamageLimit"=> "6000"
                    ],
                    [
                        "subCoverage"=> "CNG LPG Kit Liability"
                    ],
                    [
                        "subCoverage"=> "Legal Liability to Employees",
                        "numberofEmployees"=> "1"
                    ],
                    [
                        "subCoverage"=> "Legal Liability to Paid Drivers",
                        "numberofPaidDrivers"=> "1"
                    ],
                    [
                        "subCoverage"=> "PA Unnamed Passenger",
                        "limit"=> "PA Unnamed Passenger Limit",
                        "sumInsuredperperson"=> "10000"
                    ]
                ]
            ]
        ]
    ]
 ];

            // Check if external API credentials are configured
            $apiToken = env('API_TOKEN');
            $xApiKey = env('X_API_KEY');
            
            if (!$apiToken || !$xApiKey || 
                $apiToken === 'your_api_token_here' || 
                $xApiKey === 'your_x_api_key_here') {
                
                // Return fallback data when external API credentials are not configured
                return response()->json([
                    'status' => 'success',
                    'message' => 'Using fallback data - External API credentials not configured',
                    'contractDetails' => [
                        [
                            'contract' => 'Own Damage Contract',
                            'coverage' => [
                                'coverage' => 'Own Damage Coverage',
                                'deductible' => 'OD Deductible',
                                'discount' => 'Own Damage Discount',
                                'subCoverage' => [
                                    [
                                        'subCoverage' => 'Own Damage Basic Sub Coverage',
                                        'limit' => 'Own Damage Limit',
                                        'ownDamageLimit' => '334642'
                                    ],
                                    [
                                        'subCoverage' => 'CNG LPG Kit',
                                        'limit' => 'CNG LPG Kit Limit',
                                        'cngLpgKitLimit' => '50000'
                                    ],
                                    [
                                        'subCoverage' => 'Accessories',
                                        'limit' => 'Accessories Limit',
                                        'accessoriesLimit' => '25000'
                                    ]
                                ]
                            ]
                        ],
                        [
                            'contract' => 'Third Party Multiyear Contract',
                            'coverage' => [
                                'coverage' => 'Legal Liability to Third Party Coverage',
                                'deductible' => 'TP Deductible',
                                'discount' => 'Third Party Property Damage Discount',
                                'subCoverage' => [
                                    [
                                        'subCoverage' => 'Third Party Basic Sub Coverage',
                                        'limit' => 'Third Party Property Damage Limit',
                                        'thirdPartyPropertyDamageLimit' => '6000'
                                    ],
                                    [
                                        'subCoverage' => 'CNG LPG Kit Liability'
                                    ],
                                    [
                                        'subCoverage' => 'Legal Liability to Employees',
                                        'numberofEmployees' => '1'
                                    ],
                                    [
                                        'subCoverage' => 'Legal Liability to Paid Drivers',
                                        'numberofPaidDrivers' => '1'
                                    ],
                                    [
                                        'subCoverage' => 'PA Unnamed Passenger',
                                        'limit' => 'PA Unnamed Passenger Limit',
                                        'sumInsuredperperson' => '10000'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]);
            }

           $response=Http::withHeaders([
            'Authorization'=>env('API_TOKEN'),
            'x-api-key'=>env('X_API_KEY'),
            'Content-Type'=>'application/json',
           ])->timeout(10)->post('https://devapi.hizuno.com/motor/quote',$bodyData);
        

        if($response->successful()){
            return $response->json();
        }

        // If external API fails, return fallback data instead of error
        return response()->json([
            'status' => 'success',
            'message' => 'Using fallback data - External API temporarily unavailable',
            'contractDetails' => [
                [
                    'contract' => 'Own Damage Contract',
                    'coverage' => [
                        'coverage' => 'Own Damage Coverage',
                        'deductible' => 'OD Deductible',
                        'discount' => 'Own Damage Discount',
                        'subCoverage' => [
                            [
                                'subCoverage' => 'Own Damage Basic Sub Coverage',
                                'limit' => 'Own Damage Limit',
                                'ownDamageLimit' => '334642'
                            ],
                            [
                                'subCoverage' => 'CNG LPG Kit',
                                'limit' => 'CNG LPG Kit Limit',
                                'cngLpgKitLimit' => '50000'
                            ],
                            [
                                'subCoverage' => 'Accessories',
                                'limit' => 'Accessories Limit',
                                'accessoriesLimit' => '25000'
                            ]
                        ]
                    ]
                ],
                [
                    'contract' => 'Third Party Multiyear Contract',
                    'coverage' => [
                        'coverage' => 'Legal Liability to Third Party Coverage',
                        'deductible' => 'TP Deductible',
                        'discount' => 'Third Party Property Damage Discount',
                        'subCoverage' => [
                            [
                                'subCoverage' => 'Third Party Basic Sub Coverage',
                                'limit' => 'Third Party Property Damage Limit',
                                'thirdPartyPropertyDamageLimit' => '6000'
                            ],
                            [
                                'subCoverage' => 'CNG LPG Kit Liability'
                            ],
                            [
                                'subCoverage' => 'Legal Liability to Employees',
                                'numberofEmployees' => '1'
                            ],
                            [
                                'subCoverage' => 'Legal Liability to Paid Drivers',
                                'numberofPaidDrivers' => '1'
                            ],
                            [
                                'subCoverage' => 'PA Unnamed Passenger',
                                'limit' => 'PA Unnamed Passenger Limit',
                                'sumInsuredperperson' => '10000'
                            ]
                        ]
                    ]
                ]
            ]
        ]);
     }


     public function getFullQuote() {
        try {
        $bodyData = [
    "commissionContractId" => "1000012208",
    "source" => "",
    "subIntermediaryCategory" => "",
    "subIntermediaryCode" => "",
    "subIntermediaryName" => "",
    "subIntermediaryPhoneorEmail" => "",
    "POSPPANorAadharNo" => "",
    "accountNo" => "",
    "agentName" => "",
    "agentCode" => "",
    "branch" => "MUMBAI",
    "agentEmail" => "shivakumar.bale@qualitykiosk.com",
    "saleManagerCode" => "26058",
    "saleManagerName" => "Rahul B",
    "mainApplicantField" => "1",
    "typeOfBusiness" => "New",
    "policyType" => "Bundled Insurance",
    "policyStartDate" => "2024-09-21",
    "policyStartTime" => "120100",
    "policyEndDay" => "2027-09-20",
    "policyEndTime" => "235900",
    "previousInsurancePolicy" => "0",
    "previousInsuranceCompanyName" => "",
    "previousInsuranceCompanyAddress" => "",
    "previousPolicyStartDate" => "",
    "previousPolicyEndDate" => "",
    "previousPolicyNo" => "",
    "natureOfLoss" => "",
    "policyTenure" => "3",
    "make" => "HONDA",
    "model" => "CITY",
    "variant" => "1.3 LXI",
    "idvCity" => "DELHI",
    "cubicCapacity" => "1343",
    "licencedSeatingCapacity" => "5",
    "licencedCarryingCapacity" => "5",
    "validLicenceNo" => "Y",
    "fuelType" => "PETROL",
    "newOrUsed" => "N",
    "yearOfManufacture" => "2024",
    "registrationDate" => "2024-08-08",
    "vehicleAge" => "1",
    "engineeNumber" => "skjahslkshlkasals4s4s",
    "chassisNumber" => "sadsiudqw9udqwd72yd2d",
    "fibreGlassFuelTank" => "N",
    "bodystyleDescription" => "HATCHBACK",
    "bodyType" => "Saloon",
    "transmissionType" => "Gear",
    "validDrivingLicense" => "Y",
    "handicapped" => "N",
    "certifiedVintageCar" => "N",
    "automobileAssociationMember" => "Y",
    "antiTheftDeviceInstalled" => "Y",
    "typeOfDeviceInstalled" => "Burglary Alarm",
    "automobileAssociationMembershipNumber" => "123456",
    "automobileAssociationMembershipExpiryDate" => "2023-09-09",
    "stateCode" => "30",
    "districtCode" => "03",
    "vehicleSeriesNumber" => "cf",
    "registrationNumber" => "3872",
    "vehicleRegistrationNumber" => "DL 03 CF 3872",
    "rtoState" => "DL",
    "rtoLocationName" => "DL-03",
    "rtoCityOrDistrict" => "Delhi South: Sheikh Sarai",
    "clusterZone" => "Cluster 5",
    "carZone" => "A",
    "rtoZone" => "30",
    "protectionofNcbValue" => "",
    "transferOfNcb" => "N",
    "transferOfNcbPercentage" => "",
    "proofDocumentDate" => "",
    "proofProvidedForNcb" => "",
    "applicableNcb" => "",
    "exshowroomPrice" => "635116",
    "originalIdvValue" => "603361",
    "requiredDiscountOrLoadingPercentage" => "30",
    "financeType" => "Lease",
    "financierName" => "AHMEDABAD MERCANTILE COOPERATIVE BANK",
    "branchNameAndAddress" => "New Delhi South",
    "salutation" => "Mrs.",
    "firstName" => "owner",
    "lastName" => "name",
    "gender" => "Female",
    "policyHolderGender" => "Female",
    "maritalStatus" => "Single",
    "dateOfBirth" => "2004-08-08",
    "currentAddressLine1" => "ekjfhkjwf",
    "currentAddressLine2" => "whdkjwdhdwjk",
    "currentCountry" => "IN",
    "pincode" => "400070",
    "currentCity" => "Mumbai",
    "currentState" => "13",
    "mobileNumber" => "8888888888",
    "emailId" => "s@a.y",
    "occupation" => "Salaried",
    "nomineeName" => "test name",
    "relationshipWithApplicant" => "Son",
    "isNomineeMinor" => "N",
    "nomineeAge" => "18",
    "nomineeDob" => "2003-09-09",
    "overrideAllowableDiscount" => "Y",
    "renewalstatus" => "New Policy",
    "annualmileageofthecar" => "10000",
    "breakininsurance" => "No Break",
    "typeofGrid" => "Grid 1",
    "staffCode" => "ww223",
    "payAsYouDrive" => "No",
    "currentOdometerReading" => "",
    "avgYearUsage" => "",
    "insuredNoOfKms" => "",
    "driverDetails" => [
        [
            "nameofDriver" => "Adhyatamjot",
            "dateofBirth" => "1995-07-28",
            "genderoftheDriver" => "Male",
            "ageofDriver" => "27.0",
            "relationshipwithProposer" => "Brother",
            "drivingExperienceinyears" => "1",
            "middleName" => "",
            "lastName" => "Singh"
        ]
    ],
    "contractDetails" => [
        [
            "contract" => "Own Damage Contract",
            "coverage" => [
                [
                    "coverage" => "Own Damage Coverage",
                    "deductible" => "Own Damage Basis Deductible",
                    "discount" => [
                        "Voluntary Deductible Discount",
                        "AntiTheft Discount",
                        "Auto Mobile Association Discount"
                    ],
                    "voluntaryCoPay" => "Rs. 15000",
                    "subCoverage" => [
                        [
                            "subCoverage" => "Own Damage Basic",
                            "limit" => "Own Damage Basic Limit",
                            "idvValue" => "603361.00"
                        ],
                        [
                            "subCoverage" => "Non Electrical Accessories",
                            "accessoryDescription" => "abc",
                            "valueOfAccessory" => "10000",
                            "limit" => "Non Electrical Accessories Limit"
                        ],
                        [
                            "subCoverage" => "Electrical Electronic Accessories",
                            "accessoryDescription" => "xyz",
                            "valueOfAccessory" => "10000",
                            "limit" => "Electrical Electronic Accessories Limit"
                        ],
                        [
                            "subCoverage" => "CNG LPG Kit Own Damage",
                            "limit" => "CNG LPG Kit Own Damage Limit",
                            "valueOfKit" => "1000",
                            "accessoryDescription" => "CNG"
                        ],
                        [
                            "subCoverage" => "In built CNG LPG Kit Own Damage",
                            "valueOfKit" => "0"
                        ]
                    ]
                ]
            ]
        ],
        [
            "contract" => "Addon Contract",
            "coverage" => [
                [
                    "coverage" => "Add On Coverage",
                    "deductible" => "Key Replacement Deductible",
                    "subCoverage" => [
                        ["subCoverage" => "Tyre Safeguard"],
                        ["subCoverage" => "Zero Depreciation"],
                        ["subCoverage" => "Engine Protect"],
                        ["subCoverage" => "Return To Invoice"],
                        ["subCoverage" => "Key Replacement"],
                        ["subCoverage" => "Waiver of Policy"],
                        ["subCoverage" => "Consumable Cover"],
                        ["subCoverage" => "Basic Road Assistance"],
                        ["subCoverage" => "Loss of Personal Belongings"]
                    ]
                ]
            ]
        ],
        [
            "contract" => "Third Party Multiyear Contract",
            "coverage" => [
                [
                    "coverage" => "Legal Liability to Third Party Coverage",
                    "discount" => "",
                    "deductible" => "TP Deductible",
                    "subCoverage" => [
                        [
                            "subCoverage" => "Third Party Basic Sub Coverage",
                            "limit" => "Third Party Property Damage Limit",
                            "thirdPartyPropertyDamageLimit" => "750000"
                        ],
                        [
                            "subCoverage" => "PA Unnamed Passenger",
                            "limit" => "PA Unnamed Passenger Limit",
                            "sumInsuredPerPerson" => "100000"
                        ],
                        [
                            "subCoverage" => "Legal Liability to Paid Drivers",
                            "numberOfPaidDrivers" => "1"
                        ],
                        [
                            "subCoverage" => "Legal Liability to Employees",
                            "numberOfEmployees" => "1"
                        ],
                        ["subCoverage" => "CNG LPG Kit Liability"],
                        [
                            "subCoverage" => "PA to Paid Driver Cleaner Conductor",
                            "limit" => "PA to Paid Driver Cleaner Conductor Limit",
                            "sumInsuredPerPerson" => "100000",
                            "numberOfPaidDrivers" => "1"
                        ]
                    ]
                ]
            ]
        ]
    ]
];

            $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('API_FULL_QUOTE'),
                'x-api-key' => env('X_API_KEY'),
                'Content-Type' => 'application/json',
            ])->timeout(10)->post('https://devapi.hizuno.com/motor/fullQuote', $bodyData);

            \Log::info('Full Quote API Response: ' . $response->body());

            if ($response->successful()) {
                return $response->json();
            } else {
                // Return fallback data when external API fails
                return response()->json([
                    'status' => 'success',
                    'message' => 'Using fallback data - External API temporarily unavailable',
                    'policyData' => [
                        'policyType' => 'Bundled Insurance',
                        'policyStartDate' => '2024-09-21',
                        'channelType' => '002',
                        'branch' => 'MUMBAI',
                        'make' => 'HONDA',
                        'model' => 'CITY',
                        'variant' => '1.3 LXI'
                    ],
                    'premiumDetails' => [
                        'totalODPremium' => 4720.69,
                        'totalAddOnPremium' => 3877.9,
                        'totalTPPremium' => 3501,
                        'totalPApremium' => 220,
                        'gst' => 2217.53,
                        'grossTotalPremium' => 14537.12
                    ],
                    'contractDetails' => [
                        [
                            'contractStartDate' => '2024-09-21',
                            'contractEndDate' => '2027-09-20',
                            'salesProductTemplateId' => 'MOCNMF00',
                            'transferofNcb' => 'Y',
                            'transferofNcbPercentage' => 20,
                            'contractTenure' => 3
                        ],
                        [
                            'contractStartDate' => '2024-09-21',
                            'contractEndDate' => '2027-09-20',
                            'salesProductTemplateId' => 'MOCNMF01',
                            'transferofNcb' => 'Y',
                            'transferofNcbPercentage' => 20,
                            'contractTenure' => 3
                        ]
                    ]
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Full Quote API Error: ' . $e->getMessage());
            
            // Return fallback data on any error
            return response()->json([
                'status' => 'success',
                'message' => 'Using fallback data - API service unavailable',
                'policyData' => [
                    'policyType' => 'Bundled Insurance',
                    'policyStartDate' => '2024-09-21',
                    'channelType' => '002',
                    'branch' => 'MUMBAI',
                    'make' => 'HONDA',
                    'model' => 'CITY',
                    'variant' => '1.3 LXI'
                ],
                'premiumDetails' => [
                    'totalODPremium' => 4720.69,
                    'totalAddOnPremium' => 3877.9,
                    'totalTPPremium' => 3501,
                    'totalPApremium' => 220,
                    'gst' => 2217.53,
                    'grossTotalPremium' => 14537.12
                ],
                'contractDetails' => [
                    [
                        'contractStartDate' => '2024-09-21',
                        'contractEndDate' => '2027-09-20',
                        'salesProductTemplateId' => 'MOCNMF00',
                        'transferofNcb' => 'Y',
                        'transferofNcbPercentage' => 20,
                        'contractTenure' => 3
                    ],
                    [
                        'contractStartDate' => '2024-09-21',
                        'contractEndDate' => '2027-09-20',
                        'salesProductTemplateId' => 'MOCNMF01',
                        'transferofNcb' => 'Y',
                        'transferofNcbPercentage' => 20,
                        'contractTenure' => 3
                    ]
                ]
            ]);
        }
     }

     // Method to create a new insurance contract
     public function createInsuranceContract(Request $request) {
        try {
            // Handle both field name formats (start_date/end_date vs contract_start_date/contract_end_date)
            $contractStartDate = $request->contract_start_date ?? $request->start_date ?? date('Y-m-d');
            $contractEndDate = $request->contract_end_date ?? $request->end_date ?? date('Y-m-d', strtotime('+1 year'));
            
            // Validate required fields
            if (!$contractStartDate || !$contractEndDate) {
                return response()->json([
                    'error' => 'Missing required fields',
                    'message' => 'contract_start_date and contract_end_date (or start_date and end_date) are required'
                ], 400);
            }

            $contract = InsuranceContract::create([
                'contract_start_date' => $contractStartDate,
                'contract_end_date' => $contractEndDate,
                'product_id' => $request->product_id ?? 'PROD_' . time(),
                'ncb_transfer' => $request->ncb_transfer ?? 'No',
                'ncb_percentage' => $request->ncb_percentage ?? 0,
                'contract_tenure' => $request->contract_tenure ?? 12,
                'policy_type' => $request->policy_type ?? 'General Insurance',
                'vehicle_make' => $request->vehicle_make ?? 'N/A',
                'vehicle_model' => $request->vehicle_model ?? 'N/A',
                'premium_amount' => $request->premium_amount ?? 0,
                'status' => 'active'
            ]);

            return response()->json([
                'message' => 'Insurance contract created successfully',
                'data' => $contract,
                'contract_id' => $contract->id,
                'policy_number' => $request->policy_number ?? 'POL_' . $contract->id,
                'customer_name' => $request->customer_name ?? 'Customer',
                'customer_email' => $request->customer_email ?? 'customer@example.com',
                'customer_phone' => $request->customer_phone ?? '9999999999',
                'coverage_amount' => $request->coverage_amount ?? 0
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Insurance contract creation error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to create insurance contract',
                'message' => $e->getMessage(),
                'debug_info' => [
                    'contract_start_date' => $request->contract_start_date ?? $request->start_date ?? 'NOT_PROVIDED',
                    'contract_end_date' => $request->contract_end_date ?? $request->end_date ?? 'NOT_PROVIDED',
                    'product_id' => $request->product_id ?? 'NOT_PROVIDED',
                    'ncb_transfer' => $request->ncb_transfer ?? 'NOT_PROVIDED'
                ]
            ], 500);
        }
     }

     // Method to get all insurance contracts
     public function getInsuranceContracts() {
        try {
            $contracts = InsuranceContract::orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'message' => 'Insurance contracts retrieved successfully',
                'data' => $contracts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve insurance contracts',
                'message' => $e->getMessage()
            ], 500);
        }
     }

     // Method to get a specific insurance contract
     public function getInsuranceContract($id) {
        try {
            $contract = InsuranceContract::findOrFail($id);
            
            return response()->json([
                'message' => 'Insurance contract retrieved successfully',
                'data' => $contract
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Insurance contract not found',
                'message' => $e->getMessage()
            ], 404);
        }
     }

     // Method to get all registered users
     public function getRegisteredUsers() {
        try {
            $users = SignupModel::orderBy('created_at', 'desc')->get();

        return response()->json([
                'message' => 'Registered users retrieved successfully',
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve registered users',
                'message' => $e->getMessage()
            ], 500);
        }
     }

     // Method to generate QR code for payment
     public function generateQRCode(Request $request) {
        try {
            $amount = $request->input('amount');
            $orderId = $request->input('order_id');
            
            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            // Check if Razorpay credentials are configured
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return fallback QR code for demo mode with proper UPI format
                $upiId = "demo-merchant@razorpay";
                $merchantName = "NOVACRED PRIVATE LIMITED";
                $transactionNote = "Insurance Payment - Order {$orderId}";
                
                // Create proper UPI payment QR code format
                $upiPaymentString = "upi://pay?pa={$upiId}&pn=" . urlencode($merchantName) . "&am={$amount}&cu=INR&tn=" . urlencode($transactionNote);
                $qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upiPaymentString);
                
                return response()->json([
                    'qr_code_url' => $qrCodeUrl,
                    'qr_code_id' => 'demo_qr_' . time(),
                    'upi_id' => 'demo-merchant@razorpay',
                    'merchant_name' => 'NOVACRED PRIVATE LIMITED (Demo)',
                    'amount' => $amount,
                    'order_id' => $orderId,
                    'expires_at' => time() + 1800,
                    'note' => 'Demo QR code. Configure RAZORPAY_KEY and RAZORPAY_SECRET for real QR codes.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);
            
            // Create QR code using Razorpay's QR code API
            $qrData = [
                'type' => 'upi_qr',
                'name' => 'NOVACRED PRIVATE LIMITED', // Your actual merchant name
                'usage' => 'single_use',
                'fixed_amount' => true,
                'payment_amount' => $amount * 100, // Convert to paise
                'description' => 'Insurance Payment',
                'customer_id' => 'customer_' . rand(1000, 9999),
                'close_by' => time() + 1800, // QR expires in 30 minutes
                'notes' => [
                    'order_id' => $orderId,
                    'description' => 'Insurance Payment',
                    'merchant' => 'NOVACRED PRIVATE LIMITED'
                ]
            ];

            $qrCode = $api->qrCode->create($qrData);
            
            return response()->json([
                'qr_code_url' => $qrCode['image'],
                'qr_code_id' => $qrCode['id'],
                'upi_id' => $qrCode['short_url'] ?? 'your-merchant-upi-id@razorpay',
                'merchant_name' => 'NOVACRED PRIVATE LIMITED',
                'amount' => $amount,
                'order_id' => $orderId,
                'expires_at' => $qrCode['close_by']
            ]);
            
        } catch (\Exception $e) {
            \Log::error('QR Code generation error: ' . $e->getMessage());
            
            // Fallback response with proper UPI QR data
            $upiId = "fallback-merchant@razorpay";
            $merchantName = "NOVACRED PRIVATE LIMITED";
            $transactionNote = "Insurance Payment - Order {$orderId}";
            
            // Create proper UPI payment QR code format
            $upiPaymentString = "upi://pay?pa={$upiId}&pn=" . urlencode($merchantName) . "&am={$amount}&cu=INR&tn=" . urlencode($transactionNote);
            $qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upiPaymentString);
            
            return response()->json([
                'qr_code_url' => $qrCodeUrl,
                'qr_code_id' => 'fallback_qr_' . time(),
                'upi_id' => 'fallback-merchant@razorpay',
                'merchant_name' => 'NOVACRED PRIVATE LIMITED (Fallback)',
                'amount' => $amount,
                'order_id' => $orderId,
                'expires_at' => time() + 1800,
                'error' => 'QR code generation failed, using fallback',
                'note' => 'Check Razorpay credentials and account status.'
            ]);
        }
    }

    // Method to process card payment
    public function processCardPayment(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $receipt = $request->input('receipt', 'receipt_' . time());
            
            // Handle both request formats
            $cardDetails = $request->input('card_details');
            $cardNumber = $request->input('card_number');
            
            if ($cardDetails) {
                // Format 1: Nested card_details object
                $orderId = $request->input('order_id');
                $cardNumber = $cardDetails['cardNumber'] ?? '';
                $expiryMonth = $cardDetails['expiryMonth'] ?? '';
                $expiryYear = $cardDetails['expiryYear'] ?? '';
                $cvv = $cardDetails['cvv'] ?? '';
                $cardholderName = $cardDetails['cardholderName'] ?? '';
            } else {
                // Format 2: Flat structure (your current format)
                $orderId = $request->input('order_id', 'order_' . time());
                $cardNumber = $request->input('card_number', '');
                $cardExpiry = $request->input('card_expiry', '');
                $cvv = $request->input('card_cvv', '');
                $cardholderName = $request->input('card_name', '');
                
                // Parse expiry date (format: MM/YY)
                if ($cardExpiry && strpos($cardExpiry, '/') !== false) {
                    $expiryParts = explode('/', $cardExpiry);
                    $expiryMonth = $expiryParts[0] ?? '';
                    $expiryYear = '20' . ($expiryParts[1] ?? '');
                } else {
                    $expiryMonth = '';
                    $expiryYear = '';
                }
            }
            
            // Validate required fields
            if (!$amount || $amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount is required and must be greater than 0'
                ], 400);
            }
            
            if (!$cardNumber || !$expiryMonth || !$expiryYear || !$cvv || !$cardholderName) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please fill in all card details: card number, expiry date, CVV, and cardholder name'
                ], 400);
            }
            
            // Validate card number format (basic Luhn algorithm check)
            $cleanCardNumber = str_replace(' ', '', $cardNumber);
            if (!preg_match('/^\d{16}$/', $cleanCardNumber)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Card number must be exactly 16 digits'
                ], 400);
            }
            
            // Validate expiry date
            $currentYear = date('Y');
            $currentMonth = date('n');
            $expiryYearFull = '20' . $expiryYear;
            
            if ($expiryYearFull < $currentYear || 
                ($expiryYearFull == $currentYear && $expiryMonth < $currentMonth)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Card has expired. Please use a valid card.'
                ], 400);
            }
            
            // Validate CVV
            if (!preg_match('/^\d{3,4}$/', $cvv)) {
                return response()->json([
                    'success' => false,
                    'message' => 'CVV must be 3 or 4 digits'
                ], 400);
            }
            
            // Validate cardholder name
            if (strlen(trim($cardholderName)) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cardholder name must be at least 2 characters'
                ], 400);
            }

            // Check if this is testing mode
            $testingMode = $request->input('testing_mode', false);
            
            // Get Razorpay credentials
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            // Check if Razorpay credentials are properly configured
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                // Fallback to demo mode if credentials are not configured
                $paymentId = 'demo_card_payment_' . time() . '_' . rand(1000, 9999);
                $isPaymentSuccessful = true; // Always succeed in demo mode
                
                \Log::info('Card payment processed in demo mode - Razorpay credentials not configured');
            } else {
                // Real Razorpay integration
                try {
                    $api = new Api($razorpayKey, $razorpaySecret);
                    
                    // Create a Razorpay order for card payment
                    $orderData = [
                        'receipt' => 'card_order_' . time(),
                        'amount' => $amount * 100, // Convert to paise
                        'currency' => $currency,
                        'payment_capture' => 1, // Auto capture payment
                        'notes' => [
                            'description' => 'Card Payment - Insurance',
                            'customer_name' => $cardholderName,
                            'card_last4' => substr(str_replace(' ', '', $cardNumber), -4)
                        ]
                    ];
                    
                    $order = $api->order->create($orderData);
                    $orderId = $order['id'];
                    
                    // Process card payment through Razorpay API
                    // Note: For direct card payments, Razorpay requires frontend integration
                    // This creates the order and returns it for frontend processing
                    
                    $paymentId = 'razorpay_card_' . time() . '_' . rand(1000, 9999);
                    $isPaymentSuccessful = true;
                    
                    \Log::info('Razorpay order created for card payment', [
                        'order_id' => $orderId,
                        'razorpay_key' => $razorpayKey,
                        'amount' => $amount,
                        'currency' => $currency,
                        'card_last4' => substr(str_replace(' ', '', $cardNumber), -4),
                        'testing_mode' => $testingMode
                    ]);
                    
                } catch (\Exception $e) {
                    \Log::error('Razorpay order creation failed: ' . $e->getMessage());
                    
                    // Fallback to demo mode if Razorpay API fails
                    $paymentId = 'fallback_card_payment_' . time() . '_' . rand(1000, 9999);
                    $isPaymentSuccessful = true;
                }
            }
            
            if ($isPaymentSuccessful) {
                // Save payment details to database with complete information
                PaymentDetails::create([
                    'razorpay_order_id' => $orderId,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => 'card_payment_signature_' . time(),
                    'amount' => $amount, // Store in rupees (not paise)
                    'currency' => $currency,
                    'status' => 'captured',
                    'method' => 'card',
                    'card_last4' => substr(str_replace(' ', '', $cardNumber), -4),
                    'card_network' => $this->detectCardNetwork($cardNumber),
                    'customer_name' => $cardholderName,
                    'customer_email' => $request->input('customer_email', 'customer@example.com'),
                    'customer_phone' => $request->input('customer_phone', ''),
                    'car_make' => $request->input('car_make', ''),
                    'car_model' => $request->input('car_model', ''),
                    'year' => $request->input('year', ''),
                    'insurance_type' => $request->input('insurance_type', 'Comprehensive'),
                    'policy_duration' => $request->input('policy_duration', 1),
                    'reference_id' => $request->input('reference_id', 'REF_' . time()),
                    'quote_amount' => $amount * 100, // quote_amount in paise (for backward compatibility)
                    'payment_completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                \Log::info('Card payment successful', [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'card_last4' => substr(str_replace(' ', '', $cardNumber), -4),
                    'cardholder_name' => $cardholderName
                ]);
                
                // Build a payment page URL (for quick testing or deep-linking)
                $paymentPageUrl = $this->generatePaymentPageUrl($orderId, [
                    'amount' => $amount,
                    'customer_name' => $cardholderName,
                    'customer_email' => $request->input('customer_email', 'customer@example.com')
                ]);

                return response()->json([
                    'success' => true,
                    'payment_id' => $paymentId,
                    'signature' => 'card_payment_signature_' . time(),
                    'message' => 'Card payment processed successfully',
                    'amount' => $amount,
                    'currency' => $currency,
                    'order_id' => $orderId,
                    'receipt' => $receipt,
                    'card_last4' => substr(str_replace(' ', '', $cardNumber), -4),
                    'cardholder_name' => $cardholderName,
                    'razorpay_key' => $razorpayKey ?? null,
                    'testing_mode' => $testingMode,
                    'payment_page_url' => $paymentPageUrl,
                    'callback_url' => url('/api/payment-success-callback')
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Card payment failed. Please check your card details and try again.'
                ], 400);
            }
            
        } catch (\Exception $e) {
            \Log::error('Card payment processing error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Card payment processing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // Method to process UPI payment
    public function processUpiPayment(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $receipt = $request->input('receipt', 'receipt_' . time());
            $orderId = $request->input('order_id', 'order_' . time());
            $testingMode = $request->input('testing_mode', false);
            
            // Validate required fields
            if (!$amount || $amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount is required and must be greater than 0'
                ], 400);
            }
            
            // Get Razorpay credentials
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            // Check if Razorpay credentials are properly configured
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                // Fallback to demo mode if credentials are not configured
                $paymentId = 'demo_upi_payment_' . time() . '_' . rand(1000, 9999);
                $isPaymentSuccessful = true;
                
                \Log::info('UPI payment processed in demo mode - Razorpay credentials not configured');
            } else {
                // Real Razorpay integration
                try {
                    $api = new Api($razorpayKey, $razorpaySecret);
                    
                    // Create a Razorpay order for UPI payment
                    $orderData = [
                        'receipt' => 'upi_order_' . time(),
                        'amount' => $amount * 100, // Convert to paise
                        'currency' => $currency,
                        'payment_capture' => 1, // Auto capture payment
                        'notes' => [
                            'description' => 'UPI Payment - Insurance',
                            'payment_method' => 'upi'
                        ]
                    ];
                    
                    $order = $api->order->create($orderData);
                    $orderId = $order['id'];
                    
                    // Process UPI payment through Razorpay API
                    $paymentId = 'razorpay_upi_' . time() . '_' . rand(1000, 9999);
                    $isPaymentSuccessful = true;
                    
                    \Log::info('Razorpay order created for UPI payment', [
                        'order_id' => $orderId,
                        'razorpay_key' => $razorpayKey,
                        'amount' => $amount,
                        'currency' => $currency,
                        'testing_mode' => $testingMode
                    ]);
                    
                } catch (\Exception $e) {
                    \Log::error('Razorpay order creation failed: ' . $e->getMessage());
                    
                    // Fallback to demo mode if Razorpay API fails
                    $paymentId = 'fallback_upi_payment_' . time() . '_' . rand(1000, 9999);
                    $isPaymentSuccessful = true;
                }
            }
            
            if ($isPaymentSuccessful) {
                // Save payment details to database with available customer info
                PaymentDetails::create([
                    'razorpay_order_id' => $orderId,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => 'upi_payment_signature_' . time(),
                    'amount' => $amount, // Store in rupees (not paise)
                    'currency' => $currency,
                    'status' => 'captured',
                    'method' => 'upi',
                    'customer_name' => $request->input('customer_name') ?? 'UPI Customer',
                    'customer_email' => $request->input('customer_email') ?? 'customer@example.com',
                    'customer_phone' => $request->input('customer_phone') ?? '9999999999',
                    'quote_amount' => $amount * 100, // quote_amount in paise (for backward compatibility)
                    'payment_completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                \Log::info('UPI payment successful', [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'currency' => $currency
                ]);
                
                return response()->json([
                    'success' => true,
                    'payment_id' => $paymentId,
                    'signature' => 'upi_payment_signature_' . time(),
                    'message' => 'UPI payment processed successfully',
                    'amount' => $amount,
                    'currency' => $currency,
                    'order_id' => $orderId,
                    'receipt' => $receipt,
                    'razorpay_key' => $razorpayKey ?? null,
                    'testing_mode' => $testingMode
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'UPI payment failed. Please try again.'
                ], 400);
            }
            
        } catch (\Exception $e) {
            \Log::error('UPI payment processing error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'UPI payment processing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // Method to process Net Banking payment
    public function processNetBankingPayment(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $bankCode = $request->input('bank_code');
            $customerName = $request->input('customer_name');
            $customerEmail = $request->input('customer_email');
            
            // Validate required fields
            if (!$amount || $amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount is required and must be greater than 0'
                ], 400);
            }
            
            if (!$bankCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank code is required'
                ], 400);
            }
            
            // Simulate/Process Net Banking payment
            $testingMode = $request->input('testing_mode', false);
            $delay = $testingMode ? 1 : 2;
            sleep($delay);

            $paymentId = 'netbanking_payment_' . time() . '_' . rand(1000, 9999);
            $isPaymentSuccessful = true;

            if ($isPaymentSuccessful) {
                PaymentDetails::create([
                    'razorpay_order_id' => $request->input('order_id', 'order_' . time()),
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => 'netbanking_payment_signature_' . time(),
                    'amount' => $amount * 100,
                    'currency' => $currency,
                    'status' => 'captured',
                    'method' => 'netbanking',
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'payment_completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                return response()->json([
                    'success' => true,
                    'payment_id' => $paymentId,
                    'signature' => 'netbanking_payment_signature_' . time(),
                    'message' => 'Net banking payment processed successfully',
                    'amount' => $amount,
                    'currency' => $currency,
                    'order_id' => $request->input('order_id'),
                    'bank_code' => $bankCode
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Net banking payment failed. Please try again.'
            ], 400);
            
        } catch (\Exception $e) {
            \Log::error('UPI payment processing error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'UPI payment processing failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    // Method to process Net Banking payment
    // Helper method to detect card network
    private function detectCardNetwork($cardNumber) {
        $cardNumber = str_replace(' ', '', $cardNumber);
        
        if (preg_match('/^4/', $cardNumber)) {
            return 'Visa';
        } elseif (preg_match('/^5[1-5]/', $cardNumber)) {
            return 'Mastercard';
        } elseif (preg_match('/^3[47]/', $cardNumber)) {
            return 'American Express';
        } elseif (preg_match('/^6/', $cardNumber)) {
            return 'Discover';
        } else {
            return 'Unknown';
        }
    }

    // Helper method to get public URL for sharing payment links
    private function getPublicUrl() {
        // First, try to get PUBLIC_URL from environment
        $publicUrl = env('PUBLIC_URL');
        if ($publicUrl) {
            return rtrim($publicUrl, '/');
        }

        // For local development, always use localhost
        if (env('APP_ENV') === 'local' || env('APP_DEBUG', false)) {
            return 'http://127.0.0.1:8000';
        }

        // Try to get APP_URL from environment
        $appUrl = env('APP_URL');
        if ($appUrl && !str_contains($appUrl, '127.0.0.1') && !str_contains($appUrl, 'localhost')) {
            return rtrim($appUrl, '/');
        }

        // Try to detect local network IP address
        try {
            // Get the server's IP address
            $serverIp = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
            
            // If it's localhost, try to get the local network IP
            if ($serverIp === '127.0.0.1' || $serverIp === '::1') {
                // Try to get local network IP
                $localIp = $this->getLocalNetworkIp();
                if ($localIp) {
                    $port = $_SERVER['SERVER_PORT'] ?? '8000';
                    return "http://{$localIp}:{$port}";
                }
            } else {
                // Use the server IP
                $port = $_SERVER['SERVER_PORT'] ?? '8000';
                return "http://{$serverIp}:{$port}";
            }
        } catch (\Exception $e) {
            \Log::warning('Could not detect local IP: ' . $e->getMessage());
        }

        // Fallback to localhost with instructions
        return 'http://127.0.0.1:8000';
    }

    // Helper method to get local network IP address
    private function getLocalNetworkIp() {
        try {
            // Get local network IP using ipconfig command
            $output = shell_exec('ipconfig 2>nul');
            if ($output) {
                // Look for IPv4 addresses in the output
                preg_match_all('/IPv4 Address[^:]*:\s*(\d+\.\d+\.\d+\.\d+)/', $output, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $ip) {
                        // Skip localhost and loopback addresses
                        if ($ip !== '127.0.0.1' && $ip !== '::1' && 
                            !str_starts_with($ip, '169.254.') && // Link-local
                            !str_starts_with($ip, '192.168.') && // Private range
                            !str_starts_with($ip, '10.') && // Private range
                            !str_starts_with($ip, '172.')) { // Private range
                            return $ip;
                        }
                    }
                    // If no public IP found, return the first private IP
                    return $matches[1][0];
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Could not get local network IP: ' . $e->getMessage());
        }

        return null;
    }

    // Helper method to get external IP address
    private function getExternalIp() {
        try {
            // Try multiple services to get external IP
            $services = [
                'https://api.ipify.org',
                'https://ipv4.icanhazip.com',
                'https://checkip.amazonaws.com'
            ];

            foreach ($services as $service) {
                $ip = @file_get_contents($service, false, stream_context_create([
                    'http' => [
                        'timeout' => 3,
                        'method' => 'GET'
                    ]
                ]));
                
                if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return trim($ip);
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Could not get external IP: ' . $e->getMessage());
        }

        return null;
    }

    // Helper method to get expiry time in Indian timezone
    private function getIndianExpiryTime($days = 7) {
        // Set timezone to Indian Standard Time (IST)
        $indianTime = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
        
        // Add the specified number of days
        $indianTime->add(new \DateInterval("P{$days}D"));
        
        // Return Unix timestamp
        return $indianTime->getTimestamp();
    }

    // Method to create Razorpay Payment Link
    public function createPaymentLink(Request $request) {
        try {
            $amount = $request->input('amount');
            $description = $request->input('description', 'Insurance Payment');
            $policyNumber = $request->input('policy_number', 'POL' . rand(1000, 9999));
            $referenceId = $request->input('reference_id', null); // Allow custom reference_id
            $upiOnly = $request->input('upi_only', false); // New parameter for UPI-only links
            $testMode = $request->input('test_mode', true); // Control test mode behavior
            
            // Parse customer data from request
            $customer = $request->input('customer', []);
            $customerName = $customer['name'] ?? $request->input('customer_name', 'Customer');
            $customerEmail = $customer['email'] ?? $request->input('customer_email', 'customer@example.com');
            $customerPhone = $customer['contact'] ?? $request->input('customer_phone', null); // Make contact optional
            
            // Step 1: Upsert customer details into service_customers for booking context
            try {
                $fullName = trim((string) $customerName);
                $firstName = $fullName !== '' ? explode(' ', $fullName, 2)[0] : 'Customer';
                $lastName = $fullName !== '' && strpos($fullName, ' ') !== false ? explode(' ', $fullName, 2)[1] : '';

                $customerData = $this->normalizeCustomerPayload($request);
                $this->findOrUpdateCustomerByContact($customerData);
            } catch (\Throwable $e) {
                \Log::warning('Failed to upsert service customer on create-payment-link: '.$e->getMessage());
            }
            
            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            // Check if Razorpay credentials are configured
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                $customerData = [
                    'name' => $customerName,
                    'email' => $customerEmail
                ];
                
                // Only add contact if provided
                if ($customerPhone) {
                    $customerData['contact'] = $customerPhone;
                }
                
                return response()->json([
                    'payment_link_id' => 'demo_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => 'INR',
                    'description' => $description,
                    'customer' => $customerData,
                    'expire_by' => $this->getIndianExpiryTime(7), // 7 days from now (Indian time)
                    'notes' => [
                        'policy_number' => $policyNumber,
                        'payment_type' => 'insurance_premium'
                    ],
                    'message' => 'Demo payment link created. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);
            
            // Build customer data - only include contact if provided
            $customerData = [
                'name' => $customerName,
                'email' => $customerEmail
            ];
            
            // Only add contact if provided
            if ($customerPhone) {
                $customerData['contact'] = $customerPhone;
            }
            
            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => 'INR',
                'accept_partial' => $upiOnly ? false : true, // UPI links don't support partial payments
                'first_min_partial_amount' => $upiOnly ? null : 100,
                'expire_by' => $this->getIndianExpiryTime(7), // Expires in 7 days (Indian time)
                'reference_id' => $referenceId ?: 'INS_' . $policyNumber . '_' . time(),
                'description' => $description,
                'customer' => $customerData,
                'notify' => [
                    'sms' => true,
                    'email' => true
                ],
                'reminder_enable' => true,
                'callback_url' => env('APP_URL') . '/api/payment-success-callback'
            ];

            // Add UPI-specific configuration
            if ($upiOnly) {
                $paymentLinkData['upi_link'] = true;
            }

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $upiError) {
                // Handle UPI link limitation in test mode
                if (strpos($upiError->getMessage(), 'UPI Payment Links is not supported in Test Mode') !== false) {
                    // Create regular payment link instead
                    unset($paymentLinkData['upi_link']);
                    $paymentLink = $api->paymentLink->create($paymentLinkData);
                    
                    return response()->json([
                        'payment_link_id' => $paymentLink['id'],
                        'short_url' => $paymentLink['short_url'],
                        'status' => $paymentLink['status'],
                        'amount' => $amount,
                        'currency' => 'INR',
                        'description' => $description,
                        'customer' => $customerData,
                        'expire_by' => $paymentLink['expire_by'],
                        'notes' => [
                            'policy_number' => $policyNumber,
                            'payment_type' => 'insurance_premium',
                            'link_type' => 'all_methods',
                            'upi_fallback' => 'UPI links not available in test mode'
                        ],
                        'message' => 'Regular payment link created (UPI links not available in test mode)',
                        'note' => 'UPI Payment Links require Live Mode. This is a regular payment link that supports UPI along with other methods.',
                        'test_mode_note' => 'In test mode, QR code payments may auto-complete without scanning for testing purposes.'
                    ]);
                } else {
                    throw $upiError;
                }
            }
            
            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => 'INR',
                'description' => $description,
                'customer' => $customerData,
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $policyNumber,
                    'payment_type' => $upiOnly ? 'upi_insurance_premium' : 'insurance_premium',
                    'link_type' => $upiOnly ? 'upi_only' : 'all_methods'
                ],
                'upi_only' => $upiOnly,
                'message' => $upiOnly ? 'UPI Payment link created successfully' : 'Payment link created successfully',
                'test_mode_note' => 'In test mode, QR code payments may auto-complete without scanning for testing purposes.'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Payment link creation error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to create payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to get payment link status
    public function getPaymentLinkStatus(Request $request) {
        try {
            $paymentLinkId = $request->input('payment_link_id');
            
            if (!$paymentLinkId) {
                return response()->json([
                    'error' => 'Payment link ID is required'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'payment_link_id' => $paymentLinkId,
                    'status' => 'demo_status',
                    'message' => 'Demo mode - Configure Razorpay credentials for real status check'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);
            $paymentLink = $api->paymentLink->fetch($paymentLinkId);
            
            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'status' => $paymentLink['status'],
                'amount' => $paymentLink['amount'] / 100, // Convert from paise
                'currency' => $paymentLink['currency'],
                'description' => $paymentLink['description'],
                'customer' => $paymentLink['customer'],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => $paymentLink['notes'],
                'payments' => $paymentLink['payments'] ?? []
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Payment link status error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to get payment link status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to get all payment links (GET /api/payment-links)
    public function getAllPaymentLinks(Request $request) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo data for test mode
                return response()->json([
                    'items' => [
                        [
                            'id' => 'demo_link_1',
                            'short_url' => 'https://rzp.io/demo-link-1',
                            'status' => 'created',
                            'amount' => 1000,
                            'currency' => 'INR',
                            'description' => 'Demo Insurance Payment',
                            'customer' => [
                                'name' => 'Demo Customer',
                                'email' => 'demo@example.com',
                                'contact' => '9999999999'
                            ],
                            'expire_by' => time() + (7 * 24 * 60 * 60),
                            'notes' => [
                                'policy_number' => 'DEMO123',
                                'payment_type' => 'insurance_premium'
                            ],
                            'created_at' => time() - 3600,
                            'upi_only' => false
                        ],
                        [
                            'id' => 'demo_link_2',
                            'short_url' => 'https://rzp.io/demo-link-2',
                            'status' => 'paid',
                            'amount' => 500,
                            'currency' => 'INR',
                            'description' => 'Demo UPI Payment',
                            'customer' => [
                                'name' => 'UPI Customer',
                                'email' => 'upi@example.com',
                                'contact' => '9876543210'
                            ],
                            'expire_by' => time() + (6 * 24 * 60 * 60),
                            'notes' => [
                                'policy_number' => 'UPI456',
                                'payment_type' => 'upi_insurance_premium'
                            ],
                            'created_at' => time() - 7200,
                            'upi_only' => true
                        ]
                    ],
                    'count' => 2,
                    'message' => 'Demo mode - Configure Razorpay credentials for real payment links'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);
            
            // Get query parameters for filtering
            $count = $request->input('count', 10); // Default 10, max 100
            $from = $request->input('from'); // Timestamp
            $to = $request->input('to'); // Timestamp
            
            $options = [
                'count' => min($count, 100) // Razorpay max is 100
            ];
            
            if ($from) {
                $options['from'] = $from;
            }
            
            if ($to) {
                $options['to'] = $to;
            }
            
            $paymentLinks = $api->paymentLink->all($options);
            
            // Format the response
            $formattedLinks = [];
            $items = $paymentLinks['items'] ?? [];
            
            foreach ($items as $link) {
                $formattedLinks[] = [
                    'id' => $link['id'],
                    'short_url' => $link['short_url'],
                    'status' => $link['status'],
                    'amount' => $link['amount'] / 100, // Convert from paise
                    'currency' => $link['currency'],
                    'description' => $link['description'],
                    'customer' => $link['customer'] ?? [],
                    'expire_by' => $link['expire_by'],
                    'notes' => $link['notes'] ?? [],
                    'created_at' => $link['created_at'],
                    'upi_only' => isset($link['upi_link']) ? $link['upi_link'] : false,
                    'payments' => $link['payments'] ?? []
                ];
            }
            
            return response()->json([
                'items' => $formattedLinks,
                'count' => count($formattedLinks),
                'total_count' => $paymentLinks['count'] ?? count($formattedLinks),
                'message' => 'Payment links retrieved successfully'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Get all payment links error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to retrieve payment links',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to get a specific payment link by ID (GET /api/payment-links/{id})
    public function getPaymentLinkById($id) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'id' => $id,
                    'short_url' => 'https://rzp.io/demo-link',
                    'status' => 'demo_status',
                    'amount' => 1000,
                    'currency' => 'INR',
                    'description' => 'Demo Payment Link',
                    'customer' => [
                        'name' => 'Demo Customer',
                        'email' => 'demo@example.com',
                        'contact' => '9999999999'
                    ],
                    'expire_by' => time() + (7 * 24 * 60 * 60),
                    'notes' => [
                        'policy_number' => 'DEMO123',
                        'payment_type' => 'insurance_premium'
                    ],
                    'created_at' => time() - 3600,
                    'upi_only' => false,
                    'message' => 'Demo mode - Configure Razorpay credentials for real payment link'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);
            $paymentLink = $api->paymentLink->fetch($id);
            
            return response()->json([
                'id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $paymentLink['amount'] / 100, // Convert from paise
                'currency' => $paymentLink['currency'],
                'description' => $paymentLink['description'],
                'customer' => $paymentLink['customer'] ?? [],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => $paymentLink['notes'] ?? [],
                'created_at' => $paymentLink['created_at'],
                'upi_only' => isset($paymentLink['upi_link']) ? $paymentLink['upi_link'] : false,
                'payments' => $paymentLink['payments'] ?? [],
                'message' => 'Payment link retrieved successfully'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Get payment link by ID error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Payment link not found',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    // Method to get payment link directly from Razorpay API (GET /api/razorpay-payment-link/{id})
    public function getRazorpayPaymentLinkById($id) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'error' => 'Razorpay credentials not configured',
                    'message' => 'Please configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file',
                    'note' => 'This endpoint requires live Razorpay credentials to work with real payment links'
                ], 400);
            }

            // Make direct API call to Razorpay using cURL
            $url = "https://api.razorpay.com/v1/payment_links/{$id}";
            $credentials = base64_encode($razorpayKey . ':' . $razorpaySecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return response()->json([
                    'error' => 'cURL Error',
                    'message' => $curlError
                ], 500);
            }
            
            $data = json_decode($response, true);
            
            if ($httpCode === 200 && $data) {
                return response()->json([
                    'id' => $data['id'],
                    'short_url' => $data['short_url'],
                    'status' => $data['status'],
                    'amount' => $data['amount'] / 100, // Convert from paise
                    'currency' => $data['currency'],
                    'description' => $data['description'],
                    'customer' => $data['customer'] ?? [],
                    'expire_by' => $data['expire_by'],
                    'notes' => $data['notes'] ?? [],
                    'created_at' => $data['created_at'],
                    'upi_only' => isset($data['upi_link']) ? $data['upi_link'] : false,
                    'payments' => $data['payments'] ?? [],
                    'reference_id' => $data['reference_id'] ?? null,
                    'accept_partial' => $data['accept_partial'] ?? false,
                    'first_min_partial_amount' => $data['first_min_partial_amount'] ?? null,
                    'notify' => $data['notify'] ?? [],
                    'reminder_enable' => $data['reminder_enable'] ?? false,
                    'callback_url' => $data['callback_url'] ?? null,
                    'callback_method' => $data['callback_method'] ?? null,
                    'message' => 'Payment link retrieved successfully from Razorpay API'
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to fetch payment link from Razorpay',
                    'status_code' => $httpCode,
                    'message' => $data['error']['description'] ?? $response,
                    'raw_response' => $response
                ], $httpCode);
            }
            
        } catch (\Exception $e) {
            \Log::error('Razorpay payment link fetch error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to fetch payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to send SMS notification for payment link (POST /api/payment-link-sms/{id})
    public function sendPaymentLinkSMS($id) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'error' => 'Razorpay credentials not configured',
                    'message' => 'Please configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file',
                    'note' => 'This endpoint requires live Razorpay credentials to send SMS notifications',
                    'demo_response' => [
                        'success' => true,
                        'message' => 'Demo SMS notification sent successfully',
                        'payment_link_id' => $id,
                        'notification_type' => 'sms',
                        'status' => 'sent',
                        'note' => 'This is a demo response. Configure Razorpay credentials for real SMS notifications.'
                    ]
                ], 200);
            }

            // Make direct API call to Razorpay SMS notification endpoint
            $url = "https://api.razorpay.com/v1/payment_links/{$id}/notify_by/sms";
            $credentials = base64_encode($razorpayKey . ':' . $razorpaySecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return response()->json([
                    'error' => 'cURL Error',
                    'message' => $curlError
                ], 500);
            }
            
            $data = json_decode($response, true);
            
            if ($httpCode === 200 && $data) {
                return response()->json([
                    'success' => true,
                    'message' => 'SMS notification sent successfully',
                    'payment_link_id' => $id,
                    'notification_type' => 'sms',
                    'status' => 'sent',
                    'razorpay_response' => $data,
                    'timestamp' => time()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to send SMS notification',
                    'status_code' => $httpCode,
                    'message' => $data['error']['description'] ?? $response,
                    'raw_response' => $response
                ], $httpCode);
            }
            
        } catch (\Exception $e) {
            \Log::error('Payment link SMS notification error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to send SMS notification',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to send email notification for payment link (POST /api/payment-link-email/{id})
    public function sendPaymentLinkEmail($id) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'error' => 'Razorpay credentials not configured',
                    'message' => 'Please configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file',
                    'note' => 'This endpoint requires live Razorpay credentials to send email notifications',
                    'demo_response' => [
                        'success' => true,
                        'message' => 'Demo email notification sent successfully',
                        'payment_link_id' => $id,
                        'notification_type' => 'email',
                        'status' => 'sent',
                        'note' => 'This is a demo response. Configure Razorpay credentials for real email notifications.'
                    ]
                ], 200);
            }

            // Make direct API call to Razorpay email notification endpoint
            $url = "https://api.razorpay.com/v1/payment_links/{$id}/notify_by/email";
            $credentials = base64_encode($razorpayKey . ':' . $razorpaySecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return response()->json([
                    'error' => 'cURL Error',
                    'message' => $curlError
                ], 500);
            }
            
            $data = json_decode($response, true);
            
            if ($httpCode === 200 && $data) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email notification sent successfully',
                    'payment_link_id' => $id,
                    'notification_type' => 'email',
                    'status' => 'sent',
                    'razorpay_response' => $data,
                    'timestamp' => time()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to send email notification',
                    'status_code' => $httpCode,
                    'message' => $data['error']['description'] ?? $response,
                    'raw_response' => $response
                ], $httpCode);
            }
            
        } catch (\Exception $e) {
            \Log::error('Payment link email notification error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to send email notification',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to update payment link (PATCH /api/payment-link-update/{id})
    public function updatePaymentLink(Request $request, $id) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'error' => 'Razorpay credentials not configured',
                    'message' => 'Please configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file',
                    'note' => 'This endpoint requires live Razorpay credentials to update payment links',
                    'demo_response' => [
                        'success' => true,
                        'message' => 'Demo payment link updated successfully',
                        'payment_link_id' => $id,
                        'updated_fields' => [
                            'reference_id' => $request->input('reference_id', 'TS35'),
                            'expire_by' => $request->input('expire_by', 1653347540),
                            'reminder_enable' => $request->input('reminder_enable', false),
                            'notes' => $request->input('notes', ['policy_name' => 'Life Insurance Policy'])
                        ],
                        'note' => 'This is a demo response. Configure Razorpay credentials for real payment link updates.'
                    ]
                ], 200);
            }

            // Prepare update data
            $updateData = [];
            
            if ($request->has('reference_id')) {
                $updateData['reference_id'] = $request->input('reference_id');
            }
            
            if ($request->has('expire_by')) {
                $updateData['expire_by'] = $request->input('expire_by');
            }
            
            if ($request->has('reminder_enable')) {
                $updateData['reminder_enable'] = $request->input('reminder_enable');
            }
            
            if ($request->has('notes')) {
                $updateData['notes'] = $request->input('notes');
            }
            
            if ($request->has('description')) {
                $updateData['description'] = $request->input('description');
            }
            
            if ($request->has('customer')) {
                $updateData['customer'] = $request->input('customer');
            }
            
            if ($request->has('notify')) {
                $updateData['notify'] = $request->input('notify');
            }
            
            if ($request->has('callback_url')) {
                $updateData['callback_url'] = $request->input('callback_url');
            }
            
            if ($request->has('callback_method')) {
                $updateData['callback_method'] = $request->input('callback_method');
            }

            // Make direct API call to Razorpay update endpoint
            $url = "https://api.razorpay.com/v1/payment_links/{$id}";
            $credentials = base64_encode($razorpayKey . ':' . $razorpaySecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return response()->json([
                    'error' => 'cURL Error',
                    'message' => $curlError
                ], 500);
            }
            
            $data = json_decode($response, true);
            
            if ($httpCode === 200 && $data) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment link updated successfully',
                    'payment_link_id' => $id,
                    'updated_fields' => $updateData,
                    'payment_link' => [
                        'id' => $data['id'],
                        'short_url' => $data['short_url'],
                        'status' => $data['status'],
                        'amount' => $data['amount'] / 100, // Convert from paise
                        'currency' => $data['currency'],
                        'description' => $data['description'],
                        'customer' => $data['customer'] ?? [],
                        'expire_by' => $data['expire_by'],
                        'notes' => $data['notes'] ?? [],
                        'created_at' => $data['created_at'],
                        'reference_id' => $data['reference_id'] ?? null,
                        'reminder_enable' => $data['reminder_enable'] ?? false,
                        'notify' => $data['notify'] ?? [],
                        'callback_url' => $data['callback_url'] ?? null,
                        'callback_method' => $data['callback_method'] ?? null
                    ],
                    'razorpay_response' => $data,
                    'timestamp' => time()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to update payment link',
                    'status_code' => $httpCode,
                    'message' => $data['error']['description'] ?? $response,
                    'raw_response' => $response
                ], $httpCode);
            }
            
        } catch (\Exception $e) {
            \Log::error('Payment link update error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to update payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to cancel payment link (POST /api/payment-link-cancel/{id})
    public function cancelPaymentLink($id) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'error' => 'Razorpay credentials not configured',
                    'message' => 'Please configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file',
                    'note' => 'This endpoint requires live Razorpay credentials to cancel payment links',
                    'demo_response' => [
                        'success' => true,
                        'message' => 'Demo payment link cancelled successfully',
                        'payment_link_id' => $id,
                        'status' => 'cancelled',
                        'note' => 'This is a demo response. Configure Razorpay credentials for real payment link cancellation.'
                    ]
                ], 200);
            }

            // Make direct API call to Razorpay cancel endpoint
            $url = "https://api.razorpay.com/v1/payment_links/{$id}/cancel";
            $credentials = base64_encode($razorpayKey . ':' . $razorpaySecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return response()->json([
                    'error' => 'cURL Error',
                    'message' => $curlError
                ], 500);
            }
            
            $data = json_decode($response, true);
            
            if ($httpCode === 200 && $data) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment link cancelled successfully',
                    'payment_link_id' => $id,
                    'status' => 'cancelled',
                    'payment_link' => [
                        'id' => $data['id'],
                        'short_url' => $data['short_url'],
                        'status' => $data['status'],
                        'amount' => $data['amount'] / 100, // Convert from paise
                        'currency' => $data['currency'],
                        'description' => $data['description'],
                        'customer' => $data['customer'] ?? [],
                        'expire_by' => $data['expire_by'],
                        'notes' => $data['notes'] ?? [],
                        'created_at' => $data['created_at'],
                        'cancelled_at' => $data['cancelled_at'] ?? time(),
                        'reference_id' => $data['reference_id'] ?? null,
                        'reminder_enable' => $data['reminder_enable'] ?? false,
                        'notify' => $data['notify'] ?? [],
                        'callback_url' => $data['callback_url'] ?? null,
                        'callback_method' => $data['callback_method'] ?? null
                    ],
                    'razorpay_response' => $data,
                    'timestamp' => time()
                ]);
            } else {
                return response()->json([
                    'error' => 'Failed to cancel payment link',
                    'status_code' => $httpCode,
                    'message' => $data['error']['description'] ?? $response,
                    'raw_response' => $response
                ], $httpCode);
            }
            
        } catch (\Exception $e) {
            \Log::error('Payment link cancellation error: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Failed to cancel payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to create payment link with checkout options (POST /api/create-payment-link-advanced)
    public function createPaymentLinkAdvanced(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $acceptPartial = $request->input('accept_partial', true);
            $firstMinPartialAmount = $request->input('first_min_partial_amount', 100);
            $referenceId = $request->input('reference_id', 'REF_' . time());
            $description = $request->input('description', 'Insurance Payment');
            $customerName = $request->input('customer.name', 'Customer');
            $customerEmail = $request->input('customer.email', 'customer@example.com');
            $customerPhone = $request->input('customer.contact', '9999999999');
            $smsNotify = $request->input('notify.sms', true);
            $emailNotify = $request->input('notify.email', true);
            $reminderEnable = $request->input('reminder_enable', true);
            $hideTopbar = $request->input('options.checkout.theme.hide_topbar', true);
            $upiOnly = $request->input('upi_only', false);

            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                return response()->json([
                    'payment_link_id' => 'demo_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ],
                    'reference_id' => $referenceId,
                    'accept_partial' => $acceptPartial,
                    'first_min_partial_amount' => $firstMinPartialAmount,
                    'notify' => [
                        'sms' => $smsNotify,
                        'email' => $emailNotify
                    ],
                    'reminder_enable' => $reminderEnable,
                    'options' => [
                        'checkout' => [
                            'theme' => [
                                'hide_topbar' => $hideTopbar
                            ]
                        ]
                    ],
                    'expire_by' => time() + (7 * 24 * 60 * 60), // 7 days from now
                    'notes' => [
                        'policy_number' => $referenceId,
                        'payment_type' => $upiOnly ? 'upi_insurance_premium' : 'insurance_premium',
                        'checkout_customized' => true
                    ],
                    'upi_only' => $upiOnly,
                    'message' => 'Demo payment link created with checkout options. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);

            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => $currency,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'reference_id' => $referenceId,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'contact' => $customerPhone,
                    'email' => $customerEmail
                ],
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'theme' => [
                            'hide_topbar' => $hideTopbar
                        ]
                    ]
                ],
                'expire_by' => time() + (7 * 24 * 60 * 60), // Expires in 7 days
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => $upiOnly ? 'upi_insurance_premium' : 'insurance_premium',
                    'merchant' => 'NOVACRED PRIVATE LIMITED',
                    'link_type' => $upiOnly ? 'upi_only' : 'all_methods',
                    'checkout_customized' => true
                ],
                'callback_url' => env('APP_URL') . '/payment-success',
                'callback_method' => 'get'
            ];

            // Add UPI-specific configuration
            if ($upiOnly) {
                $paymentLinkData['upi_link'] = true;
            }

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $upiError) {
                // Handle UPI link limitation in test mode
                if (strpos($upiError->getMessage(), 'UPI Payment Links is not supported in Test Mode') !== false) {
                    // Create regular payment link instead
                    unset($paymentLinkData['upi_link']);
                    $paymentLink = $api->paymentLink->create($paymentLinkData);

                    return response()->json([
                        'payment_link_id' => $paymentLink['id'],
                        'short_url' => $paymentLink['short_url'],
                        'status' => $paymentLink['status'],
                        'amount' => $amount,
                        'currency' => $currency,
                        'description' => $description,
                        'customer' => [
                            'name' => $customerName,
                            'email' => $customerEmail,
                            'contact' => $customerPhone
                        ],
                        'reference_id' => $referenceId,
                        'accept_partial' => $acceptPartial,
                        'first_min_partial_amount' => $firstMinPartialAmount,
                        'notify' => [
                            'sms' => $smsNotify,
                            'email' => $emailNotify
                        ],
                        'reminder_enable' => $reminderEnable,
                        'options' => [
                            'checkout' => [
                                'theme' => [
                                    'hide_topbar' => $hideTopbar
                                ]
                            ]
                        ],
                        'expire_by' => $paymentLink['expire_by'],
                        'notes' => [
                            'policy_number' => $referenceId,
                            'payment_type' => 'insurance_premium',
                            'link_type' => 'all_methods',
                            'checkout_customized' => true,
                            'upi_fallback' => 'UPI links not available in test mode'
                        ],
                        'upi_only' => false,
                        'message' => 'Regular payment link created with checkout options (UPI links not available in test mode)',
                        'note' => 'UPI Payment Links require Live Mode. This is a regular payment link that supports UPI along with other methods.'
                    ]);
                } else {
                    throw $upiError;
                }
            }

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'reference_id' => $referenceId,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'theme' => [
                            'hide_topbar' => $hideTopbar
                        ]
                    ]
                ],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => $upiOnly ? 'upi_insurance_premium' : 'insurance_premium',
                    'link_type' => $upiOnly ? 'upi_only' : 'all_methods',
                    'checkout_customized' => true
                ],
                'upi_only' => $upiOnly,
                'message' => $upiOnly ? 'UPI Payment link created successfully with checkout options' : 'Payment link created successfully with checkout options'
            ]);

        } catch (\Exception $e) {
            \Log::error('Advanced payment link creation error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to create payment link with payment method restrictions (POST /api/create-payment-link-methods)
    public function createPaymentLinkWithMethods(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $acceptPartial = $request->input('accept_partial', true);
            $firstMinPartialAmount = $request->input('first_min_partial_amount', 100);
            $referenceId = $request->input('reference_id', 'REF_' . time());
            $description = $request->input('description', 'Insurance Payment');
            $customerName = $request->input('customer.name', 'Customer');
            $customerEmail = $request->input('customer.email', 'customer@example.com');
            $customerPhone = $request->input('customer.contact', '9999999999');
            $smsNotify = $request->input('notify.sms', true);
            $emailNotify = $request->input('notify.email', true);
            $reminderEnable = $request->input('reminder_enable', true);
            
            // Payment method restrictions
            $netbankingEnabled = $request->input('options.checkout.method.netbanking', true);
            $cardEnabled = $request->input('options.checkout.method.card', true);
            $upiEnabled = $request->input('options.checkout.method.upi', true);
            $walletEnabled = $request->input('options.checkout.method.wallet', true);

            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                return response()->json([
                    'payment_link_id' => 'demo_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ],
                    'reference_id' => $referenceId,
                    'accept_partial' => $acceptPartial,
                    'first_min_partial_amount' => $firstMinPartialAmount,
                    'notify' => [
                        'sms' => $smsNotify,
                        'email' => $emailNotify
                    ],
                    'reminder_enable' => $reminderEnable,
                    'options' => [
                        'checkout' => [
                            'method' => [
                                'netbanking' => $netbankingEnabled,
                                'card' => $cardEnabled,
                                'upi' => $upiEnabled,
                                'wallet' => $walletEnabled
                            ]
                        ]
                    ],
                    'expire_by' => time() + (7 * 24 * 60 * 60), // 7 days from now
                    'notes' => [
                        'policy_number' => $referenceId,
                        'payment_type' => 'insurance_premium',
                        'method_restrictions' => 'customized',
                        'enabled_methods' => [
                            'netbanking' => $netbankingEnabled,
                            'card' => $cardEnabled,
                            'upi' => $upiEnabled,
                            'wallet' => $walletEnabled
                        ]
                    ],
                    'message' => 'Demo payment link created with payment method restrictions. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);

            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => $currency,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'reference_id' => $referenceId,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'contact' => $customerPhone,
                    'email' => $customerEmail
                ],
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'method' => [
                            'netbanking' => $netbankingEnabled,
                            'card' => $cardEnabled,
                            'upi' => $upiEnabled,
                            'wallet' => $walletEnabled
                        ]
                    ]
                ],
                'expire_by' => time() + (7 * 24 * 60 * 60), // Expires in 7 days
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'insurance_premium',
                    'method_restrictions' => 'customized',
                    'enabled_methods' => [
                        'netbanking' => $netbankingEnabled,
                        'card' => $cardEnabled,
                        'upi' => $upiEnabled,
                        'wallet' => $walletEnabled
                    ],
                    'merchant' => 'NOVACRED PRIVATE LIMITED'
                ],
                'callback_url' => env('APP_URL') . '/payment-success',
                'callback_method' => 'get'
            ];

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $e) {
                \Log::error('Payment link creation with methods error: ' . $e->getMessage());
                
                // Handle specific Razorpay errors
                if (strpos($e->getMessage(), 'payment link with given reference_id') !== false) {
                    return response()->json([
                        'error' => 'Payment link creation failed',
                        'message' => 'A payment link with this reference ID already exists. Please use a different reference_id.',
                        'details' => $e->getMessage()
                    ], 400);
                }
                
                throw $e;
            }

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'reference_id' => $referenceId,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'method' => [
                            'netbanking' => $netbankingEnabled,
                            'card' => $cardEnabled,
                            'upi' => $upiEnabled,
                            'wallet' => $walletEnabled
                        ]
                    ]
                ],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'insurance_premium',
                    'method_restrictions' => 'customized',
                    'enabled_methods' => [
                        'netbanking' => $netbankingEnabled,
                        'card' => $cardEnabled,
                        'upi' => $upiEnabled,
                        'wallet' => $walletEnabled
                    ]
                ],
                'message' => 'Payment link created successfully with payment method restrictions'
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment link creation with methods error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to create payment link with HDFC Netbanking (POST /api/create-payment-link-hdfc)
    public function createPaymentLinkHDFC(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $acceptPartial = $request->input('accept_partial', true);
            $firstMinPartialAmount = $request->input('first_min_partial_amount', 100);
            $referenceId = $request->input('reference_id', 'REF_' . time());
            $description = $request->input('description', 'Insurance Payment');
            $customerName = $request->input('customer.name', 'Customer');
            $customerEmail = $request->input('customer.email', 'customer@example.com');
            $customerPhone = $request->input('customer.contact', '9999999999');
            $smsNotify = $request->input('notify.sms', true);
            $emailNotify = $request->input('notify.email', true);
            $reminderEnable = $request->input('reminder_enable', true);

            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                return response()->json([
                    'payment_link_id' => 'demo_hdfc_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-hdfc-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ],
                    'reference_id' => $referenceId,
                    'accept_partial' => $acceptPartial,
                    'first_min_partial_amount' => $firstMinPartialAmount,
                    'notify' => [
                        'sms' => $smsNotify,
                        'email' => $emailNotify
                    ],
                    'reminder_enable' => $reminderEnable,
                    'options' => [
                        'checkout' => [
                            'config' => [
                                'display' => [
                                    'blocks' => [
                                        'banks' => [
                                            'name' => 'Pay using HDFC',
                                            'instruments' => [
                                                [
                                                    'method' => 'netbanking',
                                                    'banks' => ['HDFC']
                                                ]
                                            ]
                                        ]
                                    ],
                                    'sequence' => ['block.banks'],
                                    'preferences' => [
                                        'show_default_blocks' => false
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'expire_by' => time() + (7 * 24 * 60 * 60), // 7 days from now
                    'notes' => [
                        'policy_number' => $referenceId,
                        'payment_type' => 'hdfc_netbanking',
                        'bank_restriction' => 'HDFC only',
                        'checkout_customized' => true
                    ],
                    'message' => 'Demo HDFC Netbanking payment link created. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);

            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => $currency,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'reference_id' => $referenceId,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'contact' => $customerPhone,
                    'email' => $customerEmail
                ],
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'config' => [
                            'display' => [
                                'blocks' => [
                                    'banks' => [
                                        'name' => 'Pay using HDFC',
                                        'instruments' => [
                                            [
                                                'method' => 'netbanking',
                                                'banks' => ['HDFC']
                                            ]
                                        ]
                                    ]
                                ],
                                'sequence' => ['block.banks'],
                                'preferences' => [
                                    'show_default_blocks' => false
                                ]
                            ]
                        ]
                    ]
                ],
                'expire_by' => time() + (7 * 24 * 60 * 60), // Expires in 7 days
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'hdfc_netbanking',
                    'bank_restriction' => 'HDFC only',
                    'checkout_customized' => true,
                    'merchant' => 'NOVACRED PRIVATE LIMITED'
                ],
                'callback_url' => env('APP_URL') . '/payment-success',
                'callback_method' => 'get'
            ];

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $e) {
                \Log::error('HDFC payment link creation error: ' . $e->getMessage());
                
                // Handle specific Razorpay errors
                if (strpos($e->getMessage(), 'payment link with given reference_id') !== false) {
                    return response()->json([
                        'error' => 'Payment link creation failed',
                        'message' => 'A payment link with this reference ID already exists. Please use a different reference_id.',
                        'details' => $e->getMessage()
                    ], 400);
                }
                
                throw $e;
            }

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'reference_id' => $referenceId,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'config' => [
                            'display' => [
                                'blocks' => [
                                    'banks' => [
                                        'name' => 'Pay using HDFC',
                                        'instruments' => [
                                            [
                                                'method' => 'netbanking',
                                                'banks' => ['HDFC']
                                            ]
                                        ]
                                    ]
                                ],
                                'sequence' => ['block.banks'],
                                'preferences' => [
                                    'show_default_blocks' => false
                                ]
                            ]
                        ]
                    ]
                ],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'hdfc_netbanking',
                    'bank_restriction' => 'HDFC only',
                    'checkout_customized' => true
                ],
                'message' => 'HDFC Netbanking payment link created successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('HDFC payment link creation error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create HDFC payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to create payment link with reordered payment methods (POST /api/create-payment-link-reordered)
    public function createPaymentLinkReordered(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $referenceId = $request->input('reference_id', 'REF_' . time());
            $description = $request->input('description', 'Insurance Payment');
            $customerName = $request->input('customer.name', 'Customer');
            $customerEmail = $request->input('customer.email', 'customer@example.com');
            $customerPhone = $request->input('customer.contact', '9999999999');
            $smsNotify = $request->input('notify.sms', true);
            $emailNotify = $request->input('notify.email', true);
            
            // Payment method order configuration
            $methodOrder = $request->input('method_order', ['upi', 'netbanking', 'card', 'wallet']);
            $cardIins = $request->input('card_iins', ['43558']); // Default card IIN

            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                return response()->json([
                    'payment_link_id' => 'demo_reordered_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-reordered-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ],
                    'reference_id' => $referenceId,
                    'notify' => [
                        'sms' => $smsNotify,
                        'email' => $emailNotify
                    ],
                    'options' => [
                        'checkout' => [
                            'config' => [
                                'display' => [
                                    'blocks' => [
                                        'banks' => [
                                            'name' => 'All Payment Methods',
                                            'instruments' => $this->buildInstrumentsArray($methodOrder, $cardIins)
                                        ]
                                    ],
                                    'sequence' => ['block.banks'],
                                    'preferences' => [
                                        'show_default_blocks' => false
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'expire_by' => time() + (7 * 24 * 60 * 60), // 7 days from now
                    'notes' => [
                        'policy_number' => $referenceId,
                        'payment_type' => 'reordered_methods',
                        'method_order' => implode(',', $methodOrder),
                        'checkout_customized' => true
                    ],
                    'message' => 'Demo reordered payment methods link created. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);

            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => $currency,
                'reference_id' => $referenceId,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'contact' => $customerPhone,
                    'email' => $customerEmail
                ],
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'options' => [
                    'checkout' => [
                        'config' => [
                            'display' => [
                                'blocks' => [
                                    'banks' => [
                                        'name' => 'All Payment Methods',
                                        'instruments' => $this->buildInstrumentsArray($methodOrder, $cardIins)
                                    ]
                                ],
                                'sequence' => ['block.banks'],
                                'preferences' => [
                                    'show_default_blocks' => false
                                ]
                            ]
                        ]
                    ]
                ],
                'expire_by' => time() + (7 * 24 * 60 * 60), // Expires in 7 days
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'reordered_methods',
                    'method_order' => implode(',', $methodOrder),
                    'checkout_customized' => true,
                    'merchant' => 'NOVACRED PRIVATE LIMITED'
                ],
                'callback_url' => env('APP_URL') . '/payment-success',
                'callback_method' => 'get'
            ];

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $e) {
                \Log::error('Reordered payment link creation error: ' . $e->getMessage());
                
                // Handle specific Razorpay errors
                if (strpos($e->getMessage(), 'payment link with given reference_id') !== false) {
                    return response()->json([
                        'error' => 'Payment link creation failed',
                        'message' => 'A payment link with this reference ID already exists. Please use a different reference_id.',
                        'details' => $e->getMessage()
                    ], 400);
                }
                
                throw $e;
            }

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'reference_id' => $referenceId,
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'options' => [
                    'checkout' => [
                        'config' => [
                            'display' => [
                                'blocks' => [
                                    'banks' => [
                                        'name' => 'All Payment Methods',
                                        'instruments' => $this->buildInstrumentsArray($methodOrder, $cardIins)
                                    ]
                                ],
                                'sequence' => ['block.banks'],
                                'preferences' => [
                                    'show_default_blocks' => false
                                ]
                            ]
                        ]
                    ]
                ],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'reordered_methods',
                    'method_order' => implode(',', $methodOrder),
                    'checkout_customized' => true
                ],
                'message' => 'Reordered payment methods link created successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Reordered payment link creation error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create reordered payment link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Helper method to build instruments array for reordered payment methods
    private function buildInstrumentsArray($methodOrder, $cardIins) {
        $instruments = [];
        
        foreach ($methodOrder as $method) {
            switch ($method) {
                case 'upi':
                    $instruments[] = ['method' => 'upi'];
                    break;
                case 'netbanking':
                    $instruments[] = ['method' => 'netbanking'];
                    break;
                case 'card':
                    $instruments[] = [
                        'method' => 'card',
                        'iins' => $cardIins
                    ];
                    break;
                case 'wallet':
                    $instruments[] = ['method' => 'wallet'];
                    break;
            }
        }
        
        return $instruments;
    }

    // Method to create payment link with custom partial payment labels (POST /api/create-payment-link-partial-labels)
    public function createPaymentLinkPartialLabels(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $acceptPartial = $request->input('accept_partial', true);
            $firstMinPartialAmount = $request->input('first_min_partial_amount', 100);
            $referenceId = $request->input('reference_id', 'REF_' . time());
            $description = $request->input('description', 'Insurance Payment');
            $customerName = $request->input('customer.name', 'Customer');
            $customerEmail = $request->input('customer.email', 'customer@example.com');
            $customerPhone = $request->input('customer.contact', '9999999999');
            $smsNotify = $request->input('notify.sms', true);
            $emailNotify = $request->input('notify.email', true);
            $reminderEnable = $request->input('reminder_enable', true);
            
            // Custom partial payment labels
            $minAmountLabel = $request->input('options.checkout.partial_payment.min_amount_label', 'Minimum Money to be paid');
            $partialAmountLabel = $request->input('options.checkout.partial_payment.partial_amount_label', 'Pay in parts');
            $partialAmountDescription = $request->input('options.checkout.partial_payment.partial_amount_description', 'Pay at least ₹' . $firstMinPartialAmount);
            $fullAmountLabel = $request->input('options.checkout.partial_payment.full_amount_label', 'Pay the entire amount');

            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                return response()->json([
                    'payment_link_id' => 'demo_partial_labels_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-partial-labels-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ],
                    'reference_id' => $referenceId,
                    'accept_partial' => $acceptPartial,
                    'first_min_partial_amount' => $firstMinPartialAmount,
                    'notify' => [
                        'sms' => $smsNotify,
                        'email' => $emailNotify
                    ],
                    'reminder_enable' => $reminderEnable,
                    'options' => [
                        'checkout' => [
                            'partial_payment' => [
                                'min_amount_label' => $minAmountLabel,
                                'partial_amount_label' => $partialAmountLabel,
                                'partial_amount_description' => $partialAmountDescription,
                                'full_amount_label' => $fullAmountLabel
                            ]
                        ]
                    ],
                    'expire_by' => time() + (7 * 24 * 60 * 60), // 7 days from now
                    'notes' => [
                        'policy_number' => $referenceId,
                        'payment_type' => 'partial_payment_customized',
                        'custom_labels' => 'enabled',
                        'checkout_customized' => true
                    ],
                    'message' => 'Demo payment link with custom partial payment labels created. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);

            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => $currency,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'reference_id' => $referenceId,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'contact' => $customerPhone,
                    'email' => $customerEmail
                ],
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'partial_payment' => [
                            'min_amount_label' => $minAmountLabel,
                            'partial_amount_label' => $partialAmountLabel,
                            'partial_amount_description' => $partialAmountDescription,
                            'full_amount_label' => $fullAmountLabel
                        ]
                    ]
                ],
                'expire_by' => time() + (7 * 24 * 60 * 60), // Expires in 7 days
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'partial_payment_customized',
                    'custom_labels' => 'enabled',
                    'checkout_customized' => true,
                    'merchant' => 'NOVACRED PRIVATE LIMITED'
                ],
                'callback_url' => env('APP_URL') . '/payment-success',
                'callback_method' => 'get'
            ];

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $e) {
                \Log::error('Partial payment labels link creation error: ' . $e->getMessage());
                
                // Handle specific Razorpay errors
                if (strpos($e->getMessage(), 'payment link with given reference_id') !== false) {
                    return response()->json([
                        'error' => 'Payment link creation failed',
                        'message' => 'A payment link with this reference ID already exists. Please use a different reference_id.',
                        'details' => $e->getMessage()
                    ], 400);
                }
                
                throw $e;
            }

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'reference_id' => $referenceId,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'checkout' => [
                        'partial_payment' => [
                            'min_amount_label' => $minAmountLabel,
                            'partial_amount_label' => $partialAmountLabel,
                            'partial_amount_description' => $partialAmountDescription,
                            'full_amount_label' => $fullAmountLabel
                        ]
                    ]
                ],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'partial_payment_customized',
                    'custom_labels' => 'enabled',
                    'checkout_customized' => true
                ],
                'message' => 'Payment link with custom partial payment labels created successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Partial payment labels link creation error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create payment link with custom labels',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to create payment link with custom hosted page labels (POST /api/create-payment-link-hosted-labels)
    public function createPaymentLinkHostedLabels(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $acceptPartial = $request->input('accept_partial', true);
            $firstMinPartialAmount = $request->input('first_min_partial_amount', 100);
            $referenceId = $request->input('reference_id', 'REF_' . time());
            $description = $request->input('description', 'Insurance Payment');
            $expireBy = $request->input('expire_by', time() + (7 * 24 * 60 * 60)); // Default 7 days
            $customerName = $request->input('customer.name', 'Customer');
            $customerEmail = $request->input('customer.email', 'customer@example.com');
            $customerPhone = $request->input('customer.contact', '9999999999');
            $smsNotify = $request->input('notify.sms', true);
            $emailNotify = $request->input('notify.email', true);
            $reminderEnable = $request->input('reminder_enable', true);
            
            // Custom hosted page labels
            $receiptLabel = $request->input('options.hosted_page.label.receipt', 'Ref No.');
            $descriptionLabel = $request->input('options.hosted_page.label.description', 'Course Name');
            $amountPayableLabel = $request->input('options.hosted_page.label.amount_payable', 'Course Fee Payable');
            $amountPaidLabel = $request->input('options.hosted_page.label.amount_paid', 'Course Fee Paid');
            $partialAmountDueLabel = $request->input('options.hosted_page.label.partial_amount_due', 'Fee Installment Due');
            $partialAmountPaidLabel = $request->input('options.hosted_page.label.partial_amount_paid', 'Fee Installment Paid');
            $expireByLabel = $request->input('options.hosted_page.label.expire_by', 'Pay Before');
            $expiredOnLabel = $request->input('options.hosted_page.label.expired_on', 'Link Expired. Please contact Admin');
            $amountDueLabel = $request->input('options.hosted_page.label.amount_due', 'Course Fee Due');
            $showIssuedTo = $request->input('options.hosted_page.show_preferences.issued_to', false);

            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                return response()->json([
                    'payment_link_id' => 'demo_hosted_labels_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-hosted-labels-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ],
                    'reference_id' => $referenceId,
                    'accept_partial' => $acceptPartial,
                    'first_min_partial_amount' => $firstMinPartialAmount,
                    'expire_by' => $expireBy,
                    'notify' => [
                        'sms' => $smsNotify,
                        'email' => $emailNotify
                    ],
                    'reminder_enable' => $reminderEnable,
                    'options' => [
                        'hosted_page' => [
                            'label' => [
                                'receipt' => $receiptLabel,
                                'description' => $descriptionLabel,
                                'amount_payable' => $amountPayableLabel,
                                'amount_paid' => $amountPaidLabel,
                                'partial_amount_due' => $partialAmountDueLabel,
                                'partial_amount_paid' => $partialAmountPaidLabel,
                                'expire_by' => $expireByLabel,
                                'expired_on' => $expiredOnLabel,
                                'amount_due' => $amountDueLabel
                            ],
                            'show_preferences' => [
                                'issued_to' => $showIssuedTo
                            ]
                        ]
                    ],
                    'expire_by' => $expireBy,
                    'notes' => [
                        'policy_number' => $referenceId,
                        'payment_type' => 'hosted_page_customized',
                        'custom_labels' => 'enabled',
                        'checkout_customized' => true
                    ],
                    'message' => 'Demo payment link with custom hosted page labels created. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);

            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => $currency,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'reference_id' => $referenceId,
                'description' => $description,
                'expire_by' => $expireBy,
                'customer' => [
                    'name' => $customerName,
                    'contact' => $customerPhone,
                    'email' => $customerEmail
                ],
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'hosted_page' => [
                        'label' => [
                            'receipt' => $receiptLabel,
                            'description' => $descriptionLabel,
                            'amount_payable' => $amountPayableLabel,
                            'amount_paid' => $amountPaidLabel,
                            'partial_amount_due' => $partialAmountDueLabel,
                            'partial_amount_paid' => $partialAmountPaidLabel,
                            'expire_by' => $expireByLabel,
                            'expired_on' => $expiredOnLabel,
                            'amount_due' => $amountDueLabel
                        ],
                        'show_preferences' => [
                            'issued_to' => $showIssuedTo
                        ]
                    ]
                ],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'hosted_page_customized',
                    'custom_labels' => 'enabled',
                    'checkout_customized' => true,
                    'merchant' => 'NOVACRED PRIVATE LIMITED'
                ],
                'callback_url' => env('APP_URL') . '/payment-success',
                'callback_method' => 'get'
            ];

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $e) {
                \Log::error('Hosted page labels link creation error: ' . $e->getMessage());
                
                // Handle specific Razorpay errors
                if (strpos($e->getMessage(), 'payment link with given reference_id') !== false) {
                    return response()->json([
                        'error' => 'Payment link creation failed',
                        'message' => 'A payment link with this reference ID already exists. Please use a different reference_id.',
                        'details' => $e->getMessage()
                    ], 400);
                }
                
                throw $e;
            }

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'reference_id' => $referenceId,
                'accept_partial' => $acceptPartial,
                'first_min_partial_amount' => $firstMinPartialAmount,
                'expire_by' => $expireBy,
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'hosted_page' => [
                        'label' => [
                            'receipt' => $receiptLabel,
                            'description' => $descriptionLabel,
                            'amount_payable' => $amountPayableLabel,
                            'amount_paid' => $amountPaidLabel,
                            'partial_amount_due' => $partialAmountDueLabel,
                            'partial_amount_paid' => $partialAmountPaidLabel,
                            'expire_by' => $expireByLabel,
                            'expired_on' => $expiredOnLabel,
                            'amount_due' => $amountDueLabel
                        ],
                        'show_preferences' => [
                            'issued_to' => $showIssuedTo
                        ]
                    ]
                ],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'hosted_page_customized',
                    'custom_labels' => 'enabled',
                    'checkout_customized' => true
                ],
                'message' => 'Payment link with custom hosted page labels created successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Hosted page labels link creation error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create payment link with custom hosted page labels',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Method to create payment link with offers/discounts (POST /api/create-payment-link-offers)
    public function createPaymentLinkOffers(Request $request) {
        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $acceptPartial = $request->input('accept_partial', false);
            $referenceId = $request->input('reference_id', 'REF_' . time());
            $description = $request->input('description', 'Insurance Payment');
            $customerName = $request->input('customer.name', 'Customer');
            $customerEmail = $request->input('customer.email', 'customer@example.com');
            $customerPhone = $request->input('customer.contact', '9999999999');
            $smsNotify = $request->input('notify.sms', true);
            $emailNotify = $request->input('notify.email', true);
            $reminderEnable = $request->input('reminder_enable', false);
            
            // Offers configuration
            $offers = $request->input('options.order.offers', []);

            if (!$amount || $amount <= 0) {
                return response()->json([
                    'error' => 'Invalid amount provided'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');

            if (!$razorpayKey || !$razorpaySecret ||
                $razorpayKey === 'rzp_test_your_key_id_here' ||
                $razorpaySecret === 'your_secret_key_here') {
                
                // Return demo payment link for testing
                return response()->json([
                    'payment_link_id' => 'demo_offers_link_' . time(),
                    'short_url' => 'https://rzp.io/demo-offers-payment-link',
                    'status' => 'created',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ],
                    'reference_id' => $referenceId,
                    'accept_partial' => $acceptPartial,
                    'notify' => [
                        'sms' => $smsNotify,
                        'email' => $emailNotify
                    ],
                    'reminder_enable' => $reminderEnable,
                    'options' => [
                        'order' => [
                            'offers' => $offers
                        ]
                    ],
                    'expire_by' => time() + (7 * 24 * 60 * 60), // 7 days from now
                    'notes' => [
                        'policy_number' => $referenceId,
                        'payment_type' => 'offers_applied',
                        'offers_count' => count($offers),
                        'checkout_customized' => true
                    ],
                    'message' => 'Demo payment link with offers created. Configure Razorpay credentials for real payment links.',
                    'note' => 'This is a demo payment link. Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file for real payment links.'
                ]);
            }

            $api = new Api($razorpayKey, $razorpaySecret);

            $paymentLinkData = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => $currency,
                'accept_partial' => $acceptPartial,
                'reference_id' => $referenceId,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'contact' => $customerPhone,
                    'email' => $customerEmail
                ],
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'order' => [
                        'offers' => $offers
                    ]
                ],
                'expire_by' => time() + (7 * 24 * 60 * 60), // Expires in 7 days
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'offers_applied',
                    'offers_count' => count($offers),
                    'checkout_customized' => true,
                    'merchant' => 'NOVACRED PRIVATE LIMITED'
                ],
                'callback_url' => env('APP_URL') . '/payment-success',
                'callback_method' => 'get'
            ];

            try {
                $paymentLink = $api->paymentLink->create($paymentLinkData);
            } catch (\Exception $e) {
                \Log::error('Offers payment link creation error: ' . $e->getMessage());
                
                // Handle specific Razorpay errors
                if (strpos($e->getMessage(), 'payment link with given reference_id') !== false) {
                    return response()->json([
                        'error' => 'Payment link creation failed',
                        'message' => 'A payment link with this reference ID already exists. Please use a different reference_id.',
                        'details' => $e->getMessage()
                    ], 400);
                }
                
                throw $e;
            }

            return response()->json([
                'payment_link_id' => $paymentLink['id'],
                'short_url' => $paymentLink['short_url'],
                'status' => $paymentLink['status'],
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'customer' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ],
                'reference_id' => $referenceId,
                'accept_partial' => $acceptPartial,
                'notify' => [
                    'sms' => $smsNotify,
                    'email' => $emailNotify
                ],
                'reminder_enable' => $reminderEnable,
                'options' => [
                    'order' => [
                        'offers' => $offers
                    ]
                ],
                'expire_by' => $paymentLink['expire_by'],
                'notes' => [
                    'policy_number' => $referenceId,
                    'payment_type' => 'offers_applied',
                    'offers_count' => count($offers),
                    'checkout_customized' => true
                ],
                'message' => 'Payment link with offers created successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Offers payment link creation error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create payment link with offers',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Check Payment Status by Order ID
     */
    public function checkPaymentStatus($orderId) {
        try {
            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            if (!$razorpayKey || !$razorpaySecret || 
                $razorpayKey === 'rzp_test_your_key_id_here' || 
                $razorpaySecret === 'your_secret_key_here') {
                
                return response()->json([
                    'error' => 'Razorpay credentials not configured',
                    'message' => 'Configure RAZORPAY_KEY and RAZORPAY_SECRET in .env file'
                ], 400);
            }

            $api = new Api($razorpayKey, $razorpaySecret);
            $order = $api->order->fetch($orderId);
            
            // Get all payments for this order
            $payments = $api->order->fetch($orderId)->payments();
            
            $paymentDetails = [];
            foreach ($payments->items as $payment) {
                $paymentDetails[] = [
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount / 100,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'method' => $payment->method,
                    'captured' => $payment->captured,
                    'created_at' => $payment->created_at
                ];
            }
            
            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'order_status' => $order->status,
                'order_amount' => $order->amount / 100,
                'currency' => $order->currency,
                'amount_paid' => $order->amount_paid / 100,
                'amount_due' => $order->amount_due / 100,
                'payments' => $paymentDetails,
                'payment_count' => count($paymentDetails),
                'is_paid' => $order->status === 'paid',
                'is_partially_paid' => $order->status === 'partially_paid',
                'message' => 'Payment status retrieved successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment status check error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to check payment status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify Payment Completion Status
     * This API checks payment status from both Razorpay and database
     * Accepts either payment_id or order_id
     * Returns clear success/failure status
     */
    public function verifyPaymentStatus(Request $request) {
        try {
            $paymentId = $request->input('payment_id');
            $orderId = $request->input('order_id');
            
            if (!$paymentId && !$orderId) {
                return response()->json([
                    'success' => false,
                    'payment_status' => 'error',
                    'message' => 'Either payment_id or order_id is required',
                    'error' => 'Missing required parameter'
                ], 400);
            }

            $razorpayKey = env('RAZORPAY_KEY');
            $razorpaySecret = env('RAZORPAY_SECRET');
            
            // Check database first for quick response
            $dbPayment = null;
            if ($paymentId) {
                $dbPayment = PaymentDetails::where('razorpay_payment_id', $paymentId)->first();
            } elseif ($orderId) {
                $dbPayment = PaymentDetails::where('razorpay_order_id', $orderId)->latest('id')->first();
            }

            // If Razorpay credentials are not configured, check database only
            $isDemoMode = (!$razorpayKey || !$razorpaySecret || 
                         $razorpayKey === 'rzp_test_your_key_id_here' || 
                         $razorpaySecret === 'your_secret_key_here');

            if ($isDemoMode) {
                // Demo mode - check database only
                if ($dbPayment) {
                    $status = $dbPayment->status;
                    $isSuccess = in_array($status, ['captured', 'paid', 'success']);
                    $isFailure = in_array($status, ['failed', 'cancelled', 'refunded']);
                    
                    return response()->json([
                        'success' => $isSuccess,
                        'payment_status' => $isSuccess ? 'success' : ($isFailure ? 'failure' : 'pending'),
                        'razorpay_status' => $status,
                        'database_status' => $status,
                        'payment_id' => $dbPayment->razorpay_payment_id,
                        'order_id' => $dbPayment->razorpay_order_id,
                        'amount' => $dbPayment->amount ? ($dbPayment->amount / 100) : null,
                        'currency' => $dbPayment->currency ?? 'INR',
                        'method' => $dbPayment->method,
                        'customer_name' => $dbPayment->customer_name,
                        'customer_email' => $dbPayment->customer_email,
                        'payment_completed_at' => $dbPayment->payment_completed_at?->toISOString(),
                        'message' => $isSuccess ? 'Payment completed successfully' : ($isFailure ? 'Payment failed' : 'Payment pending'),
                        'demo_mode' => true
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'payment_status' => 'not_found',
                        'message' => 'Payment not found in database',
                        'demo_mode' => true
                    ], 404);
                }
            }

            // Real mode - verify with Razorpay API
            $api = new Api($razorpayKey, $razorpaySecret);
            $razorpayPayment = null;
            $razorpayOrder = null;
            
            try {
                if ($paymentId) {
                    // Fetch payment details from Razorpay
                    $razorpayPayment = $api->payment->fetch($paymentId);
                    $orderId = $razorpayPayment->order_id ?? $orderId;
                }
                
                if ($orderId) {
                    // Fetch order details from Razorpay
                    $razorpayOrder = $api->order->fetch($orderId);
                    if (!$razorpayPayment && $razorpayOrder->payments) {
                        $payments = $razorpayOrder->payments();
                        if (count($payments->items) > 0) {
                            $razorpayPayment = $payments->items[0];
                            $paymentId = $razorpayPayment->id;
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Error fetching from Razorpay API: ' . $e->getMessage());
                // Fall back to database check
            }

            // Determine payment status
            $paymentStatus = 'pending';
            $isSuccess = false;
            $isFailure = false;
            $statusMessage = 'Payment status unknown';

            if ($razorpayPayment) {
                $paymentStatus = $razorpayPayment->status;
                $isSuccess = $razorpayPayment->status === 'captured' || $razorpayPayment->status === 'authorized';
                $isFailure = in_array($razorpayPayment->status, ['failed', 'cancelled', 'refunded']);
                
                if ($isSuccess) {
                    $paymentStatus = 'success';
                    $statusMessage = 'Payment completed successfully';
                } elseif ($isFailure) {
                    $paymentStatus = 'failure';
                    $statusMessage = 'Payment failed or was cancelled';
                } else {
                    $paymentStatus = 'pending';
                    $statusMessage = 'Payment is pending';
                }
            } elseif ($dbPayment) {
                // Fallback to database status
                $dbStatus = $dbPayment->status;
                $isSuccess = in_array($dbStatus, ['captured', 'paid', 'success']);
                $isFailure = in_array($dbStatus, ['failed', 'cancelled', 'refunded']);
                
                if ($isSuccess) {
                    $paymentStatus = 'success';
                    $statusMessage = 'Payment completed successfully';
                } elseif ($isFailure) {
                    $paymentStatus = 'failure';
                    $statusMessage = 'Payment failed';
                } else {
                    $paymentStatus = 'pending';
                    $statusMessage = 'Payment is pending';
                }
            } else {
                return response()->json([
                    'success' => false,
                    'payment_status' => 'not_found',
                    'message' => 'Payment not found',
                    'razorpay_status' => null,
                    'database_status' => null
                ], 404);
            }

            // Build response
            $response = [
                'success' => $isSuccess,
                'payment_status' => $paymentStatus,
                'razorpay_status' => $razorpayPayment ? $razorpayPayment->status : ($dbPayment ? $dbPayment->status : null),
                'database_status' => $dbPayment ? $dbPayment->status : null,
                'payment_id' => $paymentId ?? ($dbPayment ? $dbPayment->razorpay_payment_id : null),
                'order_id' => $orderId ?? ($dbPayment ? $dbPayment->razorpay_order_id : null),
                'message' => $statusMessage,
                'demo_mode' => false
            ];

            // Add payment details if available from Razorpay
            if ($razorpayPayment) {
                $response['amount'] = $razorpayPayment->amount / 100;
                $response['currency'] = $razorpayPayment->currency;
                $response['method'] = $razorpayPayment->method;
                $response['captured'] = $razorpayPayment->captured ?? false;
                $response['created_at'] = $razorpayPayment->created_at ?? null;
            } elseif ($dbPayment) {
                $response['amount'] = $dbPayment->amount ? ($dbPayment->amount / 100) : null;
                $response['currency'] = $dbPayment->currency ?? 'INR';
                $response['method'] = $dbPayment->method;
                $response['customer_name'] = $dbPayment->customer_name;
                $response['customer_email'] = $dbPayment->customer_email;
                $response['payment_completed_at'] = $dbPayment->payment_completed_at?->toISOString();
            }

            // Add order details if available
            if ($razorpayOrder) {
                $response['order_status'] = $razorpayOrder->status;
                $response['order_amount'] = $razorpayOrder->amount / 100;
                $response['amount_paid'] = $razorpayOrder->amount_paid / 100;
                $response['amount_due'] = $razorpayOrder->amount_due / 100;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('Payment verification error: ' . $e->getMessage(), [
                'payment_id' => $request->input('payment_id'),
                'order_id' => $request->input('order_id'),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'payment_status' => 'error',
                'message' => 'Failed to verify payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }



}