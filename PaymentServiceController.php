<?php

namespace App\PaymentService\Http\Controllers;

use App\Http\Controllers\Controller;
use App\PaymentService\Services\RazorpayService;
use App\Models\PaymentDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Payment Service Controller
 * 
 * Standalone payment service endpoints that can be used by any application.
 * These endpoints are independent of business logic and focus solely on payment operations.
 */
class PaymentServiceController extends Controller
{
    protected $paymentService;

    public function __construct(RazorpayService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Show Payment Method Selection Page
     * GET /payment-service/payment-method-selection
     * GET /payment-service/payment-page
     */
    public function showPaymentMethodSelection(Request $request)
    {
        // Get parameters from query string
        $orderId = $request->query('order_id', 'demo_order_' . time());
        $amount = $request->query('amount', 1000);
        $customerName = $request->query('customer_name', 'Customer');
        $customerEmail = $request->query('customer_email', 'customer@example.com');
        $customerPhone = $request->query('customer_phone', '');
        $description = $request->query('description', 'Payment');
        $razorpayKey = $this->paymentService->getKeyId() ?? 'rzp_test_demo';
        
        // Get callback URLs
        $baseUrl = $request->getSchemeAndHttpHost();
        $successUrl = $request->query('success_url', $baseUrl . '/payment-service/payment-success-callback');
        $failureUrl = $request->query('failure_url', $baseUrl . '/payment-service/payment-failure-callback');
        
        return view('payment-service.payment-method-selection', compact(
            'orderId',
            'amount',
            'customerName',
            'customerEmail',
            'customerPhone',
            'description',
            'razorpayKey',
            'successUrl',
            'failureUrl'
        ));
    }

    /**
     * Create Payment Order
     * POST /payment-service/orders
     */
    public function createOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|size:3',
            'receipt' => 'sometimes|string|max:255',
            'payment_capture' => 'sometimes|boolean',
            'notes' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $orderData = [
            'amount' => $request->input('amount'),
            'currency' => $request->input('currency', 'INR'),
            'receipt' => $request->input('receipt', 'order_' . time()),
            'payment_capture' => $request->input('payment_capture', 1),
            'notes' => $request->input('notes', []),
        ];

        $result = $this->paymentService->createOrder($orderData);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'order' => $result['data'],
                'key_id' => $this->paymentService->getKeyId(),
                'demo_mode' => $this->paymentService->isDemoMode(),
                'message' => $this->paymentService->isDemoMode() ? 
                    'Demo order created. Configure Razorpay credentials for real payments.' : 
                    'Order created successfully'
            ], 201);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to create order'
        ], 400);
    }

    /**
     * Verify Payment
     * POST /payment-service/verify
     */
    public function verifyPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|string',
            'payment_id' => 'required|string',
            'signature' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $this->paymentService->verifyPayment(
            $request->input('order_id'),
            $request->input('payment_id'),
            $request->input('signature')
        );

        $statusCode = $result['success'] && $result['verified'] ? 200 : 400;

        return response()->json([
            'success' => $result['success'],
            'verified' => $result['verified'] ?? false,
            'message' => $result['message'] ?? ($result['verified'] ? 'Payment verified' : 'Payment verification failed'),
            'error' => $result['error'] ?? null,
            'demo_mode' => $this->paymentService->isDemoMode()
        ], $statusCode);
    }

    /**
     * Fetch Payment Details
     * GET /payment-service/payments/{paymentId}
     */
    public function fetchPayment($paymentId)
    {
        $result = $this->paymentService->fetchPayment($paymentId);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'payment' => $result['data']
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to fetch payment'
        ], 404);
    }

    /**
     * Fetch Order Details
     * GET /payment-service/orders/{orderId}
     */
    public function fetchOrder($orderId)
    {
        $result = $this->paymentService->fetchOrder($orderId);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'order' => $result['data']
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to fetch order'
        ], 404);
    }

    /**
     * Create Payment Link
     * POST /payment-service/payment-links
     */
    public function createPaymentLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|size:3',
            'description' => 'sometimes|string|max:255',
            'customer' => 'sometimes|array',
            'customer.name' => 'required_with:customer|string|max:255',
            'customer.email' => 'required_with:customer|email|max:255',
            'customer.contact' => 'sometimes|string|max:20',
            'notify' => 'sometimes|array',
            'reminder_enable' => 'sometimes|boolean',
            'notes' => 'sometimes|array',
            'callback_url' => 'sometimes|url',
            'callback_method' => 'sometimes|string|in:get,post',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $linkData = [
            'amount' => $request->input('amount') * 100, // Convert to paise
            'currency' => $request->input('currency', 'INR'),
            'description' => $request->input('description', 'Payment Link'),
            'customer' => $request->input('customer', []),
            'notify' => $request->input('notify', ['sms' => true, 'email' => true]),
            'reminder_enable' => $request->input('reminder_enable', true),
            'notes' => $request->input('notes', []),
            'callback_url' => $request->input('callback_url'),
            'callback_method' => $request->input('callback_method', 'post'),
        ];

        $result = $this->paymentService->createPaymentLink($linkData);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'payment_link' => $result['data'],
                'short_url' => $result['short_url'] ?? null,
                'demo_mode' => $this->paymentService->isDemoMode(),
                'message' => $this->paymentService->isDemoMode() ? 
                    'Demo payment link created. Configure Razorpay credentials for real links.' : 
                    'Payment link created successfully'
            ], 201);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to create payment link'
        ], 400);
    }

    /**
     * Fetch Payment Link
     * GET /payment-service/payment-links/{linkId}
     */
    public function fetchPaymentLink($linkId)
    {
        $result = $this->paymentService->fetchPaymentLink($linkId);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'payment_link' => $result['data']
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to fetch payment link'
        ], 404);
    }

    /**
     * Update Payment Link
     * PUT /payment-service/payment-links/{linkId}
     */
    public function updatePaymentLink(Request $request, $linkId)
    {
        $validator = Validator::make($request->all(), [
            'description' => 'sometimes|string|max:255',
            'customer' => 'sometimes|array',
            'expire_by' => 'sometimes|integer',
            'notify' => 'sometimes|array',
            'reminder_enable' => 'sometimes|boolean',
            'notes' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = $request->only([
            'description',
            'customer',
            'expire_by',
            'notify',
            'reminder_enable',
            'notes'
        ]);

        $result = $this->paymentService->updatePaymentLink($linkId, $updateData);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'payment_link' => $result['data'],
                'message' => $result['message'] ?? 'Payment link updated successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to update payment link'
        ], 400);
    }

    /**
     * Cancel Payment Link
     * DELETE /payment-service/payment-links/{linkId}
     */
    public function cancelPaymentLink($linkId)
    {
        $result = $this->paymentService->cancelPaymentLink($linkId);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Payment link cancelled successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to cancel payment link'
        ], 400);
    }

    /**
     * Send Payment Link SMS
     * POST /payment-service/payment-links/{linkId}/send-sms
     */
    public function sendPaymentLinkSMS(Request $request, $linkId)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $this->paymentService->sendPaymentLinkSMS($linkId, $request->input('phone'));

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'SMS sent successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to send SMS'
        ], 400);
    }

    /**
     * Send Payment Link Email
     * POST /payment-service/payment-links/{linkId}/send-email
     */
    public function sendPaymentLinkEmail(Request $request, $linkId)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $this->paymentService->sendPaymentLinkEmail($linkId, $request->input('email'));

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Email sent successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to send email'
        ], 400);
    }

    /**
     * Health Check
     * GET /payment-service/health
     */
    public function health()
    {
        return response()->json([
            'success' => true,
            'service' => 'Payment Service',
            'provider' => 'Razorpay',
            'demo_mode' => $this->paymentService->isDemoMode(),
            'configured' => !$this->paymentService->isDemoMode(),
            'timestamp' => now()->toIso8601String()
        ]);
    }

    /**
     * Create Payment with Method Selection
     * POST /payment-service/orders-with-methods
     */
    public function createOrderWithMethods(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|size:3',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:15',
            'description' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $amount = (float) $request->input('amount');
        $customerName = $request->input('customer_name', 'Customer');
        $customerEmail = $request->input('customer_email', 'customer@example.com');
        $customerPhone = $request->input('customer_phone', '');
        $description = $request->input('description', 'Payment');

        // Create order data
        $orderData = [
            'amount' => ((int) $amount) * 100, // Convert to paise
            'currency' => $request->input('currency', 'INR'),
            'receipt' => 'order_' . time() . '_' . rand(1000, 9999),
            'notes' => [
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'description' => $description
            ]
        ];

        $result = $this->paymentService->createOrder($orderData);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to create order'
            ], 400);
        }

        $order = $result['data'];
        $keyId = $this->paymentService->getKeyId();

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

        // Create checkout configuration for frontend
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
            ],
            'handler' => config('payment_service.callbacks.success_url', '/payment-success'),
            'modal' => [
                'ondismiss' => config('payment_service.callbacks.failure_url', '/payment-failure')
            ]
        ];

        // Generate payment page URL
        $baseUrl = $request->getSchemeAndHttpHost();
        $paymentPageUrl = $baseUrl . '/payment-service/payment-method-selection?' . http_build_query([
            'order_id' => $order['id'],
            'amount' => $amount,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'description' => $description
        ]);

        return response()->json([
            'success' => true,
            'id' => $order['id'],
            'order_id' => $order['id'],
            'amount' => $amount,
            'currency' => $order['currency'],
            'key' => $keyId,
            'key_id' => $keyId,
            'payment_methods' => $paymentMethods,
            'checkout_config' => $checkoutConfig,
            'razorpay_order' => $order,
            'payment_page_url' => $paymentPageUrl,
            'redirect_url' => $paymentPageUrl,
            'demo_mode' => $this->paymentService->isDemoMode(),
            'message' => $this->paymentService->isDemoMode() ? 
                'Demo order created with method selection.' : 
                'Order created successfully with payment method selection.',
            'instructions' => 'Open payment_page_url in browser to complete payment'
        ], 201);
    }

    /**
     * Payment Success Callback Handler
     * POST /payment-service/payment-success-callback
     * 
     * Handles payment callback from Razorpay and updates payment status
     * Note: This only verifies payment. Database updates should be handled by calling application
     */
    public function handlePaymentSuccess(Request $request)
    {
        try {
            // Support both order-based and payment-link callbacks
            $hasOrderId = $request->filled('razorpay_order_id');
            
            $validator = $hasOrderId
                ? Validator::make($request->all(), [
                    'razorpay_order_id' => 'required|string',
                    'razorpay_payment_id' => 'required|string',
                    'razorpay_signature' => 'required|string'
                ])
                : Validator::make($request->all(), [
                    'razorpay_payment_id' => 'required|string'
                ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment callback data',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Check if this is a test payment
            $isTestPayment = ($request->razorpay_payment_id === 'pay_test' || 
                            $request->razorpay_signature === 'sig_test');

            if ($isTestPayment) {
                Log::info('Test payment detected - skipping signature verification');
            } else {
                if ($hasOrderId) {
                    // Verify payment signature
                    $verifyResult = $this->paymentService->verifyPayment(
                        $request->razorpay_order_id,
                        $request->razorpay_payment_id,
                        $request->razorpay_signature
                    );

                    if (!$verifyResult['success'] || !($verifyResult['verified'] ?? false)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid payment signature'
                        ], 400);
                    }
                }
            }

            // Return success response
            return response()->json([
                'success' => true,
                'message' => $isTestPayment ? 'Test payment completed successfully' : 'Payment completed successfully',
                'payment_type' => $isTestPayment ? 'test' : 'real',
                'payment_details' => [
                    'order_id' => $request->input('razorpay_order_id'),
                    'payment_id' => $request->input('razorpay_payment_id'),
                    'status' => 'completed',
                    'amount' => $request->input('amount') ?? 0,
                    'currency' => $request->input('currency', 'INR'),
                    'signature_verified' => !$isTestPayment
                ],
                'timestamp' => now()->toIso8601String(),
                'note' => 'Payment verified successfully. Update your database with this information.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Payment success callback error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Payment callback processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get Payment Order for Frontend Razorpay Integration
     * POST /payment-service/get-checkout-options
     * 
     * Returns checkout configuration ready to use with Razorpay Checkout
     */
    public function getCheckoutOptions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|size:3',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:15',
            'description' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $amount = (float) $request->input('amount');

        // Create order
        $orderData = [
            'amount' => ((int) $amount) * 100,
            'currency' => $request->input('currency', 'INR'),
            'receipt' => 'order_' . time() . '_' . rand(1000, 9999),
            'notes' => array_filter([
                'customer_name' => $request->input('customer_name'),
                'customer_email' => $request->input('customer_email'),
                'customer_phone' => $request->input('customer_phone'),
                'description' => $request->input('description')
            ])
        ];

        $result = $this->paymentService->createOrder($orderData);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to create order'
            ], 400);
        }

        $order = $result['data'];
        $keyId = $this->paymentService->getKeyId();

        // Return checkout options for frontend Razorpay Checkout
        return response()->json([
            'success' => true,
            'razorpay_key' => $keyId,
            'order_id' => $order['id'],
            'amount' => $amount,
            'currency' => $order['currency'],
            'checkout_options' => [
                'key' => $keyId,
                'amount' => $order['amount'],
                'currency' => $order['currency'],
                'order_id' => $order['id'],
                'name' => config('app.name', 'Payment Gateway'),
                'description' => $request->input('description', 'Payment'),
                'prefill' => array_filter([
                    'name' => $request->input('customer_name'),
                    'email' => $request->input('customer_email'),
                    'contact' => $request->input('customer_phone')
                ]),
                'method' => [
                    'card' => true,
                    'upi' => true,
                    'netbanking' => true,
                    'wallet' => true
                ]
            ],
            'demo_mode' => $this->paymentService->isDemoMode(),
            'message' => 'Use checkout_options to initialize Razorpay Checkout in your frontend'
        ], 201);
    }
    
    /**
     * Create Payment Link with Checkout URL
     * POST /payment-service/create-payment-link
     * 
     * Creates a Razorpay payment link that can be opened directly in browser
     */
    public function createPaymentLinkWithUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|size:3',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:15',
            'description' => 'nullable|string|max:255',
            'callback_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $amount = (float) $request->input('amount');
        $customerName = $request->input('customer_name');
        $customerEmail = $request->input('customer_email');
        $customerPhone = $request->input('customer_phone');
        $description = $request->input('description', 'Payment');
        $callbackUrl = $request->input('callback_url', config('payment_service.callbacks.success_url', '/payment-success'));

        // Create payment link data
        $linkData = [
            'amount' => ((int) $amount) * 100, // Convert to paise
            'currency' => $request->input('currency', 'INR'),
            'description' => $description,
            'customer' => [],
            'notify' => ['sms' => true, 'email' => true],
            'reminder_enable' => true,
            'callback_url' => $callbackUrl,
            'callback_method' => 'post',
            'notes' => array_filter([
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone
            ])
        ];

        // Add customer if provided
        if ($customerName || $customerEmail || $customerPhone) {
            $linkData['customer'] = array_filter([
                'name' => $customerName,
                'email' => $customerEmail,
                'contact' => $customerPhone
            ]);
        }

        $result = $this->paymentService->createPaymentLink($linkData);

        if ($result['success']) {
            $paymentLink = $result['data'];
            $shortUrl = $result['short_url'] ?? ($paymentLink['short_url'] ?? null);
            
            return response()->json([
                'success' => true,
                'payment_link_id' => $paymentLink['id'] ?? null,
                'payment_page_url' => $shortUrl,
                'direct_link' => $shortUrl,
                'amount' => $amount,
                'currency' => $paymentLink['currency'] ?? 'INR',
                'description' => $description,
                'customer' => $linkData['customer'],
                'demo_mode' => $this->paymentService->isDemoMode(),
                'message' => $this->paymentService->isDemoMode() ? 
                    'Demo payment link created. Configure Razorpay credentials for real links.' : 
                    'Payment link created successfully. Open payment_page_url in browser to pay.',
                'instructions' => 'Copy the payment_page_url and open it in your browser to complete payment'
            ], 201);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to create payment link'
        ], 400);
    }

    /**
     * Create Payment Order and Return Direct Payment Page URL
     * POST /payment-service/open-payment-page
     * 
     * Creates a Razorpay order and returns a URL that opens the Razorpay payment page directly.
     * This endpoint can be used by any application to integrate Razorpay payments.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function openPaymentPage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1',
                'currency' => 'sometimes|string|size:3',
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
                'customer_phone' => 'nullable|string|max:15',
                'description' => 'nullable|string|max:500',
                'reference_id' => 'nullable|string|max:255', // For tracking payment in your system
                'success_callback_url' => 'nullable|url', // URL to redirect after successful payment
                'failure_callback_url' => 'nullable|url', // URL to redirect after failed payment
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $amount = (float) $request->input('amount');
            $currency = $request->input('currency', 'INR');
            $customerName = $request->input('customer_name');
            $customerEmail = $request->input('customer_email');
            $customerPhone = $request->input('customer_phone');
            $description = $request->input('description', 'Payment');
            $referenceId = $request->input('reference_id');
            $successCallbackUrl = $request->input('success_callback_url');
            $failureCallbackUrl = $request->input('failure_callback_url');

            // Create order data
            $orderData = [
                'amount' => ((int) $amount) * 100, // Convert to paise
                'currency' => $currency,
                'receipt' => 'order_' . time() . '_' . rand(1000, 9999),
                'payment_capture' => 1, // Auto capture
                'notes' => array_filter([
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'description' => $description,
                    'reference_id' => $referenceId,
                ])
            ];

            // Create Razorpay order
            $result = $this->paymentService->createOrder($orderData);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to create order'
                ], 400);
            }

            $order = $result['data'];
            $keyId = $this->paymentService->getKeyId();

            // Store order in database for tracking
            $paymentRecord = PaymentDetails::create([
                'razorpay_order_id' => $order['id'],
                'razorpay_payment_id' => null,
                'razorpay_signature' => null,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'reference_id' => $referenceId,
            ]);

            // Build success and failure callback URLs
            $baseUrl = $request->getSchemeAndHttpHost();
            $defaultSuccessUrl = $successCallbackUrl ?? $baseUrl . '/payment-service/payment-success-callback';
            $defaultFailureUrl = $failureCallbackUrl ?? $baseUrl . '/payment-service/payment-failure-callback';

            // Create payment page URL with checkout options
            // This URL will open Razorpay checkout directly
            $paymentPageUrl = $baseUrl . '/payment-service/payment-method-selection?' . http_build_query([
                'order_id' => $order['id'],
                'amount' => $amount,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'description' => $description,
                'success_url' => $defaultSuccessUrl,
                'failure_url' => $defaultFailureUrl,
                'reference_id' => $referenceId,
            ]);

            return response()->json([
                'success' => true,
                'payment_page_url' => $paymentPageUrl,
                'order_id' => $order['id'],
                'payment_record_id' => $paymentRecord->id,
                'amount' => $amount,
                'currency' => $currency,
                'razorpay_key' => $keyId,
                'razorpay_order' => $order,
                'checkout_options' => [
                    'key' => $keyId,
                    'amount' => $order['amount'],
                    'currency' => $order['currency'],
                    'order_id' => $order['id'],
                    'name' => config('app.name', 'Payment Gateway'),
                    'description' => $description,
                    'prefill' => array_filter([
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'contact' => $customerPhone
                    ]),
                    'handler' => function($response) use ($defaultSuccessUrl) {
                        // Frontend should redirect to success URL with payment details
                        return redirect($defaultSuccessUrl . '?' . http_build_query($response));
                    },
                    'modal' => [
                        'ondismiss' => function() use ($defaultFailureUrl) {
                            return redirect($defaultFailureUrl);
                        }
                    ]
                ],
                'success_callback_url' => $defaultSuccessUrl,
                'failure_callback_url' => $defaultFailureUrl,
                'demo_mode' => $this->paymentService->isDemoMode(),
                'message' => 'Open payment_page_url in browser to complete payment',
                'instructions' => 'Use payment_page_url to redirect user to payment page, or use checkout_options with Razorpay Checkout library'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Open payment page error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create payment page',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Payment Success Callback - Updates Database
     * POST /payment-service/payment-success-callback
     * GET /payment-service/payment-success-callback
     * 
     * Handles payment success callback from Razorpay and updates payment status in database.
     * This endpoint can be used by any application.
     */
    public function handlePaymentSuccessCallback(Request $request)
    {
        try {
            // Support both POST and GET requests
            $orderId = $request->input('razorpay_order_id') ?? $request->query('razorpay_order_id');
            $paymentId = $request->input('razorpay_payment_id') ?? $request->query('razorpay_payment_id');
            $signature = $request->input('razorpay_signature') ?? $request->query('razorpay_signature');

            if (!$orderId || !$paymentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required parameters: razorpay_order_id and razorpay_payment_id are required'
                ], 400);
            }

            // Check if this is a test payment
            $isTestPayment = ($paymentId === 'pay_test' || $signature === 'sig_test');

            // Verify payment signature if signature is provided
            if ($signature && !$isTestPayment) {
                $verifyResult = $this->paymentService->verifyPayment(
                    $orderId,
                    $paymentId,
                    $signature
                );

                if (!$verifyResult['success'] || !($verifyResult['verified'] ?? false)) {
                    Log::warning('Payment signature verification failed', [
                        'order_id' => $orderId,
                        'payment_id' => $paymentId
                    ]);

                    // Update database with failed status
                    PaymentDetails::where('razorpay_order_id', $orderId)
                        ->update([
                            'status' => 'failed',
                            'razorpay_payment_id' => $paymentId,
                            'razorpay_signature' => $signature,
                        ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid payment signature',
                        'status' => 'failed'
                    ], 400);
                }
            }

            // Fetch payment details from Razorpay if not in demo mode
            $paymentData = null;
            $razorpayAmountPaise = null;
            if (!$this->paymentService->isDemoMode() && !$isTestPayment) {
                try {
                    $paymentResult = $this->paymentService->fetchPayment($paymentId);
                    if ($paymentResult['success'] && isset($paymentResult['data'])) {
                        $paymentData = $paymentResult['data'];
                        // Razorpay returns amount in paise
                        $razorpayAmountPaise = $paymentData['amount'] ?? null;
                    } else {
                        Log::warning('Failed to fetch payment details from Razorpay', [
                            'payment_id' => $paymentId,
                            'error' => $paymentResult['error'] ?? 'Unknown error'
                        ]);
                        // Fallback: try fetching the order details to get amount
                        try {
                            $orderResult = $this->paymentService->fetchOrder($orderId);
                            if (($orderResult['success'] ?? false) && isset($orderResult['data']['amount'])) {
                                $razorpayAmountPaise = $orderResult['data']['amount'];
                                Log::info('Using amount from Razorpay order as fallback', [
                                    'order_id' => $orderId,
                                    'amount_paise' => $razorpayAmountPaise
                                ]);
                            }
                        } catch (\Exception $e2) {
                            Log::warning('Exception while fetching order from Razorpay', [
                                'order_id' => $orderId,
                                'error' => $e2->getMessage()
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Exception while fetching payment from Razorpay', [
                        'payment_id' => $paymentId,
                        'error' => $e->getMessage()
                    ]);
                    // Fallback: try fetching the order details to get amount
                    try {
                        $orderResult = $this->paymentService->fetchOrder($orderId);
                        if (($orderResult['success'] ?? false) && isset($orderResult['data']['amount'])) {
                            $razorpayAmountPaise = $orderResult['data']['amount'];
                            Log::info('Using amount from Razorpay order as fallback (after exception)', [
                                'order_id' => $orderId,
                                'amount_paise' => $razorpayAmountPaise
                            ]);
                        }
                    } catch (\Exception $e3) {
                        Log::warning('Exception while fetching order from Razorpay (fallback)', [
                            'order_id' => $orderId,
                            'error' => $e3->getMessage()
                        ]);
                    }
                }
            }

            // Update payment record in database
            $paymentRecord = PaymentDetails::where('razorpay_order_id', $orderId)->first();

            if ($paymentRecord) {
                // Get existing amount (preserve it by default)
                $existingAmount = (float) ($paymentRecord->amount ?? 0);
                
                // Determine final amount to store
                $finalAmount = $existingAmount; // Default: preserve existing
                
                // Priority 1: Check if amount is provided in callback request (for Postman/testing)
                $requestAmount = $request->input('amount');
                if ($requestAmount !== null && (float) $requestAmount > 0) {
                    // If existing looks like paise (very large), override with rupees from request
                    if ($existingAmount >= 10000) {
                        $finalAmount = (float) $requestAmount;
                    } else {
                        $finalAmount = (float) $requestAmount;
                    }
                    Log::info('Using amount from callback request', [
                        'order_id' => $orderId,
                        'request_amount' => $requestAmount,
                        'existing_amount' => $existingAmount
                    ]);
                }
                // Priority 2: If no request amount, try Razorpay API amount
                elseif ($razorpayAmountPaise !== null && $razorpayAmountPaise > 0) {
                    // Razorpay amount is in paise, convert to rupees
                    $amountInRupees = ($razorpayAmountPaise / 100);
                    
                    // Validate converted amount
                    if ($amountInRupees > 0 && $amountInRupees <= 1000000) {
                        // If existing looks like paise (very large), override with rupees
                        if ($existingAmount >= 10000) {
                            $finalAmount = $amountInRupees;
                        } elseif ($existingAmount == 0 || abs($existingAmount - $amountInRupees) < 0.01) {
                            $finalAmount = $amountInRupees;
                            Log::info('Using amount from Razorpay API', [
                                'order_id' => $orderId,
                                'razorpay_amount_paise' => $razorpayAmountPaise,
                                'converted_amount_rupees' => $amountInRupees,
                                'existing_amount' => $existingAmount
                            ]);
                        } else {
                            // Amounts don't match significantly - preserve existing if it's valid
                            if ($existingAmount > 0) {
                                $finalAmount = $existingAmount; // Keep existing valid amount
                                Log::warning('Payment amount mismatch - preserving existing amount', [
                                    'order_id' => $orderId,
                                    'payment_id' => $paymentId,
                                    'existing_amount_rupees' => $existingAmount,
                                    'razorpay_amount_paise' => $razorpayAmountPaise,
                                    'razorpay_amount_rupees' => $amountInRupees,
                                    'difference' => abs($existingAmount - $amountInRupees),
                                    'action' => 'Preserving existing amount'
                                ]);
                            } else {
                                // Existing is 0 but Razorpay has valid amount - use Razorpay
                                $finalAmount = $amountInRupees;
                            }
                        }
                    }
                }
                // Priority 3: If existing amount is valid (> 0), preserve it (never overwrite with 0)
                // This ensures we never lose a valid amount

                $updateData = [
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => $signature ?? $paymentRecord->razorpay_signature,
                    'status' => 'paid',
                    'payment_completed_at' => now(),
                    'amount' => $finalAmount, // Always set amount (preserve or update)
                ];

                // Add payment method details if available
                if ($paymentData) {
                    $updateData['method'] = $paymentData['method'] ?? null;
                    if (isset($paymentData['card'])) {
                        $updateData['card_last4'] = $paymentData['card']['last4'] ?? null;
                        $updateData['card_network'] = $paymentData['card']['network'] ?? null;
                    }
                }

                $paymentRecord->update($updateData);
                
                Log::info('Payment status updated to paid', [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'record_id' => $paymentRecord->id,
                    'amount_stored' => $paymentRecord->fresh()->amount,
                    'amount_from_razorpay_paise' => $razorpayAmountPaise,
                    'amount_converted_rupees' => $razorpayAmountPaise ? ($razorpayAmountPaise / 100) : null,
                    'original_amount_before_update' => $paymentRecord->getOriginal('amount')
                ]);
            } else {
                // Create new payment record if not found (for direct payment links)
                // Determine amount: Priority: request > Razorpay API > 0
                $amountToStore = 0;
                
                // Priority 1: Check if amount is in request
                $requestAmount = $request->input('amount');
                if ($requestAmount !== null && (float) $requestAmount > 0) {
                    $amountToStore = (float) $requestAmount;
                }
                // Priority 2: Try Razorpay API
                elseif ($razorpayAmountPaise !== null && $razorpayAmountPaise > 0) {
                    $amountToStore = ($razorpayAmountPaise / 100); // Convert from paise to rupees
                }
                
                PaymentDetails::create([
                    'razorpay_order_id' => $orderId,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => $signature,
                    'status' => 'paid',
                    'amount' => $amountToStore,
                    'currency' => $paymentData['currency'] ?? 'INR',
                    'method' => $paymentData['method'] ?? null,
                    'payment_completed_at' => now(),
                ]);
                
                Log::info('Created new payment record', [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'amount_stored' => $amountToStore,
                    'amount_from_request' => $requestAmount,
                    'amount_from_razorpay_paise' => $razorpayAmountPaise
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment completed successfully and database updated',
                'payment_status' => 'paid',
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Payment success callback error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Payment callback processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Payment Failure Callback - Updates Database
     * POST /payment-service/payment-failure-callback
     * GET /payment-service/payment-failure-callback
     * 
     * Handles payment failure callback from Razorpay and updates payment status in database.
     * This endpoint can be used by any application.
     */
    public function handlePaymentFailureCallback(Request $request)
    {
        try {
            // Support both POST and GET requests
            $orderId = $request->input('razorpay_order_id') ?? $request->query('razorpay_order_id');
            $paymentId = $request->input('razorpay_payment_id') ?? $request->query('razorpay_payment_id');
            $errorCode = $request->input('error_code') ?? $request->query('error_code');
            $errorDescription = $request->input('error_description') ?? $request->query('error_description') ?? $request->input('error') ?? $request->query('error');
            $errorReason = $request->input('error_reason') ?? $request->query('error_reason') ?? 'Payment failed';

            if (!$orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required parameter: razorpay_order_id'
                ], 400);
            }

            // Update payment record in database
            $paymentRecord = PaymentDetails::where('razorpay_order_id', $orderId)->first();

            if ($paymentRecord) {
                $updateData = [
                    'status' => 'failed',
                ];

                if ($paymentId) {
                    $updateData['razorpay_payment_id'] = $paymentId;
                }

                $paymentRecord->update($updateData);

                Log::info('Payment status updated to failed', [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'error_code' => $errorCode,
                    'error_description' => $errorDescription,
                    'record_id' => $paymentRecord->id
                ]);
            } else {
                // Create new payment record for failed payment (if order was created but payment failed)
                PaymentDetails::create([
                    'razorpay_order_id' => $orderId,
                    'razorpay_payment_id' => $paymentId,
                    'status' => 'failed',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment failure recorded and database updated',
                'payment_status' => 'failed',
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'error_code' => $errorCode,
                'error_description' => $errorDescription,
                'error_reason' => $errorReason,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Payment failure callback error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Payment failure callback processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create Payment Order with Method Selection
     * POST /payment-service/payment-with-method-selection
     * 
     * This API creates a Razorpay order and returns payment options (Card, UPI, Netbanking, Wallet).
     * It also stores payment record in database for tracking.
     * 
     * This endpoint can be used by any application for payment integration.
     * 
     * Request body:
     * - amount: required (in rupees)
     * - value1: optional (can be numerical or text)
     * - value2: optional (can be numerical or text)
     * - currency: optional (default: INR)
     * - customer_name: optional
     * - customer_email: optional
     * - customer_phone: optional
     * - description: optional
     * - reference_id: optional (for tracking in your system)
     * - success_callback_url: optional (default: payment-service callback)
     * - failure_callback_url: optional (default: payment-service callback)
     * 
     * Returns:
     * - order_id: Razorpay order ID
     * - amount: Payment amount
     * - currency: Payment currency
     * - key: Razorpay key ID
     * - payment_methods: Available payment methods
     * - checkout_config: Razorpay Checkout configuration
     * - payment_page_url: URL to redirect user for payment
     */
    public function createPaymentWithMethodSelection(Request $request)
    {
        try {
            // Validate required fields
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1',
                'value1' => 'nullable',
                'value2' => 'nullable',
                'currency' => 'sometimes|string|size:3',
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
                'customer_phone' => 'nullable|string|max:15',
                'description' => 'nullable|string|max:500',
                'reference_id' => 'nullable|string|max:255',
                'success_callback_url' => 'nullable|url',
                'failure_callback_url' => 'nullable|url',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $amount = (float) $request->input('amount');
            $value1 = $request->has('value1') ? (string) $request->input('value1') : null;
            $value2 = $request->has('value2') ? (string) $request->input('value2') : null;
            $currency = $request->input('currency', 'INR');
            $customerName = $request->input('customer_name', 'Customer');
            $customerEmail = $request->input('customer_email', 'customer@example.com');
            $customerPhone = $request->input('customer_phone', '');
            $description = $request->input('description', 'Payment');
            $referenceId = $request->input('reference_id');
            $successCallbackUrl = $request->input('success_callback_url');
            $failureCallbackUrl = $request->input('failure_callback_url');

            // Create Razorpay order
            $orderData = [
                'amount' => ((int) $amount) * 100, // Convert to paise
                'currency' => $currency,
                'receipt' => 'order_' . time() . '_' . rand(1000, 9999),
                'payment_capture' => 1, // Auto capture
                'notes' => array_filter([
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'description' => $description,
                    'reference_id' => $referenceId,
                ])
            ];

            // Create order using PaymentService
            $result = $this->paymentService->createOrder($orderData);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create payment order',
                    'message' => $result['error'] ?? 'Unknown error occurred'
                ], 500);
            }

            $order = $result['data'];
            $keyId = $this->paymentService->getKeyId();

            // Store payment record in database for tracking
            $paymentRecord = PaymentDetails::create([
                'razorpay_order_id' => $order['id'],
                'razorpay_payment_id' => null,
                'razorpay_signature' => null,
                'amount' => $amount,
                'value1' => $value1,
                'value2' => $value2,
                'currency' => $currency,
                'status' => 'pending',
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'reference_id' => $referenceId,
            ]);

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

            // Build success and failure callback URLs
            $baseUrl = $request->getSchemeAndHttpHost();
            $defaultSuccessUrl = $successCallbackUrl ?? $baseUrl . '/payment-service/payment-success-callback';
            $defaultFailureUrl = $failureCallbackUrl ?? $baseUrl . '/payment-service/payment-failure-callback';

            // Create Checkout configuration for Razorpay
            $checkoutConfig = [
                'key' => $keyId,
                'amount' => $order['amount'],
                'currency' => $order['currency'],
                'order_id' => $order['id'],
                'name' => config('app.name', 'Payment Gateway'),
                'description' => $description,
                'prefill' => array_filter([
                    'name' => $customerName,
                    'email' => $customerEmail,
                    'contact' => $customerPhone
                ]),
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
            $paymentPageUrl = $baseUrl . '/payment-service/payment-method-selection?' . http_build_query([
                'order_id' => $order['id'],
                'amount' => $amount,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'description' => $description,
                'success_url' => $defaultSuccessUrl,
                'failure_url' => $defaultFailureUrl,
                'reference_id' => $referenceId,
            ]);

            // Return JSON response
            return response()->json([
                'success' => true,
                'message' => 'Payment order created successfully. Open payment_page_url in browser or use checkout_config to initialize Razorpay Checkout directly.',
                'order_id' => $order['id'],
                'payment_record_id' => $paymentRecord->id,
                'amount' => $amount,
                'currency' => $currency,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'description' => $description,
                'status' => 'created',
                'key' => $keyId,
                'key_id' => $keyId,
                'razorpay_key' => $keyId,
                'payment_methods' => $paymentMethods,
                'checkout_config' => $checkoutConfig,
                'razorpay_order' => $order,
                'payment_page_url' => $paymentPageUrl,
                'redirect_url' => $paymentPageUrl,
                'success_callback_url' => $defaultSuccessUrl,
                'failure_callback_url' => $defaultFailureUrl,
                'demo_mode' => $this->paymentService->isDemoMode(),
                'instructions' => 'To open payment page: Copy payment_page_url and open in browser, or use GET /payment-service/payment-method-selection with query parameters'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Payment with method selection error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create payment order',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve payment id from order id and update DB status
     * POST /payment-service/resolve-payment
     *
     * Request: { order_id: string, payment_id?: string }
     * Behaviour:
     * - If payment_id provided -> fetch payment by id
     * - Else -> fetch payments for order and pick the latest successful
     * - Update payments table row matched by order_id
     */
    public function resolveAndUpdatePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|string',
            'payment_id' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $orderId = $request->input('order_id');
        $paymentId = $request->input('payment_id');

        try {
            $paymentData = null;
            // Strategy: prefer explicit payment id, else resolve from order
            if ($paymentId) {
                $res = $this->paymentService->fetchPayment($paymentId);
                if ($res['success']) {
                    $paymentData = $res['data'];
                } else {
                    return response()->json([
                        'success' => false,
                        'error' => $res['error'] ?? 'Failed to fetch payment'
                    ], 400);
                }
            } else {
                $res = $this->paymentService->fetchPaymentsForOrder($orderId);
                if (!$res['success']) {
                    return response()->json([
                        'success' => false,
                        'error' => $res['error'] ?? 'Failed to fetch payments for order'
                    ], 400);
                }

                $list = $res['data'] ?? [];
                // Pick a successful/captured payment if available, else the latest one
                $chosen = null;
                foreach ($list as $p) {
                    if (($p['status'] ?? '') === 'captured' || ($p['status'] ?? '') === 'authorized') {
                        $chosen = $p;
                        break;
                    }
                }
                if (!$chosen && !empty($list)) {
                    $chosen = end($list);
                }

                if (!$chosen) {
                    return response()->json([
                        'success' => false,
                        'error' => 'No payments found for the provided order'
                    ], 404);
                }

                $paymentData = $chosen;
                $paymentId = $chosen['id'] ?? null;
            }

            // Normalize values
            $amountRupees = isset($paymentData['amount']) ? ((float) $paymentData['amount']) / 100 : null;
            $status = $paymentData['status'] ?? 'captured';
            $method = $paymentData['method'] ?? null;
            $cardLast4 = $paymentData['card']['last4'] ?? null;
            $cardNetwork = $paymentData['card']['network'] ?? null;

            // Update DB row for this order
            $paymentRecord = \App\Models\PaymentDetails::where('razorpay_order_id', $orderId)->first();
            if (!$paymentRecord) {
                // Create if missing
                $paymentRecord = \App\Models\PaymentDetails::create([
                    'razorpay_order_id' => $orderId,
                    'razorpay_payment_id' => $paymentId,
                    'amount' => $amountRupees,
                    'currency' => $paymentData['currency'] ?? 'INR',
                    'status' => $status === 'captured' ? 'paid' : $status,
                    'method' => $method,
                    'card_last4' => $cardLast4,
                    'card_network' => $cardNetwork,
                    'payment_completed_at' => now()
                ]);
            } else {
                $update = [
                    'razorpay_payment_id' => $paymentId ?? $paymentRecord->razorpay_payment_id,
                    'status' => $status === 'captured' ? 'paid' : $status,
                    'method' => $method ?? $paymentRecord->method,
                    'payment_completed_at' => now()
                ];
                if ($amountRupees && $amountRupees > 0) {
                    $update['amount'] = $amountRupees;
                }
                if ($cardLast4) $update['card_last4'] = $cardLast4;
                if ($cardNetwork) $update['card_network'] = $cardNetwork;
                $paymentRecord->update($update);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment resolved and status updated',
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'status' => $paymentRecord->fresh()->status,
                'amount' => $paymentRecord->amount
            ]);

        } catch (\Exception $e) {
            Log::error('resolveAndUpdatePayment failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to resolve and update payment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unified success callback compatible with legacy /api/payment-success-callback
     * Accepts order/payment ids (and optional signature), updates DB via resolver,
     * and returns a backward-compatible JSON payload so existing apps continue to work.
     *
     * POST /payment-success-callback
     * POST /api/payment-success-callback
     */
    public function unifiedSuccessCallback(Request $request)
    {
        // Accept both razorpay_* and plain keys
        $orderId = $request->input('razorpay_order_id') ?? $request->input('order_id');
        $paymentId = $request->input('razorpay_payment_id') ?? $request->input('payment_id');
        $signature = $request->input('razorpay_signature');

        if (!$orderId) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required parameter: order_id / razorpay_order_id'
            ], 400);
        }

        $signatureVerified = null;
        // Try to verify signature when available (best-effort)
        if ($signature && $paymentId) {
            try {
                $verify = $this->paymentService->verifyPayment($orderId, $paymentId, $signature);
                $signatureVerified = ($verify['success'] ?? false) && ($verify['verified'] ?? false);
            } catch (\Throwable $e) {
                $signatureVerified = false;
                Log::warning('unifiedSuccessCallback signature verification error: ' . $e->getMessage());
            }
        }

        // Reuse resolver logic
        $resolverRequest = new Request([
            'order_id' => $orderId,
            'payment_id' => $paymentId
        ]);
        $resolverResponse = $this->resolveAndUpdatePayment($resolverRequest);
        $resolverData = json_decode($resolverResponse->getContent(), true);

        if (!($resolverData['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $resolverData['error'] ?? 'Failed to update payment',
                'details' => $resolverData
            ], $resolverResponse->status());
        }

        // Build compatibility payload
        $amount = $resolverData['amount'] ?? null;
        $finalPaymentId = $resolverData['payment_id'] ?? $paymentId;
        $payload = [
            'success' => true,
            'message' => 'Payment completed successfully',
            'payment_type' => 'real',
            'payment_details' => [
                'order_id' => $orderId,
                'payment_id' => $finalPaymentId,
                'status' => 'completed',
                'amount' => $amount,
                'currency' => 'INR',
                'signature_verified' => $signatureVerified
            ]
        ];

        // Optional redirect format to maintain legacy client behaviour
        $payload['redirect_url'] = '/payment-success?payment_id=' . urlencode((string) $finalPaymentId)
            . '&amount=' . urlencode((string) $amount)
            . '&order_id=' . urlencode((string) $orderId)
            . '&method=' . urlencode('razorpay')
            . '&testing=' . urlencode('false');

        return response()->json($payload, 200);
    }
}

