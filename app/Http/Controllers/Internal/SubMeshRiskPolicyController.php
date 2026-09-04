<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RiskPolicyService;
use App\Services\UserService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class SubMeshRiskPolicyController extends Controller
{
    public function update(Request $request, RiskPolicyService $policy)
    {
        $secret = (string)config('submesh.risk_policy_secret', '');
        if (strlen($secret) < 32) {
            return response()->json(['message' => 'Risk policy sync is not configured.'], 503);
        }

        $body = $request->getContent();
        if ($body === '' || strlen($body) > (int)config('submesh.risk_policy_max_bytes', 1048576)) {
            return response()->json(['message' => 'Invalid request.'], 400);
        }
        $timestamp = (string)$request->header('X-SubMesh-Timestamp', '');
        $signature = strtolower((string)$request->header('X-SubMesh-Signature', ''));
        if (!preg_match('/^\d{10}$/', $timestamp)
            || abs(time() - (int)$timestamp) > (int)config('submesh.risk_policy_clock_skew', 300)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $expected = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
        if (!hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['message' => 'Invalid policy payload.'], 422);
        }
        if (($payload['enabled'] ?? false) === true && is_string($payload['target_email'] ?? null)) {
            $target = User::where('email', strtolower(trim($payload['target_email'])))->first();
            if (!$target || !(new UserService())->isAvailable($target)) {
                return response()->json(['message' => 'Risk subscription target is unavailable.'], 422);
            }
        }
        try {
            $stored = $policy->replace($payload);
        } catch (UnexpectedValueException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => 'Unable to store risk policy.'], 500);
        }

        return response()->json([
            'data' => [
                'version' => $stored['version'],
                'enabled' => $stored['enabled'],
                'email_count' => count($stored['emails']),
            ],
        ]);
    }
}
