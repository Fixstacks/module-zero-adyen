<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Zero\Checkout\Controller\Checkout\Placeorder;

/**
 * Payment on Adyen's hosted page (zero_adyen): a payment link for the order's
 * total; the order waits in pending payment until Adyen's webhook or the
 * customer's return updates it (OrderUpdater). Expects automatic capture
 * (Adyen's default); credit memo refunds go back by the pspReference.
 */
class Payment extends AbstractMethod
{
    public const CODE = 'zero_adyen';
    public const LINK_ID = 'adyen_payment_link_id';
    public const PSP_REFERENCE = 'adyen_psp_reference';
    /** How long the link takes a payment. */
    private const LINK_LIFETIME = 3600;

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        private readonly Api $api,
        private readonly UrlInterface $urlBuilder,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->api->isConfigured($quote ? (int) $quote->getStoreId() : null);
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return $this
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $reference = (string) $order->getIncrementId();
        $storeId = (int) $order->getStoreId();
        $link = $this->api->send('POST', '/paymentLinks', array_filter([
            'merchantAccount' => $this->api->merchantAccount($storeId),
            'reference' => $reference,
            'amount' => self::amount($order, (float) $order->getGrandTotal()),
            'description' => (string) __('Order #%1', $reference),
            'returnUrl' => $this->urlBuilder->getUrl('zero_adyen/payment/return', [
                '_secure' => true,
                '_query' => ['order' => $reference],
            ]),
            'countryCode' => (string) $order->getBillingAddress()?->getCountryId(),
            'shopperEmail' => (string) $order->getCustomerEmail(),
            'shopperLocale' => str_replace('_', '-', (string) $this->_scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $storeId
            )),
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() + self::LINK_LIFETIME),
            'reusable' => false,
        ], static fn ($value) => $value !== ''), $storeId);
        if (empty($link['id']) || empty($link['url'])) {
            throw new LocalizedException(__('Adyen didn\'t create the payment. Please try again or choose another payment method.'));
        }

        $payment->setAdditionalInformation(self::LINK_ID, (string) $link['id']);
        $payment->setAdditionalInformation(Placeorder::REDIRECT_URL, (string) $link['url']);
        $order->setCanSendNewEmailFlag(false);
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $pspReference = (string) $payment->getAdditionalInformation(self::PSP_REFERENCE);
        if ($pspReference === '') {
            throw new LocalizedException(__('Adyen hasn\'t reported this payment yet; refund it in the Adyen Customer Area.'));
        }
        /** @var Order $order */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $refund = $this->api->send('POST', '/payments/' . rawurlencode($pspReference) . '/refunds', [
            'merchantAccount' => $this->api->merchantAccount($storeId),
            'amount' => self::amount($order, (float) $amount * (float) ($order->getBaseToOrderRate() ?: 1)),
            'reference' => $order->getIncrementId() . '-refund',
        ], $storeId);
        $payment->setTransactionId((string) ($refund['pspReference'] ?? $pspReference . '-refund'))->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * An amount in the order's currency, as Adyen takes it.
     *
     * @return array{value: int, currency: string}
     */
    public static function amount(Order $order, float $amount): array
    {
        return Api::amount($amount, (string) $order->getOrderCurrencyCode());
    }
}
