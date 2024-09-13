<?php

return [
    'ttl' => env('CACHEABLE_TTL', 300), //缓存时长
    'prefix' => env('CACHEABLE_PREFIX', 'cacheable'), //前缀
    'enabled' => env('CACHEABLE_ENABLED', true), //boolean 是否开启
];