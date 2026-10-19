<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Brings an order in line with Adyen, from two sides:
 * - update(): the payment link's status, asked from Adyen when the customer
 *   comes back: completed (paid), paymentPending, expired, active (nobody
 *   paid yet: NOT_STARTED);
 * - authorised(): the signed AUTHORISATION webhook, which also brings the
 *   payment's pspReference (refunds need it).
 * Whichever comes first marks the order paid; the other changes nothing.
 */
class OrderUpdater
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderPayment $orderPayment,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @return string A ReturnResult state
     * @throws LocalizedException When Adyen doesn't answer
     */
    public function update(Order $order): string
    {
        $linkId = (string) $order->getPayment()?->getAdditionalInformation(Payment::LINK_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $linkId === '') {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        $link = $this->api->send('GET', '/paymentLinks/' . rawurlencode($linkId), null, (int) $order->getStoreId());

        return match ((string) ($link['status'] ?? '')) {
            'completed' => $this->paid($order, (array) ($link['amount'] ?? []), $linkId),
            'paymentPending' => ReturnResult::PENDING,
            'expired' => $this->failed($order, 'expired'),
            'active' => ReturnResult::NOT_STARTED,
            default => ReturnResult::PENDING,
        };
    }

    /**
     * A signed AUTHORISATION webhook with success "true" for this order.
     */
    public function authorised(Order $order, array $item): void
    {
        $pspReference = (string) ($item['pspReference'] ?? '');
        $order->getPayment()->setAdditionalInformation(Payment::PSP_REFERENCE, $pspReference);
        if ($this->orderPayment->settled($order) === null) {
            $this->paid($order, (array) ($item['amount'] ?? []), $pspReference);
        } else {
            $this->orderRepository->save($order);
        }
    }

    /**
     * Nobody paid: the link is expired (so it can't be paid later) and the
     * order cancelled.
     */
    public function abandon(Order $order): void
    {
        $linkId = (string) $order->getPayment()->getAdditionalInformation(Payment::LINK_ID);
        try {
            $this->api->send('PATCH', '/paymentLinks/' . rawurlencode($linkId), ['status' => 'expired'], (int) $order->getStoreId());
        } catch (LocalizedException) {
            // Already over: it expires on its own.
        }
        $this->failed($order, (string) __('the customer came back without paying'));
    }

    private function paid(Order $order, array $amount, string $transactionId): string
    {
        $expected = Payment::amount($order, (float) $order->getGrandTotal());
        if ((int) ($amount['value'] ?? -1) !== $expected['value'] || ($amount['currency'] ?? '') !== $expected['currency']) {
            $this->orderPayment->flag(
                $order,
                (string) __('Adyen reports a payment of a different amount; check it in the Adyen Customer Area.'),
                sprintf(
                    'Adyen: %s %s, expected %d %s',
                    $amount['value'] ?? '?',
                    $amount['currency'] ?? '?',
                    $expected['value'],
                    $expected['currency']
                )
            );

            return ReturnResult::PENDING;
        }
        $this->orderPayment->capture($order, $transactionId);

        return ReturnResult::PAID;
    }

    private function failed(Order $order, string $reason): string
    {
        $this->orderPayment->cancel($order, (string) __('Adyen payment did not go through: %1', $reason));

        return ReturnResult::FAILED;
    }
}
