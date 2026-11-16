<?php

$ch = curl_init('https://ticket.rzd.ru');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_VERBOSE => true,
    CURLOPT_TIMEOUT => 20,
]);

$result = curl_exec($ch);

echo "\n\n===== RESULT =====\n";
var_dump($result);

echo "\n===== ERROR =====\n";
echo curl_error($ch) . "\n";

echo "\n===== INFO =====\n";
print_r(curl_getinfo($ch));

curl_close($ch);