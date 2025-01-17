<?php

namespace App\Http\Service;

use App\Models\TelegramUser;
use Arr;
use Carbon\Carbon;
use Str;

class MasternodeHealthWebhookService
{
    const TYPE_WARNINGS = 'warnings';
    const TYPE_CRITICAL = 'critical';
    protected TelegramUser $telegramUser;
    protected array $data;
    protected array $analysis;
    protected Carbon $latestUpdate;
    protected bool $messageSent = false;

    public function initWithData(
        TelegramUser $telegramUser,
        array $data,
        array $analysis,
        Carbon $latestUpdate
    ): void {

        $this->telegramUser = $telegramUser;
        $this->data = $data;
        $this->analysis = $analysis;
        $this->latestUpdate = $latestUpdate;
    }

    public function run(TelegramMessageService $messageService): void
    {
        if ($this->hasWarnings()) {
            $this->sendWarnings($messageService);
        }

        if ($this->hasCriticalError()) {
            $this->sendCriticalErrors($messageService);
        }

        if ($this->messageSent) {
            $messageService->sendMessage(
                $this->telegramUser,
                __('mn_health_webhook.latest_server_update', [
                    'date'           => $this->latestUpdate->format('d.m.Y H:i:s'),
                    'human_readable' => time_diff_humanreadable(now(), $this->latestUpdate,
                        $this->telegramUser->language),
                ]),
                ['parse_mode' => 'Markdown']
            );
        }
    }

    protected function hasWarnings(): bool
    {
        return count($this->analysis['warnings'] ?? []) > 0;
    }

    protected function hasCriticalError(): bool
    {
        return count($this->analysis['critical'] ?? []) > 0;
    }

    protected function sendWarnings(TelegramMessageService $messageService): void
    {
        $message = $this->generateMessage(self::TYPE_WARNINGS, 'block_height', 2);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'connection_count', 1);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'logsize', 48);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'config_checksum', 12);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'node_version', 12);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'load_avg', 1);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'hdd', 24);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'ram', 24);
        $message .= $this->generateMessage(self::TYPE_WARNINGS, 'server_script_version', 24);

        $this->sendMessageWithCategory($messageService, self::TYPE_WARNINGS, $message);
    }

    protected function sendCriticalErrors(TelegramMessageService $messageService): void
    {
        $message = $this->generateMessage(self::TYPE_CRITICAL, 'block_height', 1);
        $message .= $this->generateMessage(self::TYPE_CRITICAL, 'block_hash', 1);
        $message .= $this->generateMessage(self::TYPE_CRITICAL, 'defid_running', 1);
        $message .= $this->generateMessage(self::TYPE_CRITICAL, 'operator_status', 1);
        $message .= $this->generateMessage(self::TYPE_CRITICAL, 'load_avg', 1);
        $message .= $this->generateMessage(self::TYPE_CRITICAL, 'hdd', 6);
        $message .= $this->generateMessage(self::TYPE_CRITICAL, 'ram', 6);

        $this->sendMessageWithCategory($messageService, self::TYPE_CRITICAL, $message);
    }

    protected function searchInArray(array $array, string $needle): array
    {
        return collect(Arr::where($array, function ($value, $key) use ($needle) {
                return $value['type'] === $needle;
            }))->first() ?? [];
    }

    protected function generateCooldownKey(string $category, string $type): string
    {
        return sprintf('%s_%s', $category, $type);
    }

    protected function generateMessage(string $category, string $type, int $cooldownHours): string
    {
        $array = $this->searchInArray($this->analysis[$category], $type);
        $cooldownKey = $this->generateCooldownKey($category, $type);
        if (
            count($array) > 0
            && $this->telegramUser->cooldown($cooldownKey)->passed()
        ) {
            $this->telegramUser->cooldown($cooldownKey)->until(now()->addHours($cooldownHours));

            return "\r\n\r\n" . __(sprintf('mn_health_webhook.%s.%s', $category, $type), [
                    'value'      => $array['value'] ?? 'n/a',
                    'expected'   => $array['expected'] ?? 'n/a',
                    'difference' => abs((int)($array['value'] ?? 0) - (int)($array['expected'] ?? 0)),
                ]) . "\r\n\r\n" . trans_choice('mn_health_webhook.cooldown_message', $cooldownHours, [
                    'value' => $cooldownHours,
                ]);
        }

        return '';
    }

    protected function sendMessageWithCategory(
        TelegramMessageService $messageService,
        string $category,
        string $message
    ): void {
        // finally send the message
        if (Str::length($message) > 0) {
            $messageService->sendMessage(
                $this->telegramUser,
                __(sprintf('mn_health_webhook.headline.%s', $category)),
                ['parse_mode' => 'Markdown']
            );
            $messageService->sendMessage(
                $this->telegramUser,
                $message,
                ['parse_mode' => 'Markdown']
            );
            $this->messageSent = true;
        }
    }
}