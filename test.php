<?php

require __DIR__ . '/vendor/autoload.php';

use Rzd\Client;
use Rzd\Config;

echo "========================================\n";
echo "        RZD API TEST v5\n";
echo "========================================\n\n";

try {
    echo "[1/3] Создаём клиент...\n";

    $client = new Client(
        config: new Config()
    );

    echo "OK\n\n";

    echo "[2/3] Ищем станции...\n";

    $from = $client->stations->find('Москва');
    $to   = $client->stations->find('Санкт-Петербург');

    echo "Результат получен!\n\n";

    echo "========================================\n";
    echo " СТАНЦИИ\n";
    echo "========================================\n\n";

    echo "Откуда:\n";
    echo "  Название: {$from->name}\n";
    echo "  Код:      {$from->code}\n";
    echo "  Часовой пояс: {$from->timezone}\n\n";

    echo "Куда:\n";
    echo "  Название: {$to->name}\n";
    echo "  Код:      {$to->code}\n";
    echo "  Часовой пояс: {$to->timezone}\n\n";

    echo "[3/3] Всё работает!\n";

    echo "\n========================================\n";
    echo " RZD API УСПЕШНО РАБОТАЕТ\n";
    echo "========================================\n";

} catch (Throwable $e) {

    echo "\n========================================\n";
    echo "ОШИБКА\n";
    echo "========================================\n\n";

    echo "Класс: " . get_class($e) . "\n";
    echo "Сообщение: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n\n";

    echo "Полный trace:\n";
    echo $e->getTraceAsString();
}