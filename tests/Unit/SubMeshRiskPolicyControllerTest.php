<?php

namespace Tests\Unit;

use App\Http\Controllers\Internal\SubMeshRiskPolicyController;
use App\Services\RiskPolicyService;
use Illuminate\Http\Request;
use Tests\TestCase;

class SubMeshRiskPolicyControllerTest extends TestCase
{
    private $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/v2board-risk-policy-controller-' . bin2hex(random_bytes(8)) . '.json';
        config([
            'submesh.risk_policy_secret' => str_repeat('a', 64),
            'submesh.risk_policy_max_bytes' => 1048576,
            'submesh.risk_policy_max_emails' => 10000,
            'submesh.risk_policy_clock_skew' => 300,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
        parent::tearDown();
    }

    public function testRejectsInvalidSignature(): void
    {
        $request = Request::create('/api/internal/submesh/risk-policy', 'PUT', [], [], [], [
            'HTTP_X_SUBMESH_TIMESTAMP' => (string)time(),
            'HTTP_X_SUBMESH_SIGNATURE' => str_repeat('0', 64),
            'CONTENT_TYPE' => 'application/json',
        ], $this->body(false));

        $response = (new SubMeshRiskPolicyController())->update($request, new RiskPolicyService($this->path));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFileDoesNotExist($this->path);
    }

    public function testAcceptsSignedDisabledPolicyWithoutDatabaseLookup(): void
    {
        $body = $this->body(false);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, str_repeat('a', 64));
        $request = Request::create('/api/internal/submesh/risk-policy', 'PUT', [], [], [], [
            'HTTP_X_SUBMESH_TIMESTAMP' => $timestamp,
            'HTTP_X_SUBMESH_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response = (new SubMeshRiskPolicyController())->update($request, new RiskPolicyService($this->path));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(7, json_decode($response->getContent(), true)['data']['version']);
    }

    public function testAcceptsAdapterManRawBodyFallback(): void
    {
        $body = $this->body(false);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, str_repeat('a', 64));
        $previousBody = $_SERVER['RAW_BODY'] ?? null;
        $_SERVER['RAW_BODY'] = $body;
        $request = Request::create('/api/internal/submesh/risk-policy', 'PUT', [], [], [], [
            'HTTP_X_SUBMESH_TIMESTAMP' => $timestamp,
            'HTTP_X_SUBMESH_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]);

        try {
            $response = (new SubMeshRiskPolicyController())->update($request, new RiskPolicyService($this->path));
        } finally {
            if ($previousBody === null) {
                unset($_SERVER['RAW_BODY']);
            } else {
                $_SERVER['RAW_BODY'] = $previousBody;
            }
        }

        $this->assertSame(200, $response->getStatusCode());
    }

    private function body(bool $enabled): string
    {
        return json_encode([
            'version' => 7,
            'enabled' => $enabled,
            'target_email' => $enabled ? 'target@example.com' : '',
            'emails' => ['risk@example.com'],
            'updated_at' => '2026-09-04T13:45:27Z',
        ], JSON_UNESCAPED_SLASHES);
    }
}
