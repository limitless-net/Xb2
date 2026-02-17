#!/usr/bin/env php
<?php
/**
 * XBoard Telegram 图片支持 — 核心补丁脚本
 * 
 * 用法: 在 XBoard 根目录运行
 *   php plugins/Telegram/patch_core.php
 * 
 * 修改文件:
 *   1. app/Http/Controllers/V1/Guest/TelegramController.php — formatMessage() 支持图片消息
 *   2. app/Services/TelegramService.php — 新增 sendPhoto/getFile/getFileUrl 方法
 * 
 * 安全说明:
 *   - 补丁前自动创建 .bak 备份
 *   - 已打补丁的文件不会重复修改
 *   - 使用 --revert 可还原原始文件
 */

$baseDir = realpath(__DIR__ . '/../../');
if (!file_exists($baseDir . '/artisan')) {
    echo "❌ 无法定位 XBoard 根目录，请确保脚本位于 plugins/Telegram/ 下\n";
    exit(1);
}

$revert = in_array('--revert', $argv);

echo "╔══════════════════════════════════════════╗\n";
echo "║  XBoard Telegram 图片支持 核心补丁       ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

$files = [
    'controller' => $baseDir . '/app/Http/Controllers/V1/Guest/TelegramController.php',
    'service'    => $baseDir . '/app/Services/TelegramService.php',
];

// ── 还原模式 ──────────────────────────────────────
if ($revert) {
    echo "🔄 还原模式\n\n";
    $restored = 0;
    foreach ($files as $key => $path) {
        $bak = $path . '.bak';
        if (file_exists($bak)) {
            copy($bak, $path);
            echo "  ✅ 已还原: " . basename($path) . "\n";
            $restored++;
        } else {
            echo "  ⏭️  无备份: " . basename($path) . "\n";
        }
    }
    echo "\n还原完成 ($restored 个文件)\n";
    exit(0);
}

// ── 补丁模式 ──────────────────────────────────────
$results = ['patched' => 0, 'skipped' => 0, 'failed' => 0];

// ─── 1. TelegramController.php ───────────────────
echo "1️⃣  TelegramController.php\n";
$ctrlFile = $files['controller'];

