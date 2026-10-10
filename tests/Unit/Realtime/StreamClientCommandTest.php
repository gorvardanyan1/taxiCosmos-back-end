<?php

namespace Tests\Unit\Realtime;

use App\Data\RealtimeEnvelope;
use App\Realtime\RealtimePublisher;
use App\Realtime\StreamClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Realtime\EventFactory;
use Tests\TestCase;

/** The exact Redis command Laravel sends: XADD <key> MAXLEN ~ <n> * field value … */
class StreamClientCommandTest extends TestCase
{
    public function test_xadd_uses_approximate_maxlen_with_the_configured_limit_and_auto_generated_ids(): void
    {
        config(['realtime.max_length' => 5000]);
        [$type, $event] = EventFactory::all()['trip.status_changed'];
        $fields = RealtimeEnvelope::from($event)->toStreamFields();
        $sent = null;

        $client = $this->partialMock(StreamClient::class, function (MockInterface $mock) use (&$sent) {
            $mock->shouldReceive('raw')->once()->andReturnUsing(function (array $arguments) use (&$sent) {
                $sent = $arguments;

                return '1791626222366-0';
            });
        });

        $id = (new RealtimePublisher($client))->publish($event);

        $this->assertSame('1791626222366-0', $id);
        $expected = ['XADD', $type->streamKey(), 'MAXLEN', '~', '5000', '*'];
        foreach ($fields as $field => $value) {
            array_push($expected, $field, $value);
        }
        $this->assertSame($expected, $sent);
    }

    public function test_the_default_limit_sent_is_one_hundred_thousand(): void
    {
        [, $event] = EventFactory::all()['user.suspended'];
        $sent = null;
        $client = $this->partialMock(StreamClient::class, function (MockInterface $mock) use (&$sent) {
            $mock->shouldReceive('raw')->once()->andReturnUsing(function (array $arguments) use (&$sent) {
                $sent = $arguments;

                return '1-0';
            });
        });
        config(['realtime.max_length' => (int) (include base_path('config/realtime.php'))['max_length']]);

        (new RealtimePublisher($client))->publish($event);

        $this->assertSame(['MAXLEN', '~', '100000'], array_slice($sent, 2, 3));
    }

    public function test_a_reply_that_is_not_an_entry_id_is_an_error_not_a_silent_success(): void
    {
        [, $event] = EventFactory::all()['user.suspended'];
        $client = $this->partialMock(StreamClient::class, fn (MockInterface $mock) => $mock->shouldReceive('raw')->once()->andReturn(false));

        $this->expectException(RuntimeException::class);

        (new RealtimePublisher($client))->publish($event);
    }
}
