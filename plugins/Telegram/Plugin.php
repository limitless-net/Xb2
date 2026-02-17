<?php

namespace Plugin\Telegram;

use App\Models\Order;
use App\Models\Server;
use App\Models\Ticket;
use App\Models\User;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\StatServer;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
  protected array $commands = [];
  protected TelegramService $telegramService;

  protected array $commandConfigs = [
    '/start' => ['description' => '开始使用', 'handler' => 'handleStartCommand'],
    '/bind' => ['description' => '绑定账号', 'handler' => 'handleBindCommand'],
    '/checkin' => ['description' => '每日签到', 'handler' => 'handleCheckinCommand'],
    '/status' => ['description' => '账户总览', 'handler' => 'handleStatusCommand'],
    '/traffic' => ['description' => '查看流量', 'handler' => 'handleTrafficCommand'],
    '/node' => ['description' => '节点状态', 'handler' => 'handleNodeCommand'],
    '/invite' => ['description' => '邀请返利', 'handler' => 'handleInviteCommand'],
    '/renew' => ['description' => '快捷续费', 'handler' => 'handleRenewCommand'],
    '/getlatesturl' => ['description' => '获取订阅链接', 'handler' => 'handleGetLatestUrlCommand'],
    '/plan' => ['description' => '查看套餐', 'handler' => 'handlePlanCommand'],
    '/lucky' => ['description' => '幸运抽奖', 'handler' => 'handleLuckyCommand'],
    '/reset' => ['description' => '重置订阅', 'handler' => 'handleResetCommand'],
    '/ticket' => ['description' => '快捷工单', 'handler' => 'handleTicketCommand'],
    '/rank' => ['description' => '邀请排行', 'handler' => 'handleRankCommand'],
    '/wallet' => ['description' => '我的钱包', 'handler' => 'handleWalletCommand'],
    '/order' => ['description' => '最近订单', 'handler' => 'handleOrderCommand'],
    '/unbind' => ['description' => '解绑账号', 'handler' => 'handleUnbindCommand'],
    // 管理员命令
    '/search' => ['description' => '查询用户(管理员)', 'handler' => 'handleSearchCommand'],
    '/broadcast' => ['description' => '群发通知(管理员)', 'handler' => 'handleBroadcastCommand'],
    '/stats' => ['description' => '运营统计(管理员)', 'handler' => 'handleStatsCommand'],
    '/ban' => ['description' => '封禁用户(管理员)', 'handler' => 'handleBanCommand'],
  ];

  public function boot(): void
  {
    $this->telegramService = new TelegramService();
    $this->registerDefaultCommands();

    $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
    $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
    $this->listen('telegram.message.error', [$this, 'handleError'], 10);
    $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
    $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
    $this->listen('user.register.after', [$this, 'sendRegisterNotify'], 10);
  }

  /**
   * 注册定时任务 — 到期预警 + 流量预警
   */
  public function schedule(Schedule $schedule): void
  {
    // 每天上午 10 点检查到期预警
    $schedule->call(function () {
      $this->runExpireAlert();
    })->daily()->at('10:00')->name('telegram_expire_alert');

    // 每 6 小时检查流量预警
    $schedule->call(function () {
      $this->runTrafficAlert();
    })->everySixHours()->name('telegram_traffic_alert');

    // 每小时检查待支付订单提醒
    $schedule->call(function () {
      $this->runPendingOrderAlert();
    })->hourly()->name('telegram_pending_order_alert');
  }

  // ══════════════════════════════════════════
  // 通知钩子: 支付 / 工单
  // ══════════════════════════════════════════

  public function sendPaymentNotify(Order $order): void
  {
    if (!$this->getConfig('enable_payment_notify', true)) {
      return;
    }

    $payment = $order->payment;
    if (!$payment) {
      Log::warning('支付通知失败：订单关联的支付方式不存在', ['order_id' => $order->id]);
      return;
    }

    $message = sprintf(
      "💰成功收款%s元\n" .
      "———————————————\n" .
      "支付接口：%s\n" .
      "支付渠道：%s\n" .
      "本站订单：`%s`",
      $order->total_amount / 100,
      $payment->payment,
      $payment->name,
      $order->trade_no
    );
    $this->telegramService->sendMessageWithAdmin($message, true);
  }

  public function sendTicketNotify(Ticket $ticket): void
  {
    if (!$this->getConfig('enable_ticket_notify', true)) {
      return;
    }

    $message = $ticket->messages()->latest()->first();
    $user = User::find($ticket->user_id);
    if (!$user)
      return;
    $user->load('plan');
    $transfer_enable = $this->transferToGBString($user->transfer_enable);
    $remaining_traffic = $this->transferToGBString($user->transfer_enable - $user->u - $user->d);
    $u = $this->transferToGBString($user->u);
    $d = $this->transferToGBString($user->d);
    $expired_at = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
    $money = $user->balance / 100;
    $affmoney = $user->commission_balance / 100;
    $plan = $user->plan;
    $ip = request()?->ip() ?? '';
    $region = $ip ? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (new \Ip2Region())->simple($ip) : 'NULL') : '';
    $TGmessage = "📮 *工单提醒* #{$ticket->id}\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📧 邮箱: `{$user->email}`\n";
    $TGmessage .= "📍 位置: `{$region}`\n";

    if ($plan) {
      $TGmessage .= "📦 套餐: `{$plan->name}`\n";
      $TGmessage .= "📊 流量: `{$remaining_traffic}G / {$transfer_enable}G` (剩余/总计)\n";
      $TGmessage .= "⬆️⬇️ 已用: `{$u}G / {$d}G`\n";
      $TGmessage .= "⏰ 到期: `{$expired_at}`\n";
    } else {
      $TGmessage .= "📦 套餐: `未订购任何套餐`\n";
    }

    $TGmessage .= "💰 余额: `{$money}元`\n";
    $TGmessage .= "💸 佣金: `{$affmoney}元`\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📝 *主题*: `{$ticket->subject}`\n";

    // 检查是否是图片消息
    if ($message && preg_match('/^\[图片\]\s*(https?:\/\/.+)$/i', $message->message, $matches)) {
        $imageUrl = trim($matches[1]);
        $TGmessage .= "🖼️ *内容*: 用户发送了一张图片";
        $this->telegramService->sendPhotoWithAdmin($imageUrl, $TGmessage, true);
    } else {
        $TGmessage .= "💬 *内容*: `{$message->message}`";
        $this->telegramService->sendMessageWithAdmin($TGmessage, true);
    }
  }

  /**
   * 新用户注册通知 — 推送给管理员
   */
  public function sendRegisterNotify(User $user): void
  {
    if (!$this->getConfig('enable_register_notify', true)) return;

    $text = "🆕 *新用户注册*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "📧 邮箱：`{$user->email}`\n";
    $text .= "⏰ 时间：`" . date('Y-m-d H:i:s') . "`\n";

    if ($user->invite_user_id) {
      $inviter = User::find($user->invite_user_id);
      if ($inviter) {
        $text .= "👤 邀请人：`{$inviter->email}`\n";
      }
    }

    $totalUsers = User::count();
    $text .= "📊 总用户数：`{$totalUsers}`";

    $this->telegramService->sendMessageWithAdmin($text, true);
  }

  // ══════════════════════════════════════════
  // 到期 / 流量预警 / 待支付提醒（定时任务调用）
  // ══════════════════════════════════════════

  protected function runExpireAlert(): void
  {
    if (!$this->getConfig('enable_expire_alert', true)) return;

    $days = (int) $this->getConfig('expire_alert_days', '3');
    $threshold = time() + ($days * 86400);
    $today = date('Y-m-d');

    $users = User::whereNotNull('telegram_id')
      ->whereNotNull('expired_at')
      ->where('expired_at', '>', time())       // 还没过期
      ->where('expired_at', '<=', $threshold)   // 即将过期
      ->where('remind_expire', true)
      ->get();

    foreach ($users as $user) {
      // 每天只提醒一次
      $cacheKey = "tg_expire_alert:{$user->id}:{$today}";
      if (Cache::has($cacheKey)) continue;

      $daysLeft = ceil(($user->expired_at - time()) / 86400);
      $text = "⏰ *到期提醒*\n\n";
      $text .= "您的套餐将在 *{$daysLeft}天后* 到期\n";
      $text .= "到期时间：`" . date('Y-m-d H:i', $user->expired_at) . "`\n\n";
      $text .= "请及时续费以免服务中断\n";
      $text .= "发送 /renew 获取续费链接";

      try {
        $this->telegramService->sendMessage($user->telegram_id, $text, 'markdown');
        Cache::put($cacheKey, true, 86400);
      } catch (\Exception $e) {
        Log::warning('到期预警发送失败', ['user_id' => $user->id, 'error' => $e->getMessage()]);
      }
    }
  }

  protected function runTrafficAlert(): void
  {
    if (!$this->getConfig('enable_traffic_alert', true)) return;

    $percent = (int) $this->getConfig('traffic_alert_percent', '10');
    $today = date('Y-m-d');

    $users = User::whereNotNull('telegram_id')
      ->where('transfer_enable', '>', 0)
      ->where('remind_traffic', true)
      ->get();

    foreach ($users as $user) {
      $used = $user->u + $user->d;
      $remaining = $user->transfer_enable - $used;
      $remainPercent = ($remaining / $user->transfer_enable) * 100;

      if ($remainPercent > $percent || $remaining <= 0) continue;

      $cacheKey = "tg_traffic_alert:{$user->id}:{$today}";
      if (Cache::has($cacheKey)) continue;

      $text = "⚠️ *流量预警*\n\n";
      $text .= "您的剩余流量仅剩 *" . $this->transferToGBString($remaining) . "G*\n";
      $text .= sprintf("使用率：%.1f%%\n", (($used / $user->transfer_enable) * 100));
      $text .= "总流量：" . $this->transferToGBString($user->transfer_enable) . "G\n\n";
      $text .= "请注意控制流量使用，或续费获取更多流量";

      try {
        $this->telegramService->sendMessage($user->telegram_id, $text, 'markdown');
        Cache::put($cacheKey, true, 86400);
      } catch (\Exception $e) {
        Log::warning('流量预警发送失败', ['user_id' => $user->id, 'error' => $e->getMessage()]);
      }
    }
  }

  protected function runPendingOrderAlert(): void
  {
    if (!$this->getConfig('enable_pending_alert', true)) return;

    $pendingOrders = Order::where('status', Order::STATUS_PENDING)
      ->where('created_at', '>=', time() - 86400)
      ->where('created_at', '<=', time() - 3600)
      ->get();

    foreach ($pendingOrders as $order) {
      $user = User::find($order->user_id);
      if (!$user || !$user->telegram_id) continue;

      $cacheKey = "tg_pending_alert:{$order->id}";
      if (Cache::has($cacheKey)) continue;

      $amount = $order->total_amount / 100;
      $text = "🛒 *待支付订单提醒*\n\n";
      $text .= "您有一笔订单尚未支付：\n";
      $text .= "订单号：`{$order->trade_no}`\n";
      $text .= "金额：`{$amount}元`\n";
      $text .= "创建时间：`" . date('Y-m-d H:i', $order->created_at) . "`\n\n";
      $text .= "如需继续支付，请登录面板完成付款\n";
      $text .= "发送 /renew 获取续费链接";

      try {
        $this->telegramService->sendMessage($user->telegram_id, $text, 'markdown');
        Cache::put($cacheKey, true, 86400);
      } catch (\Exception $e) {
        Log::warning('待支付提醒发送失败', ['order_id' => $order->id, 'error' => $e->getMessage()]);
      }
    }
  }

  // ══════════════════════════════════════════
  // 命令注册 & 分发
  // ══════════════════════════════════════════

  protected function registerDefaultCommands(): void
  {
    foreach ($this->commandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }

    $this->registerReplyHandler('/(📮.*?工单提醒.*?#?|工单ID: ?)(\\d+)/', [$this, 'handleTicketReply']);
    // 群发回复处理
    $this->registerReplyHandler('/📢.*群发通知/u', [$this, 'handleBroadcastReply']);
  }

  public function registerTelegramCommand(string $command, callable $handler): void
  {
    $this->commands['commands'][$command] = $handler;
  }

  public function registerReplyHandler(string $regex, callable $handler): void
  {
    $this->commands['replies'][$regex] = $handler;
  }

  protected function sendMessage(object $msg, string $message): void
  {
    $this->telegramService->sendMessage($msg->chat_id, $message, 'markdown');
  }

  protected function checkPrivateChat(object $msg): bool
  {
    if (!$msg->is_private) {
      $this->sendMessage($msg, '请在私聊中使用此命令');
      return false;
    }
    return true;
  }

  protected function getBoundUser(object $msg): ?User
  {
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user) {
      $this->sendMessage($msg, '请先绑定账号，发送 /bind + 订阅链接');
      return null;
    }
    return $user;
  }

  /**
   * 检查是否为管理员
   */
  protected function checkAdmin(object $msg): ?User
  {
    $user = $this->getBoundUser($msg);
    if (!$user) return null;

    if (!$user->is_admin && !$user->is_staff) {
      $this->sendMessage($msg, '❌ 此命令仅管理员可用');
      return null;
    }
    return $user;
  }

  // ══════════════════════════════════════════
  // 用户命令
  // ══════════════════════════════════════════

  public function handleStartCommand(object $msg): void
  {
    $welcomeTitle = $this->getConfig('start_welcome_title', '🎉 欢迎使用 XBoard Telegram Bot！');
    $botDescription = $this->getConfig('start_bot_description', '🤖 我是您的专属助手');
    $footer = $this->getConfig('start_footer', '💡 提示：所有命令都需要在私聊中使用');

    $welcomeText = $welcomeTitle . "\n\n" . $botDescription . "\n\n";

    $user = User::where('telegram_id', $msg->chat_id)->first();
    if ($user) {
      $welcomeText .= "✅ 您已绑定账号：{$user->email}\n\n";
      $welcomeText .= $this->getConfig('start_unbind_guide', '📋 可用命令：\\n/checkin - 每日签到\\n/status - 账户总览\\n/traffic - 查看流量\\n/node - 节点状态\\n/invite - 邀请返利\\n/renew - 快捷续费\\n/getlatesturl - 获取订阅链接\\n/unbind - 解绑账号');
    } else {
      $welcomeText .= $this->getConfig('start_bind_guide', '🔗 请先绑定您的 XBoard 账号') . "\n\n";
      $welcomeText .= $this->getConfig('start_bind_commands', '📋 可用命令：\\n/bind [订阅链接] - 绑定账号');
    }

    $welcomeText .= "\n\n" . $footer;
    $welcomeText = str_replace('\\n', "\n", $welcomeText);

    $this->sendMessage($msg, $welcomeText);
  }

  /**
   * /checkin — 每日签到
   */
  public function handleCheckinCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    if (!$this->getConfig('enable_checkin', true)) {
      $this->sendMessage($msg, '签到功能已关闭');
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $today = date('Y-m-d');
    $cacheKey = "tg_checkin:{$user->id}:{$today}";

    if (Cache::has($cacheKey)) {
      $got = Cache::get($cacheKey);
      $this->sendMessage($msg, "🎯 今日已签到\n\n今日获得：{$got}MB\n明天再来哦~");
      return;
    }

    $minMB = max(1, (int) $this->getConfig('checkin_min_mb', '50'));
    $maxMB = max($minMB, (int) $this->getConfig('checkin_max_mb', '500'));
    $rewardMB = random_int($minMB, $maxMB);
    $rewardBytes = $rewardMB * 1024 * 1024;

    $user->transfer_enable += $rewardBytes;
    $user->save();

    Cache::put($cacheKey, $rewardMB, strtotime('tomorrow') - time());

    $remaining = $user->transfer_enable - $user->u - $user->d;
    $text = "🎉 *签到成功！*\n\n";
    $text .= "🎁 获得流量：*{$rewardMB}MB*\n";
    $text .= "📊 当前剩余：" . $this->transferToGBString($remaining) . "G\n\n";
    $text .= "💡 每日签到可领 {$minMB}~{$maxMB}MB 流量";

    $this->sendMessage($msg, $text);
  }

  /**
   * /status — 账户总览（比 /traffic 更全面）
   */
  public function handleStatusCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;
    $user->load('plan');

    $transferUsed = $user->u + $user->d;
    $transferTotal = $user->transfer_enable;
    $transferRemaining = $transferTotal - $transferUsed;
    $usagePercent = $transferTotal > 0 ? ($transferUsed / $transferTotal) * 100 : 0;

    // 进度条
    $barLen = 20;
    $filled = (int) round($usagePercent / 100 * $barLen);
    $bar = str_repeat('▓', min($filled, $barLen)) . str_repeat('░', max(0, $barLen - $filled));

    $text = "👤 *账户总览*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "📧 邮箱：`{$user->email}`\n";
    $text .= "📦 套餐：`" . ($user->plan ? $user->plan->name : '未订购') . "`\n";
    $text .= "⏰ 到期：`" . ($user->expired_at ? date('Y-m-d', $user->expired_at) : '长期有效') . "`\n";

    if ($user->expired_at && $user->expired_at > time()) {
      $daysLeft = ceil(($user->expired_at - time()) / 86400);
      $text .= "📅 剩余：`{$daysLeft}天`\n";
    }

    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "📊 流量使用\n";
    $text .= "`{$bar}` " . sprintf('%.1f%%', $usagePercent) . "\n";
    $text .= "⬆️ 上行：`" . $this->transferToGBString($user->u) . "G`\n";
    $text .= "⬇️ 下行：`" . $this->transferToGBString($user->d) . "G`\n";
    $text .= "📉 剩余：`" . $this->transferToGBString($transferRemaining) . "G` / " . $this->transferToGBString($transferTotal) . "G\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "💰 余额：`" . ($user->balance / 100) . "元`\n";
    $text .= "💸 佣金：`" . ($user->commission_balance / 100) . "元`\n";

    if ($user->device_limit) {
      $text .= "📱 设备限制：`{$user->device_limit}台`\n";
    }
    if ($user->speed_limit) {
      $text .= "🚀 限速：`{$user->speed_limit}Mbps`\n";
    }

    $this->sendMessage($msg, $text);
  }

  /**
   * /node — 节点状态总览（含中国可达性探测）
   */
  public function handleNodeCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    // 获取用户可见的节点（按分组过滤）
    $servers = Server::where('show', true)
      ->orderBy('sort')
      ->get();

    if ($servers->isEmpty()) {
      $this->sendMessage($msg, '暂无可用节点');
      return;
    }

    // ── 中国探测服务（可选）──
    $probeUrl = trim($this->getConfig('node_check_probe_url', ''));
    $probeResults = [];
    if ($probeUrl) {
      $probeResults = $this->probeNodesFromChina($servers, $probeUrl);
    }

    $online = 0;
    $offline = 0;
    $blocked = 0;
    $lines = [];

    foreach ($servers as $server) {
      $status = $server->available_status;
      $onlineUsers = $server->online ?? 0;

      // 面板内部状态
      if ($status >= 1) {
        $online++;
        $icon = $onlineUsers > 0 ? '🟢' : '🟡';
        $userInfo = $onlineUsers > 0 ? " ({$onlineUsers}人)" : '';
      } else {
        $offline++;
        $icon = '🔴';
        $userInfo = '';
      }

      // 中国探测结果叠加
      $probeInfo = '';
      $serverKey = $server->host . ':' . ($server->server_port ?? $server->port ?? 443);
      if (isset($probeResults[$serverKey])) {
        $pr = $probeResults[$serverKey];
        if ($pr['reachable']) {
          $probeInfo = " 🇨🇳✅{$pr['latency_ms']}ms";
        } else {
          $probeInfo = " 🇨🇳❌" . ($pr['error'] ?: '不可达');
          // 面板显示在线但中国不可达 = 疑似被墙
          if ($status >= 1) {
            $icon = '🟠'; // 橙色 = 疑似被墙
            $blocked++;
          }
        }
      }

      // 加载状态
      $loadInfo = '';
      if ($server->load_status) {
        $load = $server->load_status;
        if (is_string($load)) $load = json_decode($load, true);
        if (is_array($load) && isset($load['cpu'])) {
          $loadInfo = " CPU:" . $load['cpu'] . "%";
        }
      }

      $lines[] = "{$icon} `{$server->name}`{$userInfo}{$loadInfo}{$probeInfo}";
    }

    $total = count($servers);
    $text = "🌐 *节点状态* ({$online}/{$total} 在线)\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= implode("\n", $lines);

    if ($offline > 0 || $blocked > 0) {
      $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
      if ($offline > 0) $text .= "⚠️ {$offline}个节点离线\n";
      if ($blocked > 0) $text .= "🟠 {$blocked}个节点疑似被墙\n";
    }

    $text .= "\n🟢在线 🟡无推送 🔴离线";
    if ($probeUrl) {
      $text .= " 🟠疑似被墙\n🇨🇳 = 中国可达性探测";
    }

    $this->sendMessage($msg, $text);
  }

  /**
   * 从中国探测节点调用 TCP 连通性测试
   */
  protected function probeNodesFromChina($servers, string $probeUrl): array
  {
    $targets = [];
    foreach ($servers as $server) {
      $host = $server->host;
      $port = $server->server_port ?? $server->port ?? 443;
      if ($host) {
        $targets[] = ['host' => $host, 'port' => (int) $port];
      }
    }

    if (empty($targets)) return [];

    try {
      $response = Http::timeout(15)->post($probeUrl, ['targets' => $targets]);
      if (!$response->ok()) return [];

      $data = $response->json();
      $results = [];
      foreach ($data['results'] ?? [] as $r) {
        $key = $r['host'] . ':' . $r['port'];
        $results[$key] = $r;
      }
      return $results;
    } catch (\Exception $e) {
      Log::warning('中国探测服务调用失败', ['url' => $probeUrl, 'error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * /invite — 邀请返利信息
   */
  public function handleInviteCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    // 获取邀请码
    $codes = InviteCode::where('user_id', $user->id)
      ->where('status', InviteCode::STATUS_UNUSED)
      ->get();

    // 统计邀请人数
    $invitedCount = User::where('invite_user_id', $user->id)->count();

    // 佣金信息
    $commissionRate = $user->commission_rate ?? (int) admin_setting('invite_commission', 10);
    $commissionBalance = $user->commission_balance / 100;

    // 佣金类型
    $typeMap = [0 => '跟随系统', 1 => '循环返利', 2 => '一次性'];
    $commissionType = $typeMap[$user->commission_type ?? 0] ?? '跟随系统';

    $text = "👥 *邀请返利*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "👤 已邀请：`{$invitedCount}人`\n";
    $text .= "💸 佣金余额：`{$commissionBalance}元`\n";
    $text .= "📊 返佣比例：`{$commissionRate}%`\n";
    $text .= "🔄 返佣类型：`{$commissionType}`\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";

    if ($codes->isEmpty()) {
      $text .= "暂无可用邀请码\n";
    } else {
      $text .= "🎫 *可用邀请码*：\n";
      foreach ($codes->take(5) as $code) {
        $text .= "  `{$code->code}`\n";
      }
      if ($codes->count() > 5) {
        $text .= "  ...还有 " . ($codes->count() - 5) . " 个\n";
      }
    }

    $text .= "\n💡 邀请好友注册后下单，您可获得订单金额 {$commissionRate}% 的佣金";

    $this->sendMessage($msg, $text);
  }

  /**
   * /renew — 快捷续费链接
   */
  public function handleRenewCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $subscribeUrls = admin_setting('subscribe_url', '');
    $siteUrl = $subscribeUrls ? explode(',', $subscribeUrls)[0] : url('/');
    $siteUrl = rtrim($siteUrl, '/');

    // 提取域名部分（不带路径）
    $parsed = parse_url($siteUrl);
    $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? $siteUrl);
    if (isset($parsed['port'])) $baseUrl .= ':' . $parsed['port'];

    $text = "🔄 *快捷续费*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";

    if ($user->plan) {
      $text .= "📦 当前套餐：`{$user->plan->name}`\n";
      if ($user->expired_at) {
        $daysLeft = max(0, ceil(($user->expired_at - time()) / 86400));
        $text .= "⏰ 剩余天数：`{$daysLeft}天`\n";
      }
      $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    }

    $text .= "🌐 请点击以下链接续费：\n";
    $text .= "{$baseUrl}/#/plan\n\n";
    $text .= "💡 登录后可选择续费或更换套餐";

    $this->sendMessage($msg, $text);
  }

  public function handleTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $transferUsed = $user->u + $user->d;
    $transferTotal = $user->transfer_enable;
    $transferRemaining = $transferTotal - $transferUsed;
    $usagePercentage = $transferTotal > 0 ? ($transferUsed / $transferTotal) * 100 : 0;

    $text = sprintf(
      "📊 流量使用情况\n\n已用流量：%sG\n总流量：%sG\n剩余流量：%sG\n使用率：%.2f%%",
      $this->transferToGBString($transferUsed),
      $this->transferToGBString($transferTotal),
      $this->transferToGBString($transferRemaining),
      $usagePercentage
    );

    $this->sendMessage($msg, $text);
  }

  public function handleGetLatestUrlCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $subscribeUrl = Helper::getSubscribeUrl($user->token);
    $text = sprintf("🔗 您的订阅链接：\n\n%s", $subscribeUrl);

    $this->sendMessage($msg, $text);
  }

  public function handleBindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $subscribeUrl = $msg->args[0] ?? null;
    if (!$subscribeUrl) {
      $this->sendMessage($msg, '参数有误，请携带订阅地址发送');
      return;
    }

    $token = $this->extractTokenFromUrl($subscribeUrl);
    if (!$token) {
      $this->sendMessage($msg, '订阅地址无效');
      return;
    }

    $user = User::where('token', $token)->first();
    if (!$user) {
      $this->sendMessage($msg, '用户不存在');
      return;
    }

    if ($user->telegram_id) {
      $this->sendMessage($msg, '该账号已经绑定了Telegram账号');
      return;
    }

    $user->telegram_id = $msg->chat_id;
    if (!$user->save()) {
      $this->sendMessage($msg, '设置失败');
      return;
    }

    HookManager::call('user.telegram.bind.after', [$user]);
    $this->sendMessage($msg, '绑定成功');
  }

  public function handleUnbindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $user->telegram_id = null;
    if (!$user->save()) {
      $this->sendMessage($msg, '解绑失败');
      return;
    }

    $this->sendMessage($msg, '解绑成功');
  }

  /**
   * /plan — 查看在售套餐列表
   */
  public function handlePlanCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $plans = Plan::where('show', true)
      ->where('sell', true)
      ->orderBy('sort')
      ->get();

    if ($plans->isEmpty()) {
      $this->sendMessage($msg, '暂无可购买套餐');
      return;
    }

    $periodNames = [
      'monthly' => '月付', 'quarterly' => '季付', 'half_yearly' => '半年',
      'yearly' => '年付', 'two_yearly' => '两年', 'three_yearly' => '三年',
      'onetime' => '一次性', 'reset_traffic' => '流量重置',
    ];

    $text = "📦 *在售套餐*\n";

    foreach ($plans as $plan) {
      $text .= "━━━━━━━━━━━━━━━━━━━━\n";
      $text .= "📌 *{$plan->name}*\n";
      $text .= "  📊 流量：`{$plan->transfer_enable}G`";
      if ($plan->speed_limit) $text .= " | 限速 `{$plan->speed_limit}Mbps`";
      if ($plan->device_limit) $text .= " | `{$plan->device_limit}台`";
      $text .= "\n";

      $prices = $plan->prices ?? [];
      if (!empty($prices)) {
        $priceLines = [];
        foreach ($prices as $period => $price) {
          if ($price === null) continue;
          $name = $periodNames[$period] ?? $period;
          $priceLines[] = "{$name}:`" . ($price / 100) . "元`";
        }
        if (!empty($priceLines)) {
          $text .= "  💰 " . implode(' / ', $priceLines) . "\n";
        }
      }

      if ($plan->content) {
        $desc = mb_substr(strip_tags($plan->content), 0, 50);
        $text .= "  📝 {$desc}\n";
      }
    }

    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "发送 /renew 获取购买链接";

    $this->sendMessage($msg, $text);
  }

  /**
   * /lucky — 幸运抽奖（每周一次）
   */
  public function handleLuckyCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    if (!$this->getConfig('enable_lucky', true)) {
      $this->sendMessage($msg, '抽奖功能已关闭');
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $weekKey = date('Y-W');
    $cacheKey = "tg_lucky:{$user->id}:{$weekKey}";
    if (Cache::has($cacheKey)) {
      $lastResult = Cache::get($cacheKey);
      $this->sendMessage($msg, "🎰 本周已参与抽奖\n\n上次获得：{$lastResult}\n下周再来试试运气吧~");
      return;
    }

    $minMB = max(1, (int) $this->getConfig('lucky_min_mb', '500'));
    $maxMB = max($minMB, (int) $this->getConfig('lucky_max_mb', '5000'));

    $roll = random_int(1, 100);
    $rewardText = '';
    $resultCache = '';

    if ($roll <= 5) {
      // 5% 概率：余额奖励 1~5 元
      $yuan = random_int(1, 5);
      $user->balance += $yuan * 100;
      $user->save();
      $rewardText = "💎 *超级大奖！* 余额 +{$yuan}元";
      $resultCache = "💎 余额 +{$yuan}元";
    } elseif ($roll <= 20) {
      // 15% 概率：大流量
      $reward = random_int((int)($maxMB * 0.6), $maxMB);
      $user->transfer_enable += $reward * 1024 * 1024;
      $user->save();
      $rewardText = "🌟 *好运！* 流量 +{$reward}MB";
      $resultCache = "🌟 流量 +{$reward}MB";
    } elseif ($roll <= 50) {
      // 30% 概率：中等流量
      $reward = random_int((int)($minMB * 2), (int)($maxMB * 0.6));
      $user->transfer_enable += $reward * 1024 * 1024;
      $user->save();
      $rewardText = "✨ 流量 +{$reward}MB";
      $resultCache = "✨ 流量 +{$reward}MB";
    } else {
      // 50% 概率：小流量
      $reward = random_int($minMB, (int)($minMB * 2));
      $user->transfer_enable += $reward * 1024 * 1024;
      $user->save();
      $rewardText = "🎁 流量 +{$reward}MB";
      $resultCache = "🎁 流量 +{$reward}MB";
    }

    Cache::put($cacheKey, $resultCache, strtotime('next monday') - time());

    $remaining = $user->transfer_enable - $user->u - $user->d;
    $text = "🎰 *幸运抽奖*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "🎊 恭喜！{$rewardText}\n";
    $text .= "📊 当前剩余流量：" . $this->transferToGBString($remaining) . "G\n";
    $text .= "💰 当前余额：`" . ($user->balance / 100) . "元`\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "🎲 概率: 50%小奖 30%中奖 15%大奖 5%余额\n";
    $text .= "⏰ 每周可抽一次";

    $this->sendMessage($msg, $text);
  }

  /**
   * /reset — 重置订阅链接（更换 Token + UUID）
   */
  public function handleResetCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $cacheKey = "tg_reset:{$user->id}";
    if (Cache::has($cacheKey)) {
      $this->sendMessage($msg, "⏳ 请等待 10 分钟后再次重置");
      return;
    }

    $user->uuid = Helper::guid(true);
    $user->token = Helper::guid();
    $user->save();

    Cache::put($cacheKey, true, 600);

    $newUrl = Helper::getSubscribeUrl($user->token);

    $text = "🔄 *订阅已重置*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "✅ 已生成新的 Token 和 UUID\n";
    $text .= "旧的订阅链接将立即失效\n\n";
    $text .= "🔗 新订阅链接：\n`{$newUrl}`\n\n";
    $text .= "⚠️ 请在客户端中更新订阅链接";

    $this->sendMessage($msg, $text);
  }

  /**
   * /ticket <内容> — 通过 TG 快捷创建工单
   */
  public function handleTicketCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $content = implode(' ', $msg->args ?? []);
    if (empty(trim($content))) {
      $this->sendMessage($msg, "用法：/ticket <问题描述>\n\n例如：/ticket 无法连接节点，请帮忙检查");
      return;
    }

    try {
      $ticketService = new TicketService();
      $ticket = $ticketService->createTicket(
        $user->id,
        mb_substr($content, 0, 30),
        2,
        $content
      );

      $text = "📮 *工单已创建*\n";
      $text .= "━━━━━━━━━━━━━━━━━━━━\n";
      $text .= "🔢 工单号：`#{$ticket->id}`\n";
      $text .= "📝 内容：{$content}\n\n";
      $text .= "客服会尽快回复，请耐心等待\n";
      $text .= "回复将通过 Telegram 通知您";

      $this->sendMessage($msg, $text);
    } catch (\Exception $e) {
      $errMsg = $e->getMessage();
      if (str_contains($errMsg, '未关闭')) {
        $this->sendMessage($msg, "❌ 您有未关闭的工单，请先处理后再创建新工单");
      } else {
        $this->sendMessage($msg, "❌ 创建失败：{$errMsg}");
      }
    }
  }

  /**
   * /rank — 邀请排行榜 Top 10
   */
  public function handleRankCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $ranking = DB::select("
      SELECT u.id, u.email, u.commission_balance, COUNT(u2.id) as invite_count
      FROM v2_user u
      INNER JOIN v2_user u2 ON u2.invite_user_id = u.id
      GROUP BY u.id, u.email, u.commission_balance
      ORDER BY invite_count DESC
      LIMIT 10
    ");

    if (empty($ranking)) {
      $this->sendMessage($msg, '暂无邀请记录，快去邀请好友吧！');
      return;
    }

    $medals = ['🥇', '🥈', '🥉'];
    $text = "🏆 *邀请排行榜 Top 10*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";

    foreach ($ranking as $i => $r) {
      $medal = $medals[$i] ?? '  ' . ($i + 1) . '.';
      $email = $r->email;
      $atPos = strpos($email, '@');
      if ($atPos > 2) {
        $email = substr($email, 0, 2) . str_repeat('*', $atPos - 2) . substr($email, $atPos);
      }
      $commission = $r->commission_balance / 100;
      $text .= "{$medal} `{$email}` — {$r->invite_count}人 (💰{$commission}元)\n";
    }

    $myCount = User::where('invite_user_id', $user->id)->count();
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "📍 您的邀请：`{$myCount}人`\n";
    $text .= "\n💡 发送 /invite 查看您的邀请详情";

    $this->sendMessage($msg, $text);
  }

  /**
   * /wallet — 我的钱包
   */
  public function handleWalletCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $balance = $user->balance / 100;
    $commission = $user->commission_balance / 100;
    $totalSpent = Order::where('user_id', $user->id)
      ->where('status', Order::STATUS_COMPLETED)
      ->sum('total_amount') / 100;

    $discount = $user->discount ? "{$user->discount}%" : '无';

    $text = "💰 *我的钱包*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "💵 账户余额：`{$balance}元`\n";
    $text .= "💸 佣金余额：`{$commission}元`\n";
    $text .= "🛍️ 累计消费：`{$totalSpent}元`\n";
    $text .= "🏷️ 专属折扣：`{$discount}`\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";

    $subscribeUrls = admin_setting('subscribe_url', '');
    $siteUrl = $subscribeUrls ? explode(',', $subscribeUrls)[0] : url('/');
    $siteUrl = rtrim($siteUrl, '/');
    $parsed = parse_url($siteUrl);
    $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? $siteUrl);
    if (isset($parsed['port'])) $baseUrl .= ':' . $parsed['port'];

    if ($commission > 0) {
      $text .= "💡 佣金可在面板「佣金管理」中提现到余额\n";
    }
    $text .= "🌐 充值续费：{$baseUrl}/#/plan";

    $this->sendMessage($msg, $text);
  }

  /**
   * /order — 最近订单
   */
  public function handleOrderCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;

    $user = $this->getBoundUser($msg);
    if (!$user) return;

    $orders = Order::where('user_id', $user->id)
      ->orderBy('created_at', 'desc')
      ->limit(5)
      ->get();

    if ($orders->isEmpty()) {
      $this->sendMessage($msg, '暂无订单记录');
      return;
    }

    $statusMap = [
      0 => '⏳待支付', 1 => '⚙️开通中', 2 => '❌已取消',
      3 => '✅已完成', 4 => '🔄已折抵',
    ];
    $typeMap = [
      1 => '新购', 2 => '续费', 3 => '升级', 4 => '流量重置',
    ];

    $text = "🧾 *最近订单*\n";

    foreach ($orders as $order) {
      $status = $statusMap[$order->status] ?? '未知';
      $type = $typeMap[$order->type] ?? '未知';
      $amount = $order->total_amount / 100;
      $date = date('m-d H:i', $order->created_at);

      $text .= "━━━━━━━━━━━━━━━━━━━━\n";
      $text .= "{$status} {$type} `{$amount}元`\n";
      $text .= "  订单号：`{$order->trade_no}`\n";
      $text .= "  时间：`{$date}`\n";
    }

    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "💡 发送 /renew 获取续费链接";

    $this->sendMessage($msg, $text);
  }

  // ══════════════════════════════════════════
  // 管理员命令
  // ══════════════════════════════════════════

  /**
   * /search <邮箱关键词> — 管理员查询用户
   */
  public function handleSearchCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $admin = $this->checkAdmin($msg);
    if (!$admin) return;

    $keyword = $msg->args[0] ?? null;
    if (!$keyword) {
      $this->sendMessage($msg, '用法：/search <邮箱关键词>');
      return;
    }

    $users = User::where('email', 'like', "%{$keyword}%")
      ->limit(5)
      ->get();

    if ($users->isEmpty()) {
      $this->sendMessage($msg, "未找到匹配 `{$keyword}` 的用户");
      return;
    }

    $text = "🔍 *搜索结果*（匹配: {$keyword}）\n\n";

    foreach ($users as $u) {
      $u->load('plan');
      $status = $u->banned ? '🚫封禁' : '✅正常';
      $planName = $u->plan ? $u->plan->name : '无套餐';
      $balance = $u->balance / 100;
      $expired = $u->expired_at ? date('Y-m-d', $u->expired_at) : '永不';
      $remaining = $this->transferToGBString($u->transfer_enable - $u->u - $u->d);

      $text .= "━━━━━━━━━━━━━━━━━━━━\n";
      $text .= "📧 `{$u->email}` {$status}\n";
      $text .= "  ID: `{$u->id}` | 套餐: `{$planName}`\n";
      $text .= "  余额: `{$balance}元` | 到期: `{$expired}`\n";
      $text .= "  剩余流量: `{$remaining}G`\n";
    }

    $this->sendMessage($msg, $text);
  }

  /**
   * /broadcast — 向所有绑定 TG 的用户群发消息
   * 第一步：发送提示，管理员通过回复来输入群发内容
   */
  public function handleBroadcastCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $admin = $this->checkAdmin($msg);
    if (!$admin) return;

    $userCount = User::whereNotNull('telegram_id')->count();

    $text = "📢 *群发通知*\n\n";
    $text .= "当前已绑定 TG 的用户数：`{$userCount}`\n\n";
    $text .= "请 *回复本消息* 输入要群发的内容\n";
    $text .= "（回复后将立即发送给所有绑定用户）";

    $this->sendMessage($msg, $text);
  }

  /**
   * 群发内容处理（回复 "群发通知" 消息触发）
   */
  public function handleBroadcastReply(object $msg, array $matches): void
  {
    $admin = $this->checkAdmin($msg);
    if (!$admin) return;

    $content = $msg->text;
    if (empty(trim($content))) {
      $this->sendMessage($msg, '群发内容不能为空');
      return;
    }

    $users = User::whereNotNull('telegram_id')->get();
    $success = 0;
    $fail = 0;

    foreach ($users as $user) {
      try {
        $this->telegramService->sendMessage($user->telegram_id, "📢 *系统通知*\n\n{$content}", 'markdown');
        $success++;
      } catch (\Exception $e) {
        $fail++;
      }
      // 避免触发 Telegram 限速
      usleep(50000); // 50ms
    }

    $this->sendMessage($msg, "✅ 群发完成\n\n成功：{$success}\n失败：{$fail}");
  }

  /**
   * /stats — 运营统计概览
   */
  public function handleStatsCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $admin = $this->checkAdmin($msg);
    if (!$admin) return;

    $today = strtotime('today');
    $month = strtotime('first day of this month');

    // 用户统计
    $totalUsers = User::count();
    $todayReg = User::where('created_at', '>=', $today)->count();
    $monthReg = User::where('created_at', '>=', $month)->count();
    $tgBound = User::whereNotNull('telegram_id')->count();

    // 收入统计
    $todayIncome = Order::where('status', 3) // 已完成
      ->where('created_at', '>=', $today)
      ->sum('total_amount') / 100;
    $monthIncome = Order::where('status', 3)
      ->where('created_at', '>=', $month)
      ->sum('total_amount') / 100;

    // 今日订单数
    $todayOrders = Order::where('status', 3)
      ->where('created_at', '>=', $today)
      ->count();

    // 节点状态
    $servers = Server::where('show', true)->get();
    $onlineNodes = $servers->filter(fn($s) => $s->is_online)->count();
    $totalNodes = $servers->count();

    // 在线用户总数
    $totalOnline = $servers->sum(fn($s) => $s->online ?? 0);

    $text = "📊 *运营统计*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "👥 *用户*\n";
    $text .= "  总用户：`{$totalUsers}`\n";
    $text .= "  今日注册：`{$todayReg}`\n";
    $text .= "  本月注册：`{$monthReg}`\n";
    $text .= "  TG绑定：`{$tgBound}`\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "💰 *收入*\n";
    $text .= "  今日：`{$todayIncome}元` ({$todayOrders}单)\n";
    $text .= "  本月：`{$monthIncome}元`\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "🌐 *节点*\n";
    $text .= "  在线：`{$onlineNodes}/{$totalNodes}`\n";
    $text .= "  当前在线用户：`{$totalOnline}`\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "⏰ 统计时间：`" . date('Y-m-d H:i') . "`";

    $this->sendMessage($msg, $text);
  }

  /**
   * /ban <邮箱> — 管理员封禁/解封用户
   */
  public function handleBanCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $admin = $this->checkAdmin($msg);
    if (!$admin) return;

    $email = $msg->args[0] ?? null;
    if (!$email) {
      $this->sendMessage($msg, "用法：/ban <邮箱>\n再次对同一用户执行将解封");
      return;
    }

    $targetUser = User::where('email', $email)->first();
    if (!$targetUser) {
      $this->sendMessage($msg, "未找到用户：`{$email}`");
      return;
    }

    if ($targetUser->is_admin) {
      $this->sendMessage($msg, "❌ 无法操作管理员账号");
      return;
    }

    $targetUser->banned = !$targetUser->banned;
    $targetUser->save();

    $action = $targetUser->banned ? '🚫 已封禁' : '✅ 已解封';
    $text = "{$action}\n";
    $text .= "📧 用户：`{$targetUser->email}`\n";
    $text .= "🆔 ID：`{$targetUser->id}`";

    $this->sendMessage($msg, $text);
  }

  // ══════════════════════════════════════════
  // 消息分发 & 工单回复
  // ══════════════════════════════════════════

  public function handleMessage(bool $handled, array $data): bool
  {
    list($msg) = $data;
    if ($handled)
      return $handled;

    try {
      return match ($msg->message_type) {
        'message' => $this->handleCommandMessage($msg),
        'reply_message' => $this->handleReplyMessage($msg),
        default => false
      };
    } catch (\Exception $e) {
      Log::error('Telegram 命令处理意外错误', [
        'command' => $msg->command ?? 'unknown',
        'chat_id' => $msg->chat_id ?? 'unknown',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
      ]);

      if (isset($msg->chat_id)) {
        $this->telegramService->sendMessage($msg->chat_id, '系统繁忙，请稍后重试');
      }

      return true;
    }
  }

  protected function handleCommandMessage(object $msg): bool
  {
    if (!isset($this->commands['commands'][$msg->command])) {
      return false;
    }

    call_user_func($this->commands['commands'][$msg->command], $msg);
    return true;
  }

  protected function handleReplyMessage(object $msg): bool
  {
    if (!isset($this->commands['replies'])) {
      return false;
    }

    foreach ($this->commands['replies'] as $regex => $handler) {
      if (preg_match($regex, $msg->reply_text, $matches)) {
        call_user_func($handler, $msg, $matches);
        return true;
      }
    }

    return false;
  }

  public function handleUnknownCommand(array $data): void
  {
    list($msg) = $data;
    if (!$msg->is_private || $msg->message_type !== 'message')
      return;

    $helpText = $this->getConfig('help_text', '未知命令，请查看帮助');
    $helpText = str_replace('\\n', "\n", $helpText);
    $this->telegramService->sendMessage($msg->chat_id, $helpText);
  }

  public function handleError(array $data): void
  {
    list($msg, $e) = $data;
    Log::error('Telegram 消息处理错误', [
      'chat_id' => $msg->chat_id ?? 'unknown',
      'command' => $msg->command ?? 'unknown',
      'message_type' => $msg->message_type ?? 'unknown',
      'error' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]);
  }

  public function handleTicketReply(object $msg, array $matches): void
  {
    $user = $this->getBoundUser($msg);
    if (!$user) return;

    if (!isset($matches[2]) || !is_numeric($matches[2])) {
      Log::warning('Telegram 工单回复正则未匹配到工单ID', ['matches' => $matches, 'msg' => $msg]);
      $this->sendMessage($msg, '未能识别工单ID，请直接回复工单提醒消息。');
      return;
    }

    $ticketId = (int) $matches[2];
    $ticket = Ticket::where('id', $ticketId)->first();
    if (!$ticket) {
      $this->sendMessage($msg, '工单不存在');
      return;
    }

    $replyText = $msg->text;

    // 如果管理员发送了图片，下载保存到服务器
    if (isset($msg->photo_file_id)) {
      try {
        $fileInfo = $this->telegramService->getFile($msg->photo_file_id);
        $filePath = $fileInfo->result->file_path;
        $fileUrl = $this->telegramService->getFileUrl($filePath);

        $imageContent = file_get_contents($fileUrl);
        if ($imageContent === false) {
          throw new \Exception('无法从 Telegram 下载图片');
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
        $filename = date('Ymd') . '_' . \Illuminate\Support\Str::random(16) . '.' . $ext;
        $storagePath = 'uploads/tickets';

        $pluginModel = \App\Models\Plugin::where('code', 'ticket_image_upload')->where('is_enabled', true)->first();
        if ($pluginModel) {
          $config = json_decode($pluginModel->config, true) ?? [];
          $storagePath = $config['storage_path'] ?? 'uploads/tickets';
        }

        $fullDir = storage_path('app/public/' . $storagePath);
        if (!is_dir($fullDir)) {
          mkdir($fullDir, 0755, true);
        }

        file_put_contents($fullDir . '/' . $filename, $imageContent);

        $imageUrl = "/api/v1/guest/upload/image/{$filename}";

        if ($replyText !== '[图片]') {
          $replyText = "[图片] {$imageUrl}\n{$replyText}";
        } else {
          $replyText = "[图片] {$imageUrl}";
        }
      } catch (\Exception $e) {
        Log::error('Telegram 管理员图片处理失败', ['error' => $e->getMessage()]);
        $this->sendMessage($msg, '图片处理失败: ' . $e->getMessage());
        return;
      }
    }

    $ticketService = new TicketService();
    $ticketService->replyByAdmin(
      $ticketId,
      $replyText,
      $user->id
    );

    $this->sendMessage($msg, "工单 #{$ticketId} 回复成功");
  }

  /**
   * 添加 Bot 命令到命令列表
   */
  public function addBotCommands(array $commands): array
  {
    // 只注册用户可见的命令（不含管理员命令）
    $userCommands = [
      '/start', '/bind', '/checkin', '/status', '/traffic',
      '/node', '/invite', '/renew', '/plan', '/lucky',
      '/reset', '/ticket', '/rank', '/wallet', '/order',
      '/getlatesturl', '/unbind',
    ];

    foreach ($userCommands as $cmd) {
      if (isset($this->commandConfigs[$cmd])) {
        $commands[] = [
          'command' => $cmd,
          'description' => $this->commandConfigs[$cmd]['description']
        ];
      }
    }

    return $commands;
  }

  // ══════════════════════════════════════════
  // 工具方法
  // ══════════════════════════════════════════

  protected function extractTokenFromUrl(string $url): ?string
  {
    $parsedUrl = parse_url($url);

    if (isset($parsedUrl['query'])) {
      parse_str($parsedUrl['query'], $query);
      if (isset($query['token'])) {
        return $query['token'];
      }
    }

    if (isset($parsedUrl['path'])) {
      $pathParts = explode('/', trim($parsedUrl['path'], '/'));
      $lastPart = end($pathParts);
      return $lastPart ?: null;
    }

    return null;
  }

  private function transferToGBString(float $transfer_enable, int $decimals = 2): string
  {
    return number_format(Helper::transferToGB($transfer_enable), $decimals, '.', '');
  }
}