<?php

use function Hibla\delay;

require 'vendor/autoload.php';

$start = microtime(true);

echo "Starting delay 1...\n";
delay(1)->await();
echo "Delay 1 done at: " . (microtime(true) - $start) . "s\n";

echo "Starting delay 2...\n";
delay(1)->await();
echo "Delay 2 done at: " . (microtime(true) - $start) . "s\n";

echo "Starting delay 3...\n";
delay(1)->await();
echo "Delay 3 done at: " . (microtime(true) - $start) . "s\n";

$end = microtime(true);
echo "Total elapsed time: " . ($end - $start) . " seconds\n";