if (!file_exists($ctrlFile)) {
    echo "   ❌ 文件不存在: $ctrlFile\n";
    $results['failed']++;
} else {
    $ctrlContent = file_get_contents($ctrlFile);

    if (strpos($ctrlContent, 'photo_file_id') !== false) {
        echo "   ⏭️  已包含图片支持补丁，跳过\n";
        $results['skipped']++;
    } else {
        // 备份
        copy($ctrlFile, $ctrlFile . '.bak');
        echo "   📦 已备份原始文件\n";

        // 查找原始 formatMessage 方法并替换
        // 原始代码模式: 只检查 text，不支持 photo
        $oldPattern = <<<'PATTERN'
    private function formatMessage(array $data): void
    {
        if (!isset($data['message']['text']))
            return;
PATTERN;

        // 也尝试不同格式的原始代码
        $oldPatternAlt = <<<'PATTERN'
    private function formatMessage(array $data): void
    {
        if (!isset($data['message']['text'])) return;
PATTERN;

        $newFormatMessageStart = <<<'CODE'
    private function formatMessage(array $data): void
    {
        if (!isset($data['message']))
            return;

        $message = $data['message'];

        // 支持文本消息和图片消息（图片的文字在 caption 中）
        $messageText = $message['text'] ?? $message['caption'] ?? null;
        $hasPhoto = isset($message['photo']) && is_array($message['photo']) && count($message['photo']) > 0;

        // 既没有文字也没有图片，忽略
        if (!$messageText && !$hasPhoto)
            return;

        // 纯图片消息（无文字），设置默认文本
        if (!$messageText) {
            $messageText = '[图片]';
        }

        $text = explode(' ', $messageText);
CODE;

        $patched = false;

        // 尝试匹配原始代码
        if (strpos($ctrlContent, $oldPattern) !== false) {
            // 找到原始代码块直到 $text = explode(...)
            $startPos = strpos($ctrlContent, $oldPattern);
            $textExplodeLine = "\$text = explode(' ', \$data['message']['text']);";
            $endPos = strpos($ctrlContent, $textExplodeLine, $startPos);

            if ($endPos !== false) {
                $endPos += strlen($textExplodeLine);
                $oldBlock = substr($ctrlContent, $startPos, $endPos - $startPos);
                $ctrlContent = str_replace($oldBlock, $newFormatMessageStart, $ctrlContent);
                $patched = true;
            }
        }

        if (!$patched && strpos($ctrlContent, $oldPatternAlt) !== false) {
            $startPos = strpos($ctrlContent, $oldPatternAlt);
            $textExplodeLine = "\$text = explode(' ', \$data['message']['text']);";
            $endPos = strpos($ctrlContent, $textExplodeLine, $startPos);

            if ($endPos !== false) {
                $endPos += strlen($textExplodeLine);
                $oldBlock = substr($ctrlContent, $startPos, $endPos - $startPos);
                $ctrlContent = str_replace($oldBlock, $newFormatMessageStart, $ctrlContent);
                $patched = true;
            }
        }

        if (!$patched) {
            // 尝试更宽松的匹配: 只替换 formatMessage 开头几行
            if (preg_match('/private\s+function\s+formatMessage\s*\(\s*array\s+\$data\s*\)\s*:\s*void\s*\{[^}]*?\$text\s*=\s*explode\(\s*\' \'\s*,\s*\$data\[\'message\'\]\[\'text\'\]\s*\)\s*;/s', $ctrlContent, $matches)) {
                $ctrlContent = str_replace($matches[0], $newFormatMessageStart, $ctrlContent);
                $patched = true;
            }
        }

        if ($patched) {
            // 替换 $this->msg 构造：将 $data['message'] 引用改为 $message
            $ctrlContent = str_replace(
                "'chat_id' => \$data['message']['chat']['id'],",
                "'chat_id' => \$message['chat']['id'],",
                $ctrlContent
            );
            $ctrlContent = str_replace(
                "'message_id' => \$data['message']['message_id'],",
                "'message_id' => \$message['message_id'],",
                $ctrlContent
            );
            $ctrlContent = str_replace(
                "'text' => \$data['message']['text'],",
                "'text' => \$messageText,",
                $ctrlContent
            );
            $ctrlContent = str_replace(
                "'is_private' => \$data['message']['chat']['type'] === 'private',",
                "'is_private' => \$message['chat']['type'] === 'private',",
                $ctrlContent
            );

            // 在 msg 对象构造后添加 photo_file_id
            $afterMsgConstruct = "'is_private' => \$message['chat']['type'] === 'private',\n        ];";
            $photoBlock = "'is_private' => \$message['chat']['type'] === 'private',\n        ];\n\n        // 如果消息包含图片，存储最大尺寸图片的 file_id\n        if (\$hasPhoto) {\n            \$photos = \$message['photo'];\n            \$this->msg->photo_file_id = end(\$photos)['file_id'];\n        }";

            if (strpos($ctrlContent, 'photo_file_id') === false) {
                $ctrlContent = str_replace($afterMsgConstruct, $photoBlock, $ctrlContent);
            }

            // 替换 reply_to_message 部分：添加 caption 支持
            $oldReply = "if (isset(\$data['message']['reply_to_message']['text'])) {\n            \$this->msg->message_type = 'reply_message';\n            \$this->msg->reply_text = \$data['message']['reply_to_message']['text'];\n        }";
            
            $newReply = "if (isset(\$message['reply_to_message']['text'])) {\n            \$this->msg->message_type = 'reply_message';\n            \$this->msg->reply_text = \$message['reply_to_message']['text'];\n        } elseif (isset(\$message['reply_to_message']['caption'])) {\n            // 图片消息的文字在 caption 中（sendPhoto 发送的工单通知）\n            \$this->msg->message_type = 'reply_message';\n            \$this->msg->reply_text = \$message['reply_to_message']['caption'];\n        }";

            if (strpos($ctrlContent, $oldReply) !== false) {
                $ctrlContent = str_replace($oldReply, $newReply, $ctrlContent);
            } else {
                // 宽松匹配 reply_to_message
                $ctrlContent = str_replace(
                    "\$data['message']['reply_to_message']['text']",
                    "\$message['reply_to_message']['text']",
                    $ctrlContent
                );
                // 在 reply_text 赋值后添加 caption 分支
                if (strpos($ctrlContent, "reply_to_message']['caption']") === false) {
                    $ctrlContent = str_replace(
                        "\$this->msg->reply_text = \$message['reply_to_message']['text'];\n        }",
                        "\$this->msg->reply_text = \$message['reply_to_message']['text'];\n        } elseif (isset(\$message['reply_to_message']['caption'])) {\n            // 图片消息的文字在 caption 中（sendPhoto 发送的工单通知）\n            \$this->msg->message_type = 'reply_message';\n            \$this->msg->reply_text = \$message['reply_to_message']['caption'];\n        }",
                        $ctrlContent
                    );
                }
            }

            file_put_contents($ctrlFile, $ctrlContent);
            echo "   ✅ 补丁成功: formatMessage() 已支持图片消息\n";
            $results['patched']++;
        } else {
            echo "   ❌ 无法匹配原始代码，可能是 XBoard 版本不兼容\n";
            echo "   💡 请手动参考 patch_core_manual.md 修改\n";
            $results['failed']++;
        }
    }
}

// ─── 2. TelegramService.php ─────────────────────
echo "\n2️⃣  TelegramService.php\n";
$svcFile = $files['service'];

if (!file_exists($svcFile)) {
    echo "   ❌ 文件不存在: $svcFile\n";
    $results['failed']++;
} else {
    $svcContent = file_get_contents($svcFile);

    if (strpos($svcContent, 'function sendPhoto') !== false) {
        echo "   ⏭️  已包含图片支持补丁，跳过\n";
        $results['skipped']++;
    } else {
        // 备份
        copy($svcFile, $svcFile . '.bak');
        echo "   📦 已备份原始文件\n";

        // 在 sendMessageWithAdmin 之前插入新方法
        $insertMethods = <<<'METHODS'

    /**
     * 发送图片消息
     */
    public function sendPhoto(int $chatId, string $photoUrl, string $caption = '', string $parseMode = ''): void
    {
        $caption = $parseMode === 'markdown' ? str_replace('_', '\_', $caption) : $caption;

        $this->request('sendPhoto', array_filter([
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption ?: null,
            'parse_mode' => $parseMode ?: null,
        ]));
    }

    /**
     * 向所有管理员发送图片
     */
    public function sendPhotoWithAdmin(string $photoUrl, string $caption = '', bool $isStaff = false): void
    {
        $query = User::where('telegram_id', '!=', null);
        $query->where(
            fn($q) => $q->where('is_admin', 1)
                ->when($isStaff, fn($q) => $q->orWhere('is_staff', 1))
        );
        $users = $query->get();
        foreach ($users as $user) {
            try {
                $this->sendPhoto($user->telegram_id, $photoUrl, $caption, 'markdown');
            } catch (\Exception $e) {
                Log::warning('Telegram 发送图片失败，回退为文本', ['error' => $e->getMessage()]);
                SendTelegramJob::dispatch($user->telegram_id, $caption . "\n🖼️ 图片: " . $photoUrl);
            }
        }
    }

    /**
     * 获取 Telegram 文件信息
     */
    public function getFile(string $fileId): object
    {
        return $this->request('getFile', ['file_id' => $fileId]);
    }

    /**
     * 获取 Telegram 文件下载 URL
     */
    public function getFileUrl(string $filePath): string
    {
        $botToken = admin_setting('telegram_bot_token');
        return "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
    }

METHODS;

        // 在 sendMessageWithAdmin 方法前插入
        $anchor = '    public function sendMessageWithAdmin(';
        $insertPos = strpos($svcContent, $anchor);

        if ($insertPos !== false) {
            $svcContent = substr($svcContent, 0, $insertPos) . $insertMethods . substr($svcContent, $insertPos);
            file_put_contents($svcFile, $svcContent);
            echo "   ✅ 补丁成功: 新增 sendPhoto / sendPhotoWithAdmin / getFile / getFileUrl\n";
            $results['patched']++;
        } else {
            // 尝试在 request() 之前插入
            $anchor2 = '    protected function request(';
            $insertPos2 = strpos($svcContent, $anchor2);

            if ($insertPos2 !== false) {
                $svcContent = substr($svcContent, 0, $insertPos2) . $insertMethods . substr($svcContent, $insertPos2);
                file_put_contents($svcFile, $svcContent);
                echo "   ✅ 补丁成功: 新增 sendPhoto / sendPhotoWithAdmin / getFile / getFileUrl\n";
                $results['patched']++;
            } else {
                echo "   ❌ 无法定位插入点，请手动添加方法\n";
                $results['failed']++;
            }
        }
    }
}

// ── 结果汇总 ──────────────────────────────────────
echo "\n╔══════════════════════════════════════════╗\n";
echo "║  补丁结果                                ║\n";
echo "╠══════════════════════════════════════════╣\n";
printf("║  ✅ 成功补丁: %d 个文件                    ║\n", $results['patched']);
printf("║  ⏭️  已有补丁: %d 个文件                    ║\n", $results['skipped']);
printf("║  ❌ 补丁失败: %d 个文件                    ║\n", $results['failed']);
echo "╚══════════════════════════════════════════╝\n";

if ($results['failed'] > 0) {
    echo "\n⚠️  部分补丁失败，请手动修改或检查 XBoard 版本兼容性\n";
    echo "   还原命令: php plugins/Telegram/patch_core.php --revert\n";
    exit(1);
}

echo "\n✅ 全部完成！请在管理后台启用 Telegram 和 TicketImageUpload 插件\n";
exit(0);
