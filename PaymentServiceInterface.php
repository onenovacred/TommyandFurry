<?php

namespace App\PaymentService\Interfaces;

/**
 * Payment Service Interface
 * 
 * Defines the contract for payment services.
 * Implement this interface to create different payment provider integrations.
 */
interface PaymentServiceInterface
{
    /**
     * Create a payment order
     * 
     * @param array $orderData Order data
     * @return array Response with order details
     */
    public function createOrder(array $orderData): array;

    /**
     * Verify payment signature
     * 
     * @param string $orderId Order ID
     * @param string $paymentId Payment ID
     * @param string $signature Payment signature
     * @return array Verification result
     */
    public function verifyPayment(string $orderId, string $paymentId, string $signature): array;

    /**
     * Fetch payment details
     * 
     * @param string $paymentId Payment ID
     * @return array Payment details
     */
    public function fetchPayment(string $paymentId): array;

    /**
     * Fetch order details
     * 
     * @param string $orderId Order ID
     * @return array Order details
     */
    public function fetchOrder(string $orderId): array;

    /**
     * Create a payment link
     * 
     * @param array $linkData Payment link data
     * @return array Payment link details
     */
    public function createPaymentLink(array $linkData): array;

    /**
     * Fetch payment link
     * 
     * @param string $linkId Payment link ID
     * @return array Payment link details
     */
    public function fetchPaymentLink(string $linkId): array;
}

