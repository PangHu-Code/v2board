<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerAnytls;
use App\Models\ServerV2node;
use App\Plugins\Telegram\Telegram;

class ChangeIP extends Telegram {
    public $command = '/changeip';
    public $description = '修改所有显示节点的地址';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;

        // 验证用户身份
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        // 验证管理员权限
        if (!$user->is_admin) {
            $telegramService->sendMessage($message->chat_id, '❌ 权限不足，此命令仅管理员可用', 'markdown');
            return;
        }

        // 获取IP/域名参数
        if (!isset($message->args[0])) {
            $telegramService->sendMessage($message->chat_id, '❌ 请提供IP或域名\n用法: `/changeip 1.2.3.4` 或 `/changeip example.com`', 'markdown');
            return;
        }

        $newHost = trim($message->args[0]);

        // 验证IP或域名格式
        if (!$this->isValidHostOrIP($newHost)) {
            $telegramService->sendMessage($message->chat_id, '❌ 无效的IP或域名格式', 'markdown');
            return;
        }

        // 更新所有显示的节点
        $updatedCount = 0;

        // Vmess
        $vmessCount = ServerVmess::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $vmessCount;

        // Trojan
        $trojanCount = ServerTrojan::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $trojanCount;

        // Shadowsocks
        $ssCount = ServerShadowsocks::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $ssCount;

        // Vless
        $vlessCount = ServerVless::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $vlessCount;

        // Hysteria
        $hysteriaCount = ServerHysteria::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $hysteriaCount;

        // Tuic
        $tuicCount = ServerTuic::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $tuicCount;

        // AnyTLS
        $anytlsCount = ServerAnytls::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $anytlsCount;

        // V2node
        $v2nodeCount = ServerV2node::where('show', 1)->update(['host' => $newHost]);
        $updatedCount += $v2nodeCount;

        $text = "✅ 节点地址修改完成\n———————————————\n";
        $text .= "新地址: `{$newHost}`\n";
        $text .= "更新节点数: `{$updatedCount}`\n";
        $text .= "———————————————\n";
        $text .= "Vmess: {$vmessCount}\n";
        $text .= "Trojan: {$trojanCount}\n";
        $text .= "Shadowsocks: {$ssCount}\n";
        $text .= "Vless: {$vlessCount}\n";
        $text .= "Hysteria: {$hysteriaCount}\n";
        $text .= "Tuic: {$tuicCount}\n";
        $text .= "AnyTLS: {$anytlsCount}\n";
        $text .= "V2node: {$v2nodeCount}";

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }

    /**
     * 验证是否为有效的IP地址或域名
     */
    private function isValidHostOrIP($host) {
        // 验证IPv4
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        // 验证IPv6
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }

        // 验证域名
        if (preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $host)) {
            return true;
        }

        return false;
    }
}
