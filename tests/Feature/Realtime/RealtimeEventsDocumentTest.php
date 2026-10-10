<?php

namespace Tests\Feature\Realtime;

use App\Realtime\RealtimeEventType;
use Tests\TestCase;

/** docs/realtime-events.md is the contract for the Node service; it must match the code. */
class RealtimeEventsDocumentTest extends TestCase
{
    private const DEFAULT_PREFIX = 'taxikosmos:events:';

    private string $doc;

    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path('docs/realtime-events.md');
        $this->assertFileExists($path);
        $this->doc = file_get_contents($path);
    }

    /**
     * @return array<string, array{stream: string, version: int, keys: list<string>}>
     */
    private function catalogue(): array
    {
        $rows = [];
        foreach (explode("\n", $this->doc) as $line) {
            if (! preg_match('/^\| `([a-z_]+\.[a-z_]+)` \| `([^`]+)` \| (\d+) \| (.+?) \| .+? \| .+? \|$/', $line, $m)) {
                continue;
            }
            preg_match_all('/`([a-z_]+)`/', $m[4], $keys);
            $rows[$m[1]] = ['stream' => $m[2], 'version' => (int) $m[3], 'keys' => $keys[1]];
        }

        return $rows;
    }

    public function test_the_default_stream_prefix_documented_is_the_one_configured(): void
    {
        $this->assertStringContainsString("'".self::DEFAULT_PREFIX."'", file_get_contents(base_path('config/realtime.php')));
        $this->assertStringContainsString('`'.self::DEFAULT_PREFIX.'`', $this->doc);
    }

    public function test_the_catalogue_lists_every_event_type_with_its_stream_key_version_and_payload_keys(): void
    {
        $catalogue = $this->catalogue();

        $this->assertEqualsCanonicalizing(array_map(fn (RealtimeEventType $t) => $t->value, RealtimeEventType::cases()), array_keys($catalogue), 'The catalogue and RealtimeEventType list different events.');

        foreach (RealtimeEventType::cases() as $type) {
            $row = $catalogue[$type->value];
            $this->assertSame(self::DEFAULT_PREFIX.$type->value, $row['stream'], "{$type->value}: stream key");
            $this->assertSame($type->version(), $row['version'], "{$type->value}: version");
            $this->assertSame($type->payloadKeys(), $row['keys'], "{$type->value}: payload keys (and order)");
        }
    }

    public function test_every_event_has_a_section_whose_example_matches_the_schema(): void
    {
        foreach (RealtimeEventType::cases() as $type) {
            $this->assertSame(1, preg_match('/### `'.preg_quote($type->value, '/').'`\n(.*?)(?=\n### `|\n## |\z)/s', $this->doc, $section), "No section for {$type->value}");
            $body = $section[1];

            $this->assertStringContainsString('**Stream:** `'.self::DEFAULT_PREFIX.$type->value.'`', $body, "{$type->value}: stream in section");

            preg_match_all('/^\| `([a-z_]+)` \|/m', $body, $fieldRows);
            $this->assertSame($type->payloadKeys(), $fieldRows[1], "{$type->value}: field table");

            $this->assertSame(1, preg_match('/```json\n(.*?)\n```/s', $body, $json), "{$type->value}: example");
            $example = json_decode($json[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['event_id', 'type', 'version', 'occurred_at', 'payload'], array_keys($example), "{$type->value}: envelope keys");
            $this->assertSame($type->value, $example['type']);
            $this->assertSame($type->version(), $example['version']);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $example['event_id']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $example['occurred_at']);
            $this->assertSame($type->payloadKeys(), array_keys($example['payload']), "{$type->value}: example payload keys");
        }
    }

    public function test_the_examples_are_exactly_what_the_code_produces(): void
    {
        config(['realtime.stream_prefix' => self::DEFAULT_PREFIX]);

        foreach (EventFactory::all() as $name => [$type, $event]) {
            preg_match('/### `'.preg_quote($name, '/').'`\n.*?```json\n(.*?)\n```/s', $this->doc, $m);
            $example = json_decode($m[1], true);

            $this->assertEquals($event->payload(), $example['payload'], "{$name}: the documented example drifted from the event class");
        }
    }

    public function test_the_doc_states_the_delivery_rules_the_consumer_relies_on(): void
    {
        foreach (['XADD', 'XREADGROUP', 'XACK', 'MAXLEN ~ 100000', 'event_id', 'after the surrounding database transaction commits', 'Redis is the only integration point', 'disconnect every socket of this user'] as $phrase) {
            $this->assertStringContainsString($phrase, $this->doc);
        }
    }
}
