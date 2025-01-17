<?php

namespace App\Http\Controllers;

use App\Http\Conversations\HelpConversation;
use App\Http\Conversations\LinkMasternodeConversation;
use App\Http\Conversations\MasternodeStatsConversation;
use App\Http\Conversations\RewardsByMnConversation;
use App\Http\Conversations\RewardsConversation;
use App\Http\Conversations\ServerHealthConversation;
use App\Http\Conversations\ServerHealthResetConversation;
use App\Http\Conversations\SetupServerHealthConversation;
use App\Http\Conversations\SyncDisableConversation;
use App\Http\Conversations\SyncKeyChangedConversation;
use App\Http\Conversations\UnlinkMasternodeConversation;
use App\Http\Conversations\ListMasternodesConversation;
use App\Http\Conversations\OnboardConversation;
use App\Http\Conversations\ResetMasternodesConversation;
use App\Http\Conversations\SyncMasternodeMonitorConversation;
use App\Http\Middleware\TelegramBot\CommandReceived;
use App\Http\Middleware\TelegramBot\SentMessages;
use App\Http\Middleware\TelegramBot\SetLanguageReceived;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Exceptions\Base\BotManException;
use App\Models\Service\TelegramUserService;
use Throwable;

class BotController extends Controller
{
    protected BotMan $botMan;

    public function handle(TelegramUserService $telegramUserService): void
    {
        /** @var BotMan $botMan */
        $botMan = app('botman');

        $botMan->hears('/start', function (Botman $botman) use ($telegramUserService) {
            if ($telegramUserService->isExistingUser($botman->getUser())) {
                $botman->startConversation(new HelpConversation());
            } else {
                $telegramUserService->getTelegramUser($botman->getUser());
                $botman->startConversation(new OnboardConversation());
            }
        })->skipsConversation();
        $botMan->hears('/sync', function (BotMan $botman) {
            $botman->startConversation(new SyncMasternodeMonitorConversation());
        })->skipsConversation();
        $botMan->hears('/sync_key_changed', function (BotMan $botman) {
            $botman->startConversation(new SyncKeyChangedConversation());
        });
        $botMan->hears('/sync_disable', function (BotMan $botman) {
            $botman->startConversation(new SyncDisableConversation());
        });

        $botMan->hears('/link_mn(.*|^)', function (BotMan $botman, string $ownerAddress) {
            $botman->startConversation(new LinkMasternodeConversation($botman->getUser(), trim($ownerAddress)));
        })->skipsConversation();
        $botMan->hears('/unlink_mn(.*|^)', function (BotMan $botman, string $ownerAddress) {
            $botman->startConversation(new UnlinkMasternodeConversation($botman->getUser(), trim($ownerAddress)));
        })->skipsConversation();

        $botMan->hears('/list', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            $masternodes = $telegramUser->masternodes;
            $botman->startConversation(new ListMasternodesConversation($masternodes));
        })->skipsConversation();

        $botMan->hears('/stats', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            $masternodes = $telegramUser->masternodes;
            $botman->startConversation(new MasternodeStatsConversation($masternodes));
        })->skipsConversation();
        $botMan->hears('/rewards', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            $masternodes = $telegramUser->masternodes;
            $botman->startConversation(new RewardsConversation($masternodes, true));
        })->skipsConversation();
        $botMan->hears('/rewardsall', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            $masternodes = $telegramUser->masternodes;
            $botman->startConversation(new RewardsConversation($masternodes, false));
        })->skipsConversation();
        $botMan->hears('/rewardsbynode', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            $masternodes = $telegramUser->masternodes;
            $botman->startConversation(new RewardsByMnConversation($masternodes));
        })->skipsConversation();

        $botMan->hears('/masternode_health', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            if (is_null($telegramUser->server_health_api_key)) {
                $botman->startConversation(new SetupServerHealthConversation($telegramUser));
                return;
            }
            $botman->startConversation(new ServerHealthConversation($telegramUser));
        })->skipsConversation();

        $botMan->hears('/masternode_health_reset', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            $botman->startConversation(new ServerHealthResetConversation($telegramUser));
        })->skipsConversation();

        $botMan->hears('/reset', function (BotMan $botman) use ($telegramUserService) {
            $telegramUser = $telegramUserService->getTelegramUser($botman->getUser());
            $botman->startConversation(new ResetMasternodesConversation($telegramUser));
        })->skipsConversation();

        $botMan->hears('/stop', function (BotMan $botman) {
            $botman->reply('⏹ DFI Signal command canceled');
        })->stopsConversation();

        $botMan->fallback(function (BotMan $botman) {
            $botman->startConversation(new HelpConversation());
        });
        $botMan->exception(BotManException::class, function (Throwable $throwable, $bot) {
            $bot->reply('An error occured. Try again later...');
        });
        $botMan->middleware->received(new SetLanguageReceived());
        $botMan->middleware->received(new CommandReceived());
        $botMan->middleware->sending(new SentMessages());
        $botMan->listen();
    }
}
