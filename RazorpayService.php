<?php

namespace App\PaymentService\Services;

use Razorpay\Api\Api;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Razorpay Payment Service
 * 
 * A loosely coupled service for Razorpay payment integration.
 * This service can be used independently in any Laravel application.
 */
class RazorpayService implements \App\PaymentService\Interfaces\PaymentServiceInterface
{
    protected $api;
    protected $keyId;
    protected $keySecret;
    protected $isDemoMode;

    public function __construct()
    {
        $this->keyId = config('payment_service.razorpay.key_id', env('RAZORPAY_KEY'));
        $this->keySecret = config('payment_service.razorpay.key_secret', env('RAZORPAY_SECRET'));
        
        // Check if credentials are configured
        $this->isDemoMode = !$this->keyId || !$this->keySecret || 
                           $this->keyId === 'rzp_test_your_key_id_here' || 
                           $this->keySecret === 'your_secret_key_here';

        if (!$this->isDemoMode) {
            $this->api = new Api($this->keyId, $this->keySecret);
        }
    }

    /**
     * Check if service is in demo mode
     */
    public function isDemoMode(): bool
    {
        return $this->isDemoMode;
    }

    /**
     * Get Razorpay key ID
     */
    public function getKeyId(): ?string
    {
        return $this->keyId;
    }

    /**
     * Create a payment order
     * 
     * @param array $orderData Order data including amount, currency, receipt, etc.
     * @return array Response with success status and order data or error
     */
    public function createOrder(array $orderData): array
    {
        try {
            if ($this->isDemoMode) {
                return $this->createDemoOrder($orderData);
            }

            // Ensure amount is in paise (multiply by 100 if not already)
            if (!isset($orderData['amount']) || $orderData['amount'] <= 0) {
                return [
                    'success' => false,
                    'error' => 'Invalid amount provided'
                ];
            }

            // Convert to paise if amount is less than 100 (assuming it's in rupees)
            if ($orderData['amount'] < 100) {
                $orderData['amount'] = $orderData['amount'] * 100;
            }

            // Set default currency
            if (!isset($orderData['currency'])) {
                $orderData['currency'] = 'INR';
            }

            // Set default payment capture
            if (!isset($orderData['payment_capture'])) {
                $orderData['payment_capture'] = 1;
            }

            $order = $this->api->order->create($orderData);

            return [
                'success' => true,
                'data' => $order,
                'order_id' => $order['id']
            ];

        } catch (Exception $e) {
            Log::error('Razorpay order creation failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'demo_fallback' => $this->createDemoOrder($orderData)
            ];
        }
    }

    /**
     * Verify payment signature
     * 
     * @param string $orderId Razorpay order ID
     * @param string $paymentId Razorpay payment ID
     * @param string $signature Payment signature
     * @return array Response with verification status
     */
    public function verifyPayment(string $orderId, string $paymentId, string $signature): array
    {
        try {
            if ($this->isDemoMode) {
                return $this->verifyDemoPayment($orderId, $paymentId, $signature);
            }

            $attributes = [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature
            ];

            $this->api->utility->verifyPaymentSignature($attributes);

            return [
                'success' => true,
                'verified' => true,
                'message' => 'Payment signature verified successfully'
            ];

        } catch (Exception $e) {
            Log::error('Payment verification failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'verified' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch payment details
     * 
     * @param string $paymentId Payment ID
     * @return array Payment details or error
     */
    public function fetchPayment(string $paymentId): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'data' => [
                        'id' => $paymentId,
                        'status' => 'authorized',
                        'amount' => 0,
                        'currency' => 'INR',
                        'demo' => true
                    ]
                ];
            }

            $payment = $this->api->payment->fetch($paymentId);

            return [
                'success' => true,
                'data' => $payment->toArray()
            ];

        } catch (Exception $e) {
            Log::error('Fetch payment failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch order details
     * 
     * @param string $orderId Order ID
     * @return array Order details or error
     */
    public function fetchOrder(string $orderId): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'data' => [
                        'id' => $orderId,
                        'amount' => 0,
                        'currency' => 'INR',
                        'status' => 'created',
                        'demo' => true
                    ]
                ];
            }

            $order = $this->api->order->fetch($orderId);

