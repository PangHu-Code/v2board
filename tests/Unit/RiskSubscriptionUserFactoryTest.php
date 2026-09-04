<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\RiskSubscriptionUserFactory;
use PHPUnit\Framework\TestCase;

class RiskSubscriptionUserFactoryTest extends TestCase
{
    public function testKeepsTargetCredentialsAndOriginalVisibleAccountInformation(): void
    {
        $original = new User();
        $original->forceFill([
            'id' => 10,
            'uuid' => 'original-uuid',
            'token' => 'original-token',
            'u' => 101,
            'd' => 202,
            'transfer_enable' => 303,
            'expired_at' => 1924992000,
            'speed_limit' => 5,
        ]);
        $target = new User();
        $target->forceFill([
            'id' => 20,
            'uuid' => 'target-uuid',
            'token' => 'target-token',
            'u' => 1001,
            'd' => 2002,
            'transfer_enable' => 3003,
            'expired_at' => 1956528000,
            'speed_limit' => 50,
        ]);

        $result = (new RiskSubscriptionUserFactory())->make($original, $target);

        $this->assertSame(20, $result->id);
        $this->assertSame('target-uuid', $result->uuid);
        $this->assertSame(50, $result->speed_limit);
        $this->assertSame('original-token', $result->token);
        $this->assertSame(101, $result->u);
        $this->assertSame(202, $result->d);
        $this->assertSame(303, $result->transfer_enable);
        $this->assertSame(1924992000, $result->expired_at);

        $this->assertSame('target-token', $target->token);
        $this->assertSame(1001, $target->u);
    }
}
