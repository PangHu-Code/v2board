<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CheckSubscribeAnomaly extends Command
{
    protected $signature = 'check:subscribeAnomaly {days=1}';

    protected $description = '检测周期内仅拉取订阅但没有流量使用的用户';

    public function handle()
    {
        if (!(int) config('v2board.subscribe_anomaly_enable', 0)) {
            return;
        }

        if (!(int) config('v2board.telegram_bot_enable', 0)) {
            return;
        }

        $periodDays = (int) $this->argument('days');
        if (!in_array($periodDays, [1, 3, 7], true)) {
            $periodDays = 1;
        }

        $cutoffAt = time() - ($periodDays * 86400);
        $userIds = array_values(array_unique(array_map('intval', Redis::zrangebyscore('v2board_subscribe_pull_users', $cutoffAt, '+inf'))));
        if (empty($userIds)) {
            return;
        }

        Redis::zremrangebyscore('v2board_subscribe_pull_users', 0, time() - (86400 * 8));

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('banned', 0)
            ->where('transfer_enable', '>', 0)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhereNull('expired_at');
            })
            ->where('t', '<', $cutoffAt)
            ->orderBy('id')
            ->get([
                'id',
                'email',
                'u',
                'd',
                't',
                'transfer_enable',
                'expired_at',
                'plan_id'
            ]);

        if ($users->isEmpty()) {
            return;
        }

        $telegramService = new TelegramService();
        $messages = [];

        foreach ($users as $user) {
            $cacheKey = sprintf(
                'subscribe_anomaly_notified_%d_%d',
                $periodDays,
                $user->id
            );

            if (Cache::has($cacheKey)) {
                continue;
            }

            $lastSubscribeAt = Redis::zscore('v2board_subscribe_pull_users', $user->id);
            if (!$lastSubscribeAt || $lastSubscribeAt < $cutoffAt) {
                continue;
            }

            $messages[] = $this->formatUserLine($user, (int) $lastSubscribeAt);
            Cache::put($cacheKey, 1, now()->addDays($periodDays));
        }

        if (empty($messages)) {
            return;
        }

        foreach (array_chunk($messages, 10) as $chunk) {
            $text = "订阅异常检测通知\n";
            $text .= "周期：{$periodDays}天\n";
            $text .= "说明：检测到以下用户在周期内拉取过订阅，但没有新增流量使用\n";
            $text .= "———————————————\n";
            $text .= implode("\n\n", $chunk);
            $telegramService->sendMessageWithAdmin($text, true);
        }
    }

    private function formatUserLine(User $user, int $lastSubscribeAt): string
    {
        $pullMeta = Redis::hgetall("v2board_subscribe_pull_meta_{$user->id}");
        $usedTraffic = (int) $user->u + (int) $user->d;
        $totalTraffic = Helper::trafficConvert((int) $user->transfer_enable);
        $usedTrafficText = Helper::trafficConvert($usedTraffic);
        $expiredAt = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
        $lastTrafficAt = $user->t ? date('Y-m-d H:i:s', $user->t) : '无';
        $lastSubscribeAtText = date('Y-m-d H:i:s', (int) ($pullMeta['pulled_at'] ?? $lastSubscribeAt));
        $pullIp = $pullMeta['ip'] ?? 'unknown';
        $pullUserAgent = $pullMeta['user_agent'] ?? 'unknown';
        $email = $pullMeta['email'] ?? $user->email;

        return sprintf(
            "#%d %s\n邮箱: %s\n最近拉订阅: %s\n拉取IP: %s\n拉取UA: %s\n最近流量变更: %s\n已用/总量: %s / %s\n到期时间: %s",
            $user->id,
            $email,
            $email,
            $lastSubscribeAtText,
            $pullIp,
            $pullUserAgent,
            $lastTrafficAt,
            $usedTrafficText,
            $totalTraffic,
            $expiredAt
        );
    }
}
