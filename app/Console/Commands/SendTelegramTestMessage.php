<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramReminderChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SendTelegramTestMessage extends Command
{
    private const MESSAGE_SEPARATOR = '________________________________';

    protected $signature = 'notifications:telegram-test
                            {--message= : Mensaje de prueba opcional}';

    protected $description = 'Envía un mensaje administrativo de prueba mediante Telegram.';

    public function handle(TelegramReminderChannel $telegramChannel): int
    {
        if (! (bool) config('services.notifications.enabled')) {
            $this->error('Los recordatorios están desactivados.');

            return self::FAILURE;
        }

        if (config('services.notifications.channel') !== 'telegram') {
            $this->error('El canal activo no es Telegram.');

            return self::FAILURE;
        }

        $chatId = trim((string) config('services.notifications.telegram.chat_id'));

        if ($chatId === '') {
            $this->error('TELEGRAM_CHAT_ID no está configurado.');

            return self::FAILURE;
        }

        $message = trim((string) $this->option('message'));
        $message = implode(PHP_EOL, [
            self::MESSAGE_SEPARATOR,
            'PRUEBA DE CONEXIÓN DE TELEGRAM',
            self::MESSAGE_SEPARATOR,
            '- '.($message === '' ? 'Marleni\'s Repostería' : $message),
            self::MESSAGE_SEPARATOR,
        ]);

        try {
            $telegramChannel->send(
                recipient: $chatId,
                message: $message,
                notificationKey: 'telegram:test:'.Str::uuid()->toString(),
            );
        } catch (Throwable) {
            $this->error('No fue posible enviar el mensaje de prueba de Telegram.');

            return self::FAILURE;
        }

        $this->info('Mensaje de prueba enviado a Telegram.');

        return self::SUCCESS;
    }
}
