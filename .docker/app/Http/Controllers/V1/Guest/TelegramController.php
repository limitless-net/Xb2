<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected ?object $msg = null;
    protected TelegramService $telegramService;
    protected UserService $userService;

    public function __construct(TelegramService $telegramService, UserService $userService)
    {
        $this->telegramService = $telegramService;
        $this->userService = $userService;
    }

    public function webhook(Request $request): void
    {
        $expectedToken = md5(admin_setting('telegram_bot_token'));
        if ($request->input('access_token') !== $expectedToken) {
            throw new ApiException('access_token is error', 401);
        }

        $data = $request->json()->all();

        $this->formatMessage($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    private function handle(): void
    {
        if (!$this->msg)
            return;
        $msg = $this->msg;
        $this->processBotName($msg);
        try {
            HookManager::call('telegram.message.before', [$msg]);
            $handled = HookManager::filter('telegram.message.handle', false, [$msg]);
            if (!$handled) {
                HookManager::call('telegram.message.unhandled', [$msg]);
            }
            HookManager::call('telegram.message.after', [$msg]);
        } catch (\Exception $e) {
            HookManager::call('telegram.message.error', [$msg, $e]);
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }

    private function processBotName(object $msg): void
    {
        $commandParts = explode('@', $msg->command);

        if (count($commandParts) === 2) {
            $botName = $this->getBotName();
            if ($commandParts[1] === $botName) {
                $msg->command = $commandParts[0];
            }
        }
    }

    private function getBotName(): string
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

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

        $this->msg = (object) [
            'command' => $text[0],
            'args' => array_slice($text, 1),
            'chat_id' => $message['chat']['id'],
            'message_id' => $message['message_id'],
            'message_type' => 'message',
            'text' => $messageText,
            'is_private' => $message['chat']['type'] === 'private',
        ];

        // 如果消息包含图片，存储最大尺寸图片的 file_id
        if ($hasPhoto) {
            $photos = $message['photo'];
            $this->msg->photo_file_id = end($photos)['file_id'];
        }

        if (isset($message['reply_to_message']['text'])) {
            $this->msg->message_type = 'reply_message';
            $this->msg->reply_text = $message['reply_to_message']['text'];
        } elseif (isset($message['reply_to_message']['caption'])) {
            // 图片消息的文字在 caption 中（sendPhoto 发送的工单通知）
            $this->msg->message_type = 'reply_message';
            $this->msg->reply_text = $message['reply_to_message']['caption'];
        }
    }

    private function formatChatJoinRequest(array $data): void
    {
        $joinRequest = $data['chat_join_request'] ?? null;
        if (!$joinRequest)
            return;

        $chatId = $joinRequest['chat']['id'] ?? null;
        $userId = $joinRequest['from']['id'] ?? null;

        if (!$chatId || !$userId)
            return;

        $user = User::where('telegram_id', $userId)->first();

        if (!$user) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        $this->telegramService->approveChatJoinRequest($chatId, $userId);
    }
}
