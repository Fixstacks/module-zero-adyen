<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;
use Zero\Adyen\Model\Api;
use Zero\Adyen\Model\Notification;
use Zero\Adyen\Model\OrderUpdater;
use Zero\Adyen\Model\Payment;

/**
 * Adyen's standard webhook (zero_adyen/payment/webhook, HMAC): each item's
 * signature is checked with the order's store key; a successful AUTHORISATION
 * marks the order paid (OrderUpdater). Answers "[accepted]", or 401 on a bad
 * signature.
 */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly OrderFactory $orderFactory,
        private readonly Api $api,
        private readonly OrderUpdater $orderUpdater,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return Raw
     */
    public function execute()
    {
        $result = $this->rawFactory->create();
        /** @var Http $request */
        $request = $this->request;
        $body = json_decode((string) $request->getContent(), true);
        foreach ((array) ($body['notificationItems'] ?? []) as $wrapper) {
            $item = (array) ($wrapper['NotificationRequestItem'] ?? []);
            $reference = (string) ($item['merchantReference'] ?? '');
            $order = $reference === '' ? null : $this->orderFactory->create()->loadByIncrementId($reference);
            if (!$order || !$order->getId() || $order->getPayment()?->getMethod() !== Payment::CODE) {
                continue;
            }
            if (!Notification::isValid($item, $this->api->hmacKey((int) $order->getStoreId()))) {
                $this->logger->warning('Zero Adyen: webhook with a bad signature for order ' . $reference);

                return $result->setHttpResponseCode(401)->setContents('');
            }
            if (($item['eventCode'] ?? '') === 'AUTHORISATION' && ($item['success'] ?? '') === 'true') {
                $this->orderUpdater->authorised($order, $item);
            }
        }

        return $result->setHttpResponseCode(200)->setContents('[accepted]');
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Signed by Adyen instead (HMAC).
     *
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
