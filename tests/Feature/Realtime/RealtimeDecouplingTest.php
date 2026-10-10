<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\RealtimeDomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Laravel never talks to a socket server: Redis Streams are the only integration point. */
class RealtimeDecouplingTest extends TestCase
{
    /**
     * @return array<string, string> relative path => contents
     */
    private function sources(string $dir): array
    {
        return collect(File::allFiles(base_path($dir)))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->mapWithKeys(fn ($file) => [$dir.'/'.$file->getRelativePathname() => $file->getContents()])
            ->all();
    }

    public function test_application_code_has_no_socket_or_broadcast_coupling(): void
    {
        $forbidden = ['ShouldBroadcast', 'Illuminate\\Support\\Facades\\Broadcast', 'Broadcast::', 'broadcast(', 'Pusher', 'Laravel\\Reverb', 'Ably'];
        $socketLibraries = ['socket.io', 'Socket.io', 'Socket.IO', 'SocketIO'];

        foreach ($this->sources('app') + $this->sources('routes') as $path => $contents) {
            foreach ([...$forbidden, ...$socketLibraries] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} must not use {$needle}: real-time delivery goes through Redis Streams only.");
            }
        }

        // Config may *mention* the Node service in comments, but must not configure a broadcaster.
        foreach ($this->sources('config') as $path => $contents) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} must not configure {$needle}.");
            }
        }
    }

    public function test_the_broadcast_connection_is_disabled_and_no_channels_file_exists(): void
    {
        $this->assertNull(config('broadcasting.default'), 'No broadcaster is configured (env null).');
        $this->assertFileDoesNotExist(base_path('routes/channels.php'));
    }

    public function test_only_the_stream_client_issues_redis_stream_commands(): void
    {
        foreach ($this->sources('app') as $path => $contents) {
            if ($path === 'app/Realtime/StreamClient.php') {
                $this->assertStringContainsString("'XADD'", $contents);

                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/[\'"](XADD|XREADGROUP|XACK|XGROUP)[\'"]|->(xAdd|xadd|xReadGroup)\(/i', $contents, "{$path} must publish through the RealtimePublisher.");
        }
    }

    public function test_every_realtime_event_class_defers_to_after_the_database_commit(): void
    {
        $classes = collect(File::files(app_path('Events/Realtime')))->map(fn ($f) => 'App\\Events\\Realtime\\'.$f->getFilenameWithoutExtension());

        $this->assertCount(13, $classes, '12 event types + the base class');
        foreach ($classes as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldDispatchAfterCommit::class) || $class === RealtimeDomainEvent::class, "{$class} must be dispatched after commit.");
            $this->assertFalse(is_subclass_of($class, ShouldQueue::class), "{$class} must not be queued: the event id/time are fixed at dispatch.");
        }
    }
}
