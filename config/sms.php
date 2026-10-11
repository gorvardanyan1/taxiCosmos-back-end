<?php

return [

    // log = write the message to the log (local, testing, staging). Real providers are added as
    // SmsSender implementations once the provider decision is made (Open Decisions: SMS provider).
    'driver' => env('SMS_DRIVER', 'log'),

];
