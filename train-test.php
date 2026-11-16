<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

require __DIR__ . '/vendor/autoload.php';
use Rzd\Client;
use Rzd\Config;
use Rzd\Request\TrainSearch;

echo "========================================\n";
echo "        RZD МОНИТОР БИЛЕТОВ\n";
echo "========================================\n\n";

try {

    // =====================================
    // ВВОД
    // =====================================

    $fromName = trim(readline("Откуда: "));
    $toName   = trim(readline("Куда: "));

    $datesInput = trim(
        readline(
            "Даты через запятую (например 25.08.2026, 26.08.2026): "
        )
    );

    $wagonInput = trim(
        readline(
            "Тип вагона [любой / плацкарт / купе / св]: "
        )
    );

    if ($wagonInput === '') {
        $wagonInput = 'любой';
    }

    $intervalInput = trim(
        readline("Интервал проверки в секундах [5]: ")
    );

    $interval = $intervalInput === ''
        ? 5
        : (int)$intervalInput;

    if ($fromName === '' || $toName === '' || $datesInput === '') {
        throw new Exception("Все обязательные поля должны быть заполнены.");
    }

    if ($interval < 1) {
        throw new Exception("Интервал должен быть не меньше 1 секунды.");
    }


    // =====================================
    // НОРМАЛИЗУЕМ ТИП ВАГОНА
    // =====================================

    $wagonInput = mb_strtolower($wagonInput);

    $wagonType = match ($wagonInput) {

        'плацкарт',
        'плац',
        'п' => 'плацкарт',

        'купе',
        'к' => 'купе',

        'св',
        'люкс' => 'св',

        'любой',
        'все',
        'любой вагон',
        '' => 'любой',

        default => throw new Exception(
            "Неизвестный тип вагона: {$wagonInput}\n" .
            "Используй: любой, плацкарт, купе или св."
        ),
    };


    // =====================================
    // РАЗБИРАЕМ ДАТЫ
    // =====================================

    $dateStrings = array_map(
        'trim',
        explode(',', $datesInput)
    );

    $dates = [];

    foreach ($dateStrings as $dateInput) {

        if ($dateInput === '') {
            continue;
        }

        $date = DateTimeImmutable::createFromFormat(
            'd.m.Y',
            $dateInput
        );

        if (
            !$date ||
            $date->format('d.m.Y') !== $dateInput
        ) {
            throw new Exception(
                "Неверный формат даты: {$dateInput}\n" .
                "Используй формат ДД.ММ.ГГГГ."
            );
        }

        $dates[$date->format('Y-m-d')] = $date;
    }


    if (count($dates) === 0) {
        throw new Exception("Не указано ни одной даты.");
    }

    $dates = array_values($dates);


    // =====================================
    // СОЗДАЁМ КЛИЕНТ
    // =====================================

    echo "\n========================================\n";
    echo "Создаём клиент РЖД...\n";

    $client = new Client(
        config: new Config()
    );

    echo "OK\n";


    // =====================================
    // ПОЛУЧАЕМ СТАНЦИИ
    // =====================================

    echo "\nПолучаем станции...\n";

    $from = $client->stations->find($fromName);
    $to   = $client->stations->find($toName);

    echo "Откуда: {$from->name} ({$from->code})\n";
    echo "Куда:   {$to->name} ({$to->code})\n";


    // =====================================
    // ВЫВОД НАСТРОЕК
    // =====================================

    echo "\n========================================\n";
    echo "НАСТРОЙКИ МОНИТОРИНГА\n";
    echo "========================================\n";

    echo "Маршрут: {$from->name} -> {$to->name}\n";

    echo "Даты:\n";

    foreach ($dates as $date) {
        echo "  - " . $date->format('d.m.Y') . "\n";
    }

    echo "Тип вагона: {$wagonType}\n";
    echo "Интервал: {$interval} сек.\n";

    echo "\n========================================\n";
    echo "МОНИТОРИНГ ЗАПУЩЕН\n";
    echo "Для остановки нажми Ctrl+C\n";
    echo "========================================\n";


    // =====================================
    // ХРАНИМ УЖЕ ОБНАРУЖЕННЫЕ БИЛЕТЫ
    // =====================================

    $knownTickets = [];


    // =====================================
    // БЕСКОНЕЧНЫЙ МОНИТОРИНГ
    // =====================================

    while (true) {

        $cycleStartedAt = microtime(true);

        echo "\n";
        echo "----------------------------------------\n";
        echo "ПРОВЕРКА: "
            . date('d.m.Y H:i:s')
            . "\n";
        echo "----------------------------------------\n";


        foreach ($dates as $date) {

            $dateString = $date->format('Y-m-d');

            echo "\n";
            echo "[{$date->format('d.m.Y')}] Запрашиваем РЖД...\n";


            try {

                // =================================
                // ПОИСК РЕАЛЬНЫХ БИЛЕТОВ
                // =================================

                $request = new TrainSearch(
                    origin: $from->code,
                    destination: $to->code,
                    date: $date,
                    adults: 1,
                    children: 0,

                    // ВАЖНО:
                    // false = реальные результаты продажи
                    fromSchedule: false,

                    largeFamily: false
                );


                $result = $client->trains->search($request);


                // =================================
                // ИЩЕМ ПОДХОДЯЩИЕ МЕСТА
                // =================================

                $availableTickets = [];


                foreach ($result->trains as $train) {

                    foreach ($train->carGroups as $carGroup) {

                        // Продажа запрещена
                        if ($carGroup->saleForbidden) {
                            continue;
                        }

                        // Мест нет
                        if ($carGroup->places <= 0) {
                            continue;
                        }


                        // =================================
                        // ФИЛЬТР ТИПА ВАГОНА
                        // =================================

                        $carType = mb_strtolower(
                            trim($carGroup->typeName)
                        );


                        if ($wagonType !== 'любой') {

                            $matches = false;


                            if ($wagonType === 'плацкарт') {

                                $matches =
                                    str_contains($carType, 'плац')
                                    ||
                                    str_contains($carType, 'сид')
                                    ||
                                    str_contains($carType, 'плацкарт');
                            }


                            if ($wagonType === 'купе') {

                                $matches =
                                    str_contains($carType, 'купе');
                            }


                            if ($wagonType === 'св') {

                                $matches =
                                    str_contains($carType, 'св')
                                    ||
                                    str_contains($carType, 'люкс');
                            }


                            if (!$matches) {
                                continue;
                            }
                        }


                        $availableTickets[] = [
                            'train' => $train,
                            'car'   => $carGroup,
                        ];
                    }
                }


                // =================================
                // РЕЗУЛЬТАТ ПРОВЕРКИ
                // =================================

                if (count($availableTickets) === 0) {

                    echo "[{$date->format('d.m.Y')}] "
                        . "Билетов нет.\n";

                    continue;
                }


                echo "\n";
                echo "🚨 ==================================== 🚨\n";
                echo "🚨       БИЛЕТ НАЙДЕН!\n";
                echo "🚨 ==================================== 🚨\n";


                // =================================
                // ПОКАЗЫВАЕМ НАЙДЕННЫЕ БИЛЕТЫ
                // =================================

                foreach ($availableTickets as $ticket) {

                    $train = $ticket['train'];
                    $car   = $ticket['car'];


                    // =================================
                    // СОЗДАЁМ УНИКАЛЬНЫЙ ID БИЛЕТА
                    // =================================

                    $classes = implode(
                        ',',
                        $car->serviceClasses
                    );


                    $ticketId =
                        $dateString
                        . '|'
                        . $train->number
                        . '|'
                        . $train->departure->format('H:i')
                        . '|'
                        . $car->typeName
                        . '|'
                        . $classes;


                    // =================================
                    // ЕСЛИ ЭТО НОВЫЙ БИЛЕТ
                    // =================================

                    if (!isset($knownTickets[$ticketId])) {

                        $knownTickets[$ticketId] = true;


                        echo "\n";
                        echo "🟢 НОВЫЙ БИЛЕТ!\n";

                    } else {

                        echo "\n";
                        echo "Билет всё ещё доступен:\n";
                    }


                    echo "\n";

                    echo "Дата: "
                        . $date->format('d.m.Y')
                        . "\n";


                    echo "Поезд: "
                        . $train->number;


                    if (!empty($train->name)) {

                        echo " — "
                            . $train->name;
                    }


                    echo "\n";


                    echo "Время: "
                        . $train->departure->format('H:i')
                        . " → "
                        . $train->arrival->format('H:i')
                        . "\n";


                    echo "Вагон: "
                        . $car->typeName
                        . "\n";


                    echo "Класс: "
                        . $classes
                        . "\n";


                    echo "Мест: "
                        . $car->places
                        . "\n";


                    echo "Цена: "
                        . number_format(
                            $car->minPrice,
                            2,
                            '.',
                            ' '
                        )
                        . " - "
                        . number_format(
                            $car->maxPrice,
                            2,
                            '.',
                            ' '
                        )
                        . " ₽\n";


                    echo "----------------------------------------\n";
                }


            } catch (\Throwable $e) {

                // =================================
                // ОШИБКА НЕ ОСТАНАВЛИВАЕТ ПРОГРАММУ
                // =================================

                echo "\n";
                echo "⚠️ ОШИБКА ПРИ ПРОВЕРКЕ "
                    . $date->format('d.m.Y')
                    . "\n";

                echo $e->getMessage() . "\n";

                echo "Продолжаем мониторинг...\n";
            }
        }


        // =====================================
        // ВРЕМЯ ЦИКЛА
        // =====================================

        $cycleTime = microtime(true) - $cycleStartedAt;

        echo "\n";
        echo "Цикл завершён за "
            . number_format($cycleTime, 2)
            . " сек.\n";


        // =====================================
        // ЖДЁМ ДО СЛЕДУЮЩЕЙ ПРОВЕРКИ
        // =====================================

        echo "Следующая проверка через "
            . $interval
            . " сек...\n";


        sleep($interval);
    }


} catch (\Throwable $e) {

    echo "\n";
    echo "========================================\n";
    echo "               ОШИБКА\n";
    echo "========================================\n\n";

    echo "Сообщение: "
        . $e->getMessage()
        . "\n";
}