            return [
                'success' => true,
                'data' => $order->toArray()
            ];

        } catch (Exception $e) {
            Log::error('Fetch order failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a payment link
     * 
     * @param array $linkData Payment link data
     * @return array Response with link data or error
     */
    public function createPaymentLink(array $linkData): array
    {
        try {
            if ($this->isDemoMode) {
                return $this->createDemoPaymentLink($linkData);
            }

            $paymentLink = $this->api->paymentLink->create($linkData);

            return [
                'success' => true,
                'data' => $paymentLink->toArray(),
                'short_url' => $paymentLink['short_url'] ?? null
            ];

        } catch (Exception $e) {
            Log::error('Payment link creation failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch payment link
     * 
     * @param string $linkId Payment link ID
     * @return array Payment link details or error
     */
    public function fetchPaymentLink(string $linkId): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'data' => [
                        'id' => $linkId,
                        'status' => 'issued',
                        'amount' => 0,
                        'demo' => true
                    ]
                ];
            }

            $paymentLink = $this->api->paymentLink->fetch($linkId);

            return [
                'success' => true,
                'data' => $paymentLink->toArray()
            ];

        } catch (Exception $e) {
            Log::error('Fetch payment link failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update payment link
     * 
     * @param string $linkId Payment link ID
     * @param array $updateData Data to update
     * @return array Updated link data or error
     */
    public function updatePaymentLink(string $linkId, array $updateData): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'data' => array_merge(['id' => $linkId, 'demo' => true], $updateData),
                    'message' => 'Demo mode: Payment link update simulated'
                ];
            }

            $paymentLink = $this->api->paymentLink->fetch($linkId);
            $updatedLink = $paymentLink->edit($updateData);

            return [
                'success' => true,
                'data' => $updatedLink->toArray()
            ];

        } catch (Exception $e) {
            Log::error('Update payment link failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel payment link
     * 
     * @param string $linkId Payment link ID
     * @return array Response status
     */
    public function cancelPaymentLink(string $linkId): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'message' => 'Demo mode: Payment link cancellation simulated'
                ];
            }

            $paymentLink = $this->api->paymentLink->fetch($linkId);
            $paymentLink->cancel();

            return [
                'success' => true,
                'message' => 'Payment link cancelled successfully'
            ];

        } catch (Exception $e) {
            Log::error('Cancel payment link failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send payment link notification via SMS
     * 
     * @param string $linkId Payment link ID
     * @param string $phone Phone number
     * @return array Response status
     */
    public function sendPaymentLinkSMS(string $linkId, string $phone): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'message' => 'Demo mode: SMS notification simulated'
                ];
            }

            $paymentLink = $this->api->paymentLink->fetch($linkId);
            $paymentLink->notifyBy('sms', ['contact' => $phone]);

            return [
                'success' => true,
                'message' => 'SMS notification sent successfully'
            ];

        } catch (Exception $e) {
            Log::error('Send SMS notification failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send payment link notification via Email
     * 
     * @param string $linkId Payment link ID
     * @param string $email Email address
     * @return array Response status
     */
    public function sendPaymentLinkEmail(string $linkId, string $email): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'message' => 'Demo mode: Email notification simulated'
                ];
            }

            $paymentLink = $this->api->paymentLink->fetch($linkId);
            $paymentLink->notifyBy('email', ['email' => $email]);

            return [
                'success' => true,
                'message' => 'Email notification sent successfully'
            ];

        } catch (Exception $e) {
            Log::error('Send email notification failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create demo order for testing
     */
    protected function createDemoOrder(array $orderData): array
    {
        $demoOrderId = 'demo_order_' . time() . '_' . rand(1000, 9999);
        
        return [
            'success' => true,
            'data' => [
                'id' => $demoOrderId,
                'entity' => 'order',
                'amount' => $orderData['amount'] ?? 0,
                'amount_paid' => 0,
                'amount_due' => $orderData['amount'] ?? 0,
                'currency' => $orderData['currency'] ?? 'INR',
                'receipt' => $orderData['receipt'] ?? 'demo_receipt',
                'status' => 'created',
                'attempts' => 0,
                'demo' => true
            ],
            'order_id' => $demoOrderId,
            'demo' => true
        ];
    }

    /**
     * Verify demo payment
     */
    protected function verifyDemoPayment(string $orderId, string $paymentId, string $signature): array
    {
        $isTestPayment = ($paymentId === 'pay_test' || $signature === 'sig_test');
        
        return [
            'success' => true,
            'verified' => $isTestPayment || strpos($paymentId, 'demo') !== false || strpos($paymentId, 'test') !== false,
            'message' => $isTestPayment || strpos($paymentId, 'demo') !== false ? 
                'Demo payment verified' : 'Payment signature verified successfully',
            'demo' => true
        ];
    }

    /**
     * Create demo payment link
     */
    protected function createDemoPaymentLink(array $linkData): array
    {
        $demoLinkId = 'plink_demo_' . time() . '_' . rand(1000, 9999);
        $baseUrl = config('app.url', 'http://127.0.0.1:8000');
        
        return [
            'success' => true,
            'data' => [
                'id' => $demoLinkId,
                'entity' => 'payment_link',
                'amount' => $linkData['amount'] ?? 0,
                'currency' => $linkData['currency'] ?? 'INR',
                'description' => $linkData['description'] ?? 'Demo Payment Link',
                'short_url' => $baseUrl . '/demo-payment/' . $demoLinkId,
                'status' => 'issued',
                'demo' => true
            ],
            'short_url' => $baseUrl . '/demo-payment/' . $demoLinkId,
            'demo' => true
        ];
    }

    /**
     * Fetch all payments for a Razorpay order
     *
     * @param string $orderId Razorpay order ID
     * @return array List of payments or error
     */
    public function fetchPaymentsForOrder(string $orderId): array
    {
        try {
            if ($this->isDemoMode) {
                return [
                    'success' => true,
                    'data' => [
                        [
                            'id' => 'pay_demo_' . time(),
                            'amount' => 0,
                            'currency' => 'INR',
                            'status' => 'captured',
                            'order_id' => $orderId,
                            'method' => 'card',
                            'demo' => true
                        ]
                    ]
                ];
            }

            $order = $this->api->order->fetch($orderId);
            $payments = $order->payments();

            // Normalize to array of arrays
            $list = [];
            foreach ($payments->items as $p) {
                $list[] = $p->toArray();
            }

            return [
                'success' => true,
                'data' => $list
            ];

        } catch (Exception $e) {
            Log::error('Fetch payments for order failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

