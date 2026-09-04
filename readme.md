<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

[![](https://img.shields.io/badge/TgChat-@UnOfficialV2board讨论-blue.svg)](https://t.me/unofficialV2board)

## 本分支支持的后端
 - [修改版V2bX](https://github.com/wyx2685/V2bX)
 - [v2node](https://github.com/wyx2685/v2node)

## 原版迁移步骤

按以下步骤进行面板代码文件迁移：

    git remote set-url origin https://github.com/wyx2685/v2board  
    git checkout master  
    ./update.sh  


按以下步骤配置缓存驱动为redis，然后刷新设置缓存，重启队列:

    sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
    php artisan config:clear
    php artisan config:cache
    php artisan horizon:terminate

最后进入后台重新保存主题： 主题配置-选择default主题-主题设置-确定保存

# **V2Board**

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

## Demo
[Demo_user](https://v2bdemo.v-50.me/)
[Demo_admin](https://v2bdemo.v-50.me/admindashboard)
邮箱和密码可随意输入

## Document
[Click](https://v2board.com)

## Sponsors
Thanks to the open source project license provided by [Jetbrains](https://www.jetbrains.com/)

## Community
🔔Telegram Group: [@unofficialV2board](https://t.me/unofficialV2board)  

## How to Feedback
Follow the template in the issue to submit your question correctly, and we will have someone follow up with you.

## SubMesh 风险用户联动

本分支支持接收 SubMesh 的 HMAC 签名风险策略，并在风险用户拉取订阅时使用策略指定账号生成订阅内容。风险名单保存在 `storage/app/submesh/risk-policy.json`，不修改 V2Board 数据库结构。

在 V2Board `.env` 中配置与 SubMesh 中央服务相同的共享密钥：

```ini
SUBMESH_RISK_POLICY_SECRET=使用 openssl rand -hex 32 生成的密钥
```

策略接收接口为：

```text
PUT /api/internal/submesh/risk-policy
```

修改配置后运行 `php artisan config:clear && php artisan config:cache`，并重启 PHP/Workerman 与 Horizon。共享密钥不得提交到 Git 或写入日志。

启用共享密钥后，应立即从 SubMesh 控制台执行一次“立即同步”。在首次有效策略到达前，订阅接口会失败关闭，避免风险名单缺失时下发真实节点。
