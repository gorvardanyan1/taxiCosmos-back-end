<?php

/*
 * Real-time event contract (P8-T1). Laravel appends state-change events to Redis Streams; the
 * separate Node/Socket.io service reads them through a consumer group and pushes them to clients.
 * Redis is the only integration point. See docs/realtime-events.md.
 */
return [

    // Redis connection (config/database.php) the streams live on.
    'connection' => env('REALTIME_REDIS_CONNECTION', 'default'),

    // Exact stream key = prefix + event type, e.g. "taxikosmos:events:trip.status_changed".
    // Streams are written with raw commands, so Laravel's global REDIS_PREFIX is NOT added;
    // the Node service must use these keys verbatim.
    'stream_prefix' => env('REALTIME_STREAM_PREFIX', 'taxikosmos:events:'),

    // Approximate trim (XADD MAXLEN ~ n) per stream, so Redis memory stays bounded. A consumer
    // that is down for longer than it takes to produce this many events misses the oldest ones.
    'max_length' => (int) env('REALTIME_STREAM_MAXLEN', 100_000),

];
