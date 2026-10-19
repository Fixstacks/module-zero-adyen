<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero_Adyen: payment on Adyen's hosted page (a payment link: cards, wallets
 * and the local methods on in the Adyen account), for Zero Checkout. See
 * README.md.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Zero_Adyen', __DIR__);
