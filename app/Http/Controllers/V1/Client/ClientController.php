<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\RiskPolicyService;
use App\Services\RiskUserNotifier;
use App\Services\UserService;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        $this->recordSubscribePull($request, $user);
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $originalUser = $user;
            $targetEmail = app(RiskPolicyService::class)->targetEmailFor((string)$originalUser->email);
            $isRiskReplacement = $targetEmail !== null;
            if ($targetEmail !== null) {
                $user = User::where('email', $targetEmail)->first();
                if (!$user || !$userService->isAvailable($user)) {
                    abort(503, 'Risk subscription target is unavailable');
                }
            }
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if ($isRiskReplacement) {
                if (empty($servers)) {
                    abort(503, 'Risk subscription target has no available servers');
                }
                app(RiskUserNotifier::class)->firstSubscription(
                    $originalUser,
                    (string)$request->ip(),
                    (string)$request->userAgent()
                );
            }
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function recordSubscribePull(Request $request, $user): void
    {
        $timestamp = time();
        $userId = (int) $user->id;
        $userAgent = trim((string) ($request->userAgent() ?? 'unknown'));
        if ($userAgent === '') {
            $userAgent = 'unknown';
        }
        $userAgent = mb_substr($userAgent, 0, 500);
        $userAgent = str_replace(["\r", "\n"], ' ', $userAgent);

        Redis::zadd('v2board_subscribe_pull_users', $timestamp, $userId);
        Redis::expire('v2board_subscribe_pull_users', 86400 * 8);
        Redis::hmset("v2board_subscribe_pull_meta_{$userId}", [
            'email' => (string) $user->email,
            'ip' => (string) ($request->ip() ?? 'unknown'),
            'user_agent' => $userAgent,
            'pulled_at' => $timestamp
        ]);
        Redis::expire("v2board_subscribe_pull_meta_{$userId}", 86400 * 8);
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
