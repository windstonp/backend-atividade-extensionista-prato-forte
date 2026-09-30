<?php

use App\Models\User;
use App\Notifications\MealReminder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;

it('o serviço de push responde 410: a inscrição é apagada (CA08)', function () {
    config(['queue.default' => 'sync']);
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/velha', 'BPublica', 'segredo', 'aes128gcm');
    $push = Mockery::mock(WebPush::class);
    $push->shouldReceive('queueNotification')->once();
    $push->shouldReceive('flush')->once()->andReturnUsing(function () {
        yield new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/fcm/send/velha'), new Response(410), false, 'Gone');
    });
    // O provider do pacote injeta um WebPush real no canal (binding contextual): o canal vem montado aqui.
    app()->instance(WebPushChannel::class, new WebPushChannel($push, app(ReportHandler::class)));

    $user->notify(new MealReminder('almoco', 'Almoço', '12:30', 'Arroz, feijão e frango'));

    expect($user->pushSubscriptions()->count())->toBe(0);
});
