<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RiskUserNotifier
{
    private $policy;

    public function __construct(RiskPolicyService $policy)
    {
        $this->policy = $policy;
    }

    public function registration(User $user, string $ip): void
    {
        $this->forRiskUser($user, 'registration', 315360000, function () use ($user, $ip) {
            return "🚨 风险用户注册\n邮箱：{$this->safe($user->email)}\n用户 ID：{$user->id}\nIP：{$this->safe($ip)}";
        });
    }

    public function orderOpened(User $user, Order $order): void
    {
        $this->forRiskUser($user, 'order:' . $order->trade_no, 7776000, function () use ($user, $order) {
            return "🚨 风险用户订单已开通\n邮箱：{$this->safe($user->email)}\n用户 ID：{$user->id}\n订单：{$this->safe($order->trade_no)}\n类型：{$order->type}\n金额：{$order->total_amount}";
        });
    }

    public function firstSubscription(User $user, string $ip, string $userAgent): void
    {
        $this->forRiskUser($user, 'subscription', 315360000, function () use ($user, $ip, $userAgent) {
            return "🚨 风险用户首次拉取订阅\n邮箱：{$this->safe($user->email)}\n用户 ID：{$user->id}\nIP：{$this->safe($ip)}\n客户端：{$this->safe($userAgent)}";
        });
    }

    private function forRiskUser(User $user, string $event, int $ttl, callable $message): void
    {
        if (!config('v2board.telegram_bot_enable', 0)) {
            return;
        }
        try {
            if (!$this->policy->isRiskEmail((string)$user->email)) {
                return;
            }
            $identity = $user->id . ':' . strtolower(trim((string)$user->email));
            $key = 'submesh:risk:notify:' . hash('sha256', $identity . ':' . $event);
            if (!Cache::add($key, 1, $ttl)) {
                return;
            }
            try {
                (new TelegramService())->sendMessageWithAdmin($message());
            } catch (Throwable $exception) {
                Cache::forget($key);
                throw $exception;
            }
        } catch (Throwable $exception) {
            Log::warning('Unable to notify administrators about risk user event.', [
                'event' => $event,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safe($value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$value);
        $value = str_replace(['`', '*', '[', ']'], '', $value ?: '-');
        return mb_substr($value, 0, 300);
    }
}
