<?php

require 'vendor/autoload.php';

$logger = new \Lotto\Core\Logger();

$logger->info('test 1');
$logger->warning('test 2');
$logger->error('test 3');

print_r($logger->getLastLines());
