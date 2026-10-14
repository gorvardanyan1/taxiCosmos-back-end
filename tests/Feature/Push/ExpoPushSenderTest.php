<?php

namespace Tests\Feature\Push;

use App\Data\PushMessage;
use App\Exceptions\PushDeliveryException;
use App\Services\Push\ExpoPushSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P8-T4: the Expo Push Service with the HTTP client faked: what is sent, how tickets and receipts are read, how failures look.
 */
class ExpoPushSenderTest extends TestCase
{
    private const TOKEN = 'ExponentPushToken[secret-token-0001]';

    private function message(string $token = self::TOKEN, bool $urgent = false): PushMessage
    {
        return new PushMessage($token, 'Title', 'Body', ['type' => 'trip_offer', 'trip_id' => 7], $urgent, $urgent ? 'trip_offers' : 'default', $urgent, $urgent ? 30 : 86400);
    }

    public function test_it_posts_the_message_to_expo_with_the_delivery_hints_of_an_offer(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'tk-1']]])]);

        $results = (new ExpoPushSender)->send([$this->message(urgent: true)]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && $request->method() === 'POST'
                && $request->data() === [[
                    'to' => self::TOKEN, 'title' => 'Title', 'body' => 'Body', 'data' => ['type' => 'trip_offer', 'trip_id' => 7],
                    'sound' => 'default', 'priority' => 'high', 'channelId' => 'trip_offers', 'ttl' => 30, 'interruptionLevel' => 'time-sensitive',
                ]]
                && ! $request->hasHeader('Authorization');
        });
        $this->assertTrue($results[0]->ok);
        $this->assertSame('tk-1', $results[0]->ticketId);
    }

    public function test_an_ordinary_push_is_default_priority_on_the_default_channel_and_not_time_sensitive(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'tk-1']]])]);

        (new ExpoPushSender)->send([$this->message()]);

        Http::assertSent(fn (Request $request) => $request->data()[0]['priority'] === 'default' && $request->data()[0]['channelId'] === 'default' && $request->data()[0]['ttl'] === 86400 && ! array_key_exists('interruptionLevel', $request->data()[0]));
    }

    public function test_the_access_token_is_sent_as_a_bearer_token_only_when_configured(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'tk-1']]])]);

        (new ExpoPushSender('expo-access-token'))->send([$this->message()]);

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer expo-access-token']);
    }

    public function test_the_configured_timeout_is_sent_with_every_request(): void
    {
        config(['services.push.timeout' => 4]);
        $options = null;
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['data' => [['status' => 'ok', 'id' => 'tk-1']]]);
        });

        (new ExpoPushSender)->send([$this->message()]);

        $this->assertEquals(4, $options['timeout']);
    }

    public function test_messages_go_to_expo_in_requests_of_at_most_one_hundred(): void
    {
        Http::fake(fn (Request $request) => Http::response(['data' => array_map(fn ($m) => ['status' => 'ok', 'id' => 'id-'.substr($m['to'], -4, 3)], $request->data())]));
        $messages = array_map(fn (int $i) => $this->message("ExponentPushToken[token-{$i}-abcdefg]"), range(1, 250));

        $results = (new ExpoPushSender)->send($messages);

        Http::assertSentCount(3);
        $sizes = [];
        foreach (Http::recorded() as [$request]) {
            $sizes[] = count($request->data());
        }
        $this->assertSame([100, 100, 50], $sizes);
        $this->assertCount(250, $results, 'one result per message, in order');
    }

    public function test_each_ticket_is_matched_to_its_message_by_position(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'tk-a'],
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'error', 'message' => 'too big', 'details' => ['error' => 'MessageTooBig']],
        ]])]);

        [$ok, $invalid, $refused] = (new ExpoPushSender)->send([$this->message(), $this->message('ExponentPushToken[bbbbbbbbbb]'), $this->message('ExponentPushToken[cccccccccc]')]);

        $this->assertSame([true, false, 'tk-a'], [$ok->ok, $ok->invalidToken, $ok->ticketId]);
        $this->assertSame([false, true, 'DeviceNotRegistered'], [$invalid->ok, $invalid->invalidToken, $invalid->error]);
        $this->assertSame([false, false, 'MessageTooBig'], [$refused->ok, $refused->invalidToken, $refused->error]);
    }

    /**
     * @return array<string, array{callable(): mixed}>
     */
    public static function failures(): array
    {
        return [
            'Expo is down (500)' => [fn () => Http::response('upstream error '.self::TOKEN, 500)],
            'rate limited (429)' => [fn () => Http::response(['errors' => [['code' => 'TOO_MANY_REQUESTS']]], 429)],
            'a refused request (400)' => [fn () => Http::response(['errors' => [['message' => 'bad '.self::TOKEN]]], 400)],
            'an unreadable answer' => [fn () => Http::response('<html>nope</html>', 200)],
            'fewer tickets than messages' => [fn () => Http::response(['data' => []], 200)],
        ];
    }

    /**
     * @param  callable(): mixed  $response
     */
    #[DataProvider('failures')]
    public function test_a_failed_request_is_a_delivery_exception_that_does_not_carry_the_token(callable $response): void
    {
        Http::fake(['exp.host/*' => $response()]);

        try {
            (new ExpoPushSender)->send([$this->message()]);
            $this->fail('the push service failure must surface so the job retries');
        } catch (PushDeliveryException $e) {
            $this->assertStringNotContainsString('ExponentPushToken', $e->getMessage());
        }
    }

    public function test_a_connection_error_is_a_delivery_exception_without_the_original_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 while posting '.self::TOKEN));

        try {
            (new ExpoPushSender)->send([$this->message()]);
            $this->fail('a timeout must surface');
        } catch (PushDeliveryException $e) {
            $this->assertStringNotContainsString('ExponentPushToken', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    // ---- receipts ----

    public function test_receipts_are_asked_by_ticket_id_and_read_by_status(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            'tk-1' => ['status' => 'ok'],
            'tk-2' => ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
            'tk-3' => ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'MessageRateExceeded']],
        ]])]);

        $receipts = (new ExpoPushSender('t'))->receipts(['tk-1', 'tk-2', 'tk-3', 'tk-unknown']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://exp.host/--/api/v2/push/getReceipts' && $request->data() === ['ids' => ['tk-1', 'tk-2', 'tk-3', 'tk-unknown']]);
        $this->assertSame(['tk-1', 'tk-2', 'tk-3'], array_keys($receipts), 'a ticket Expo does not know yet is left out');
        $this->assertTrue($receipts['tk-1']->ok);
        $this->assertTrue($receipts['tk-2']->invalidToken);
        $this->assertSame([false, false, 'MessageRateExceeded'], [$receipts['tk-3']->ok, $receipts['tk-3']->invalidToken, $receipts['tk-3']->error]);
    }

    public function test_receipts_are_read_in_requests_of_at_most_a_thousand_ids(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => []])]);

        (new ExpoPushSender)->receipts(array_map(fn (int $i) => "tk-{$i}", range(1, 2500)));

        $sizes = [];
        foreach (Http::recorded() as [$request]) {
            $sizes[] = count($request->data()['ids']);
        }
        $this->assertSame([1000, 1000, 500], $sizes);
    }

    public function test_a_receipts_failure_is_a_delivery_exception(): void
    {
        Http::fake(['exp.host/*' => Http::response('down', 503)]);

        $this->expectException(PushDeliveryException::class);

        (new ExpoPushSender)->receipts(['tk-1']);
    }
}
