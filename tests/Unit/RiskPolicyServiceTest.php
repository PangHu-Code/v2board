<?php

namespace Tests\Unit;

use App\Services\RiskPolicyService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

class RiskPolicyServiceTest extends TestCase
{
    private $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/v2board-risk-policy-' . bin2hex(random_bytes(8)) . '.json';
        config(['submesh.risk_policy_max_emails' => 10000]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
        parent::tearDown();
    }

    public function testStoresAndMatchesNormalizedPolicy(): void
    {
        $service = new RiskPolicyService($this->path);
        $policy = $service->replace($this->payload());

        $this->assertSame(['first@example.com', 'second@example.com'], $policy['emails']);
        $this->assertSame('target@example.com', $service->targetEmailFor(' FIRST@example.com '));
        $this->assertNull($service->targetEmailFor('normal@example.com'));
        $this->assertSame($policy, $service->replace($this->payload()));
    }

    public function testRejectsVersionRollback(): void
    {
        $service = new RiskPolicyService($this->path);
        $service->replace($this->payload(['version' => 2]));

        $this->expectException(UnexpectedValueException::class);
        $service->replace($this->payload(['version' => 1]));
    }

    public function testRejectsSameVersionWithDifferentContent(): void
    {
        $service = new RiskPolicyService($this->path);
        $service->replace($this->payload());

        $this->expectException(UnexpectedValueException::class);
        $service->replace($this->payload(['emails' => ['different@example.com']]));
    }

    public function testRejectsTargetInRiskList(): void
    {
        $service = new RiskPolicyService($this->path);

        $this->expectException(InvalidArgumentException::class);
        $service->replace($this->payload(['emails' => ['target@example.com']]));
    }

    public function testDisabledPolicyStillIdentifiesRiskEmailWithoutReplacingSubscription(): void
    {
        $service = new RiskPolicyService($this->path);
        $service->replace($this->payload([
            'enabled' => false,
            'target_email' => '',
        ]));

        $this->assertTrue($service->isRiskEmail('first@example.com'));
        $this->assertNull($service->targetEmailFor('first@example.com'));
    }

    public function testConfiguredSecretFailsClosedBeforeFirstSync(): void
    {
        config(['submesh.risk_policy_secret' => str_repeat('a', 64)]);
        $service = new RiskPolicyService($this->path);

        $this->expectException(RuntimeException::class);
        $service->targetEmailFor('normal@example.com');
    }

    public function testSignedReplacementCanRepairCorruptLocalPolicy(): void
    {
        file_put_contents($this->path, '{broken');
        $service = new RiskPolicyService($this->path);

        $stored = $service->replace($this->payload());

        $this->assertSame(1, $stored['version']);
        $this->assertSame('target@example.com', $service->targetEmailFor('first@example.com'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'version' => 1,
            'enabled' => true,
            'target_email' => 'target@example.com',
            'emails' => ['Second@example.com', 'first@example.com', 'first@example.com'],
            'updated_at' => '2026-09-04T13:45:27Z',
        ], $overrides);
    }
}
