<?php

require __DIR__ . '/vendor/autoload.php';

$rc = new ReflectionClass(Symfony\Component\HttpFoundation\Request::class);

$consts = $rc->getConstants();
ksort($consts);

foreach ($consts as $k => $v) {
    if (str_starts_with($k, 'HEADER_')) {
        echo $k . '=' . $v . PHP_EOL;
    }
}
