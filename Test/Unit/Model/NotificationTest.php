<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\Adyen\Model\Api;
use Zero\Adyen\Model\Notification;

class NotificationTest extends TestCase
{
    private const KEY = '44782DEF547AAA06C910C43932B1EB0C71FC68D9D0C057550C48EC2ACF6BA056';

    private function item(): array
    {
        return [
            'pspReference' => '7914073381342284',
            'originalReference' => '',
            'merchantAccountCode' => 'TestMerchant',
            'merchantReference' => '000000001',
            'amount' => ['value' => 1130, 'currency' => 'EUR'],
            'eventCode' => 'AUTHORISATION',
            'success' => 'true',
        ];
    }

    public function testAcceptsTheSignedItem(): void
    {
        $item = $this->item();
        $item['additionalData']['hmacSignature'] = Notification::sign($item, self::KEY);

        $this->assertTrue(Notification::isValid($item, self::KEY));
    }

    public function testRejectsAChangedItem(): void
    {
        $item = $this->item();
        $item['additionalData']['hmacSignature'] = Notification::sign($item, self::KEY);
        $item['amount']['value'] = 1;

        $this->assertFalse(Notification::isValid($item, self::KEY));
        $this->assertFalse(Notification::isValid($this->item(), self::KEY));
    }

    public function testMinorUnitsByCurrency(): void
    {
        $this->assertSame(['value' => 1999, 'currency' => 'EUR'], Api::amount(19.99, 'EUR'));
        $this->assertSame(['value' => 1500, 'currency' => 'JPY'], Api::amount(1500, 'JPY'));
        $this->assertSame(['value' => 1500, 'currency' => 'KWD'], Api::amount(1.5, 'KWD'));
    }
}
