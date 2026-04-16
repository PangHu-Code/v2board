<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class SubAnomaly extends Telegram
{
    public $command = '/subanomaly';
    public $description = '控制并执行订阅异常检测(仅管理员)';

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        if (!$user->is_admin) {
            $telegramService->sendMessage($message->chat_id, '❌ 权限不足，此命令仅管理员可用', 'markdown');
            return;
        }

        $action = strtolower(trim((string) ($message->args[0] ?? 'status')));

        if ($action === 'status') {
            $enabled = (int) config('v2board.subscribe_anomaly_enable', 0);
            $telegramEnabled = (int) config('v2board.telegram_bot_enable', 0);
            $text = "订阅异常检测状态\n";
            $text .= "开关: `" . ($enabled ? 'on' : 'off') . "`\n";
            $text .= "Telegram Bot: `" . ($telegramEnabled ? 'on' : 'off') . "`\n";
            $text .= "用法:\n";
            $text .= "`/subanomaly status`\n";
            $text .= "`/subanomaly on`\n";
            $text .= "`/subanomaly off`\n";
            $text .= "`/subanomaly run [1|3|7]`";
            $telegramService->sendMessage($message->chat_id, $text, 'markdown');
            return;
        }

        if ($action === 'on' || $action === 'off') {
            $enabled = $action === 'on' ? 1 : 0;
            $this->updateConfig('subscribe_anomaly_enable', $enabled);
            $telegramService->sendMessage(
                $message->chat_id,
                "✅ 订阅异常检测已" . ($enabled ? '开启' : '关闭') . "\n当前状态: `" . ($enabled ? 'on' : 'off') . "`",
                'markdown'
            );
            return;
        }

        if ($action === 'run') {
            $days = isset($message->args[1]) ? (int) $message->args[1] : 1;
        } else {
            $days = (int) $action;
        }

        if (!in_array($days, [1, 3, 7], true)) {
            $telegramService->sendMessage(
                $message->chat_id,
                "❌ 参数错误\n用法:\n`/subanomaly status`\n`/subanomaly on`\n`/subanomaly off`\n`/subanomaly run [1|3|7]`\n示例: `/subanomaly run 3`",
                'markdown'
            );
            return;
        }

        if (!(int) config('v2board.telegram_bot_enable', 0)) {
            $telegramService->sendMessage($message->chat_id, '❌ Telegram Bot 未开启，无法发送检测通知', 'markdown');
            return;
        }

        if (!(int) config('v2board.subscribe_anomaly_enable', 0)) {
            $telegramService->sendMessage($message->chat_id, '❌ 订阅异常检测开关当前为关闭，请先执行 `/subanomaly on`', 'markdown');
            return;
        }

        $telegramService->sendMessage($message->chat_id, "🔄 开始执行订阅异常检测，周期: `{$days}` 天", 'markdown');

        Artisan::call('check:subscribeAnomaly', [
            'days' => $days
        ]);

        $telegramService->sendMessage($message->chat_id, "✅ 订阅异常检测已执行，周期: `{$days}` 天", 'markdown');
    }

    private function updateConfig(string $key, $value): void
    {
        $config = config('v2board');
        $config[$key] = $value;
        $data = var_export($config, true);

        if (!File::put(base_path() . '/config/v2board.php', "<?php\n return $data ;")) {
            abort(500, '配置写入失败');
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        Artisan::call('config:cache');
    }
}
