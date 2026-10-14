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

    // Live driver positions (P8-T2), on the same Redis connection. Both keys are shared with the Node service, which GEOADDs to
    // them directly, so they are used verbatim (no Laravel REDIS_PREFIX). Members are driver USER ids; the geo set holds the
    // position, the sorted set the unix time of the last ping (seconds).
    'geo' => [
        'locations_key' => env('REALTIME_GEO_KEY', 'drivers:locations'),
        'last_seen_key' => env('REALTIME_LAST_SEEN_KEY', 'drivers:last_seen'),
    ],

];
