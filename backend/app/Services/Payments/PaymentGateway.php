<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * One payment provider (iPaymu, Xendit, DOKU). Landing-page sales only talk to this
 * interface, so switching or adding a provider does not touch order/ledger code.
 * Keys and secrets come from the provider settings (super admin), never hard-coded.
 */
interface PaymentGateway
{
    /** Provider key stored on orders: "ipaymu", "xendit", "doku". */
    public function name(): string;

    public function isReady(): bool;

    /**
     * Choices shown on the checkout page: "qris" (QR on our page) and/or "other" (the
     * provider's own payment page with VA, e-wallet, retail, …).
     *
     * @return list<'qris'|'other'>
     */
    public function paymentOptions(): array;

    /** Start a payment for the buyer. */
    public function createCharge(ChargeRequest $request): ChargeResult;

    /** Is this webhook really from the provider (callback token / signature)? */
    public function verifyWebhook(Request $request): bool;

    /**
     * Ask the provider for the real state of a payment. Used to confirm webhooks and to
     * reconcile orders whose webhook never arrived. Returns state "unknown" when the
     * provider cannot be asked (e.g. no transaction id yet).
     */
    public function getStatus(string $reference, ?string $gatewayRef, ?string $transactionId): PaymentStatus;

    public function supportsDisbursement(): bool;

    /** Send money to a seller account (automatic withdrawal mode). */
    public function disburse(DisbursementRequest $request): DisbursementResult;

    public function supportsAccountValidation(): bool;

    /** Account holder name registered at the bank / e-wallet, or null when unknown. */
    public function validateBankAccount(string $bankCode, string $accountNumber): ?string;
}
