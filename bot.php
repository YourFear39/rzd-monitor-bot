<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Rzd\Client;
use Rzd\Config;
use Rzd\Request\TrainSearch;

date_default_timezone_set('Europe/Warsaw');

/*
|--------------------------------------------------------------------------
| .ENV
|--------------------------------------------------------------------------
*/

$envFile = __DIR__ . '/.env';

if (!file_exists($envFile)) {
    die("Файл .env не найден.\n");
}

$env = parse_ini_file($envFile);

if (!$env || empty($env['TELEGRAM_BOT_TOKEN'])) {
    die("TELEGRAM_BOT_TOKEN не найден в .env\n");
}

$BOT_TOKEN = trim($env['TELEGRAM_BOT_TOKEN']);

/*
|--------------------------------------------------------------------------
| НАСТРОЙКИ
|--------------------------------------------------------------------------
*/

$config = [
    'from' => null,
    'to' => null,
    'fromCode' => null,
    'toCode' => null,

    // Это НЕ коды станций для API поиска.
    // Это node ID, используемые в ссылке ticket.rzd.ru.
    'fromNodeId' => null,
    'toNodeId' => null,

    'dates' => [],
    'wagon' => 'любой',
    'interval' => 5,
    'monitoring' => false,
    'chatId' => null,
];

/*
|--------------------------------------------------------------------------
| КЛИЕНТ РЖД
|--------------------------------------------------------------------------
*/

try {
    $rzd = new Client(
        config: new Config()
    );
} catch (Throwable $e) {
    die(
        "Не удалось создать клиент РЖД:\n"
        . $e->getMessage()
        . "\n"
    );
}

/*
|--------------------------------------------------------------------------
| TELEGRAM API
|--------------------------------------------------------------------------
*/

function telegram(string $method, array $params = []): ?array
{
    global $BOT_TOKEN;

    $url =
        'https://api.telegram.org/bot'
        . $BOT_TOKEN
        . '/'
        . $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        echo "Telegram CURL ERROR: "
            . curl_error($ch)
            . "\n";

        return null;
    }

    $data = json_decode($response, true);

    return is_array($data) ? $data : null;
}

function sendMessage(
    int|string $chatId,
    string $text
): void {
    telegram(
        'sendMessage',
        [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => false,
        ]
    );
}

/*
|--------------------------------------------------------------------------
| ГЛАВНОЕ МЕНЮ
|--------------------------------------------------------------------------
*/

function mainKeyboard(): array
{
    return [
        'keyboard' => [
            [
                ['text' => '📊 Статус'],
                ['text' => '⚙️ Настройки'],
            ],
            [
                ['text' => '▶️ Запустить'],
                ['text' => '⏹ Остановить'],
            ],
            [
                ['text' => '🧪 Проверить сейчас'],
            ],
            [
                ['text' => '📍 Откуда'],
                ['text' => '📍 Куда'],
            ],
            [
                ['text' => '📅 Даты'],
                ['text' => '🚆 Вагон'],
            ],
            [
                ['text' => '⏱ Интервал'],
            ],
            [
                ['text' => '⚙️ По умолчанию'],
            ],
        ],
        'resize_keyboard' => true,
        'is_persistent' => true,
    ];
}

function sendMainMenu(
    int|string $chatId,
    string $text = ''
): void {
    if ($text === '') {
        $text = "🤖 RZD МОНИТОР БИЛЕТОВ\n\nВыбери действие:";
    }

    telegram(
        'sendMessage',
        [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(
                mainKeyboard(),
                JSON_UNESCAPED_UNICODE
            ),
        ]
    );
}

/*
|--------------------------------------------------------------------------
| НАСТРОЙКИ ПО УМОЛЧАНИЮ
|--------------------------------------------------------------------------
|
| ВАЖНО:
| URL РЖД использует nodeId:
|   Калининград = 5a13bc60340c745ca1e85669
|   Москва       = 5a323c29340c7441a0a556bb
|
| А TrainSearch требует реальные коды станций.
| Поэтому ниже мы получаем их через stations->find().
|--------------------------------------------------------------------------
*/

function setDefaultSettings(): string
{
    global $rzd, $config;

    $fromName = 'Калининград';
    $toName = 'Москва';

    $fromNodeId = '5a13bc60340c745ca1e85669';
    $toNodeId = '5a323c29340c7441a0a556bb';

    try {
        $from = $rzd->stations->find($fromName);
        $to = $rzd->stations->find($toName);

        if (!$from) {
            throw new Exception(
                "Не удалось найти станцию Калининград."
            );
        }

        if (!$to) {
            throw new Exception(
                "Не удалось найти станцию Москва."
            );
        }

        $config['from'] = $from->name;
        $config['to'] = $to->name;

        // Реальные коды станций для TrainSearch.
        $config['fromCode'] = $from->code;
        $config['toCode'] = $to->code;

        // Node ID для ссылки ticket.rzd.ru.
        $config['fromNodeId'] = $fromNodeId;
        $config['toNodeId'] = $toNodeId;

        $config['dates'] = [
            '21.08.2026',
            '22.08.2026',
        ];

        $config['wagon'] = 'плацкарт';
        $config['interval'] = 3;

        return
            "✅ НАСТРОЙКИ ПО УМОЛЧАНИЮ УСТАНОВЛЕНЫ!\n\n"
            . settingsText()
            . "\n\n"
            . "🔧 Коды поиска:\n"
            . $config['fromCode']
            . " → "
            . $config['toCode'];

    } catch (Throwable $e) {
        return
            "❌ Не удалось установить настройки по умолчанию:\n"
            . $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| ТЕКСТ НАСТРОЕК
|--------------------------------------------------------------------------
*/

function settingsText(): string
{
    global $config;

    $from = $config['from'] ?? 'не задано';
    $to = $config['to'] ?? 'не задано';

    $dates = count($config['dates']) > 0
        ? implode("\n", $config['dates'])
        : 'не заданы';

    $monitoring = $config['monitoring']
        ? '🟢 ЗАПУЩЕН'
        : '🔴 ОСТАНОВЛЕН';

    return
        "⚙️ НАСТРОЙКИ RZD\n\n"
        . "📍 Откуда: {$from}\n"
        . "📍 Куда: {$to}\n\n"
        . "📅 Даты:\n"
        . $dates
        . "\n\n"
        . "🚆 Вагон: {$config['wagon']}\n"
        . "⏱️ Интервал: {$config['interval']} сек.\n\n"
        . "Мониторинг: {$monitoring}";
}

/*
|--------------------------------------------------------------------------
| СТАНЦИЯ
|--------------------------------------------------------------------------
*/

function setStation(
    string $type,
    string $name
): string {
    global $rzd, $config;

    $station = $rzd->stations->find($name);

    if (!$station) {
        return "❌ Станция не найдена: {$name}";
    }

    if ($type === 'from') {
        $config['from'] = $station->name;
        $config['fromCode'] = $station->code;

        return
            "✅ Откуда установлено:\n\n"
            . $station->name
            . " (код "
            . $station->code
            . ")";
    }

    $config['to'] = $station->name;
    $config['toCode'] = $station->code;

    return
        "✅ Куда установлено:\n\n"
        . $station->name
        . " (код "
        . $station->code
        . ")";
}

/*
|--------------------------------------------------------------------------
| ДАТЫ
|--------------------------------------------------------------------------
*/

function parseDates(string $input): array
{
    $parts = array_map(
        'trim',
        explode(',', $input)
    );

    $dates = [];

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $date = DateTimeImmutable::createFromFormat(
            'd.m.Y',
            $part
        );

        if (
            !$date ||
            $date->format('d.m.Y') !== $part
        ) {
            throw new Exception(
                "Неверная дата: {$part}\n\n"
                . "Используй формат:\n"
                . "25.08.2026, 26.08.2026"
            );
        }

        $dates[$date->format('Y-m-d')] =
            $date->format('d.m.Y');
    }

    return array_values($dates);
}

/*
|--------------------------------------------------------------------------
| ВАГОН
|--------------------------------------------------------------------------
*/

function normalizeWagon(string $wagon): string
{
    $wagon = mb_strtolower(trim($wagon));

    return match ($wagon) {
        'плацкарт', 'плац', 'п' => 'плацкарт',
        'купе', 'к' => 'купе',
        'св', 'люкс' => 'св',
        'любой', 'все', 'всё', 'любой вагон' => 'любой',

        default => throw new Exception(
            "Неизвестный тип вагона.\n\n"
            . "Можно:\n"
            . "плацкарт\n"
            . "купе\n"
            . "св\n"
            . "любой"
        ),
    };
}

function wagonMatches(
    string $wagonType,
    string $carType
): bool {
    if ($wagonType === 'любой') {
        return true;
    }

    $carType = mb_strtolower(trim($carType));

    if ($wagonType === 'плацкарт') {
        return str_contains($carType, 'плац');
    }

    if ($wagonType === 'купе') {
        return str_contains($carType, 'купе');
    }

    if ($wagonType === 'св') {
        return
            str_contains($carType, 'св')
            ||
            str_contains($carType, 'люкс');
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| ССЫЛКА РЖД
|--------------------------------------------------------------------------
*/

function rzdSearchUrl(string $dateString): string
{
    global $config;

    $date = DateTimeImmutable::createFromFormat(
        'd.m.Y',
        $dateString
    );

    if (!$date) {
        return '';
    }

    if (
        empty($config['fromNodeId']) ||
        empty($config['toNodeId'])
    ) {
        return '';
    }

    return
        'https://ticket.rzd.ru/searchresults/v/1/'
        . $config['fromNodeId']
        . '/'
        . $config['toNodeId']
        . '/'
        . $date->format('Y-m-d')
        . '?adult=1';
}

/*
|--------------------------------------------------------------------------
| ПРОВЕРКА РЖД
|--------------------------------------------------------------------------
*/

function checkRzd(): array
{
    global $rzd, $config;

    if (
        empty($config['fromCode']) ||
        empty($config['toCode']) ||
        empty($config['dates'])
    ) {
        return [];
    }

    $found = [];

    foreach ($config['dates'] as $dateString) {
        try {
            $date = DateTimeImmutable::createFromFormat(
                'd.m.Y',
                $dateString
            );

            if (!$date) {
                continue;
            }

            echo
                "[{$dateString}] "
                . "Запрос РЖД: "
                . $config['fromCode']
                . " -> "
                . $config['toCode']
                . "\n";

            $request = new TrainSearch(
                origin: $config['fromCode'],
                destination: $config['toCode'],
                date: $date,
                adults: 1,
                children: 0,
                fromSchedule: false,
                largeFamily: false
            );

            $result = $rzd->trains->search($request);

            echo
                "[{$dateString}] "
                . "Получено поездов: "
                . count($result->trains)
                . "\n";

            foreach ($result->trains as $train) {
                foreach ($train->carGroups as $car) {
                    $carType = (string)($car->typeName ?? '');

                    echo
                        "  Поезд {$train->number}, "
                        . "вагон: {$carType}, "
                        . "мест: "
                        . var_export(
                            $car->places ?? null,
                            true
                        )
                        . ", saleForbidden: "
                        . var_export(
                            $car->saleForbidden ?? null,
                            true
                        )
                        . "\n";

                    // Если продажа запрещена — точно пропускаем.
                    if (!empty($car->saleForbidden)) {
                        continue;
                    }

                    // Сначала фильтр типа вагона.
                    if (
                        !wagonMatches(
                            $config['wagon'],
                            $carType
                        )
                    ) {
                        continue;
                    }

                    /*
                     * Главное исправление:
                     *
                     * places — количество свободных мест.
                     * Не сравниваем его с 0 до проверки наличия
                     * значения, чтобы странный ответ API не
                     * превращался автоматически в "билетов нет".
                     */
                    $places = $car->places ?? null;

                    if ($places === null) {
                        continue;
                    }

                    if ((int)$places <= 0) {
                        continue;
                    }

                    $classes = implode(
                        ', ',
                        $car->serviceClasses ?? []
                    );

                    $found[] = [
                        'date' => $dateString,

                        'train' => $train->number,

                        'name' =>
                            $train->name ?? '',

                        'departure' =>
                            $train->departure
                                ->format('H:i'),

                        'arrival' =>
                            $train->arrival
                                ->format('H:i'),

                        'wagon' => $carType,

                        'classes' => $classes,

                        'places' => (int)$places,

                        'price' =>
                            number_format(
                                (float)($car->minPrice ?? 0),
                                2,
                                '.',
                                ' '
                            ),

                        'url' =>
                            rzdSearchUrl($dateString),
                    ];
                }
            }

        } catch (Throwable $e) {
            echo
                "[{$dateString}] Ошибка РЖД: "
                . $e->getMessage()
                . "\n";
        }
    }

    return $found;
}

/*
|--------------------------------------------------------------------------
| СООБЩЕНИЕ О БИЛЕТЕ
|--------------------------------------------------------------------------
*/

function ticketMessage(array $ticket): string
{
    $name = $ticket['name'] !== ''
        ? " — " . $ticket['name']
        : '';

    return
        "🚨🚨🚨 БИЛЕТ НАЙДЕН! 🚨🚨🚨\n\n"
        . "📅 Дата: {$ticket['date']}\n"
        . "🚆 Поезд: {$ticket['train']}{$name}\n"
        . "🕐 Время: {$ticket['departure']} → {$ticket['arrival']}\n"
        . "🚃 Вагон: {$ticket['wagon']}\n"
        . "🎫 Класс: {$ticket['classes']}\n"
        . "💺 Мест: {$ticket['places']}\n"
        . "💰 Цена от: {$ticket['price']} ₽\n\n"
        . "⚡ БИЛЕТ ДОСТУПЕН ПРЯМО СЕЙЧАС!\n\n"
        . "🔗 Открыть поиск РЖД:\n"
        . $ticket['url'];
}

/*
|--------------------------------------------------------------------------
| КОМАНДЫ
|--------------------------------------------------------------------------
*/

function handleCommand(
    int|string $chatId,
    string $text
): void {
    global $config;

    $text = trim($text);

    if ($text === '/start' || $text === '/help') {
        sendMainMenu(
            $chatId,
            "🤖 RZD МОНИТОР БИЛЕТОВ\n\n"
            . "Я постоянно проверяю РЖД и сообщаю о свободных местах.\n\n"
            . "Управление — кнопками ниже.\n\n"
            . "Также работают команды:\n"
            . "/from Москва\n"
            . "/to Санкт-Петербург\n"
            . "/dates 25.08.2026, 26.08.2026\n"
            . "/wagon плацкарт\n"
            . "/interval 3\n"
            . "/start_monitor\n"
            . "/stop_monitor\n"
            . "/test\n"
            . "/status"
        );

        return;
    }

    if ($text === '⚙️ По умолчанию') {
        sendMainMenu(
            $chatId,
            setDefaultSettings()
        );
        return;
    }

    if (str_starts_with($text, '/from ')) {
        $name = trim(substr($text, 6));

        try {
            sendMainMenu(
                $chatId,
                setStation('from', $name)
            );
        } catch (Throwable $e) {
            sendMessage(
                $chatId,
                "❌ Не удалось найти станцию:\n"
                . $e->getMessage()
            );
        }

        return;
    }

    if (str_starts_with($text, '/to ')) {
        $name = trim(substr($text, 4));

        try {
            sendMainMenu(
                $chatId,
                setStation('to', $name)
            );
        } catch (Throwable $e) {
            sendMessage(
                $chatId,
                "❌ Не удалось найти станцию:\n"
                . $e->getMessage()
            );
        }

        return;
    }

    if (str_starts_with($text, '/dates ')) {
        $input = trim(substr($text, 7));

        try {
            $config['dates'] = parseDates($input);

            sendMainMenu(
                $chatId,
                "✅ Даты сохранены:\n\n"
                . implode("\n", $config['dates'])
            );
        } catch (Throwable $e) {
            sendMessage(
                $chatId,
                "❌ " . $e->getMessage()
            );
        }

        return;
    }

    if (str_starts_with($text, '/wagon ')) {
        $wagon = trim(substr($text, 7));

        try {
            $config['wagon'] = normalizeWagon($wagon);

            sendMainMenu(
                $chatId,
                "✅ Тип вагона: {$config['wagon']}"
            );
        } catch (Throwable $e) {
            sendMessage(
                $chatId,
                "❌ " . $e->getMessage()
            );
        }

        return;
    }

    if (str_starts_with($text, '/interval ')) {
        $value = (int)trim(substr($text, 10));

        if ($value < 1) {
            sendMessage(
                $chatId,
                "❌ Интервал должен быть не меньше 1 секунды."
            );
            return;
        }

        $config['interval'] = $value;

        sendMainMenu(
            $chatId,
            "✅ Интервал установлен: {$value} сек."
        );

        return;
    }

    if (
        $text === '/status' ||
        $text === '📊 Статус' ||
        $text === '⚙️ Настройки' ||
        $text === '/settings'
    ) {
        sendMainMenu(
            $chatId,
            settingsText()
        );

        return;
    }

    if (
        $text === '/start_monitor' ||
        $text === '▶️ Запустить'
    ) {
        if (
            empty($config['fromCode']) ||
            empty($config['toCode'])
        ) {
            sendMessage(
                $chatId,
                "❌ Сначала укажи маршрут."
            );
            return;
        }

        if (empty($config['dates'])) {
            sendMessage(
                $chatId,
                "❌ Сначала укажи даты."
            );
            return;
        }

        $config['chatId'] = $chatId;
        $config['monitoring'] = true;

        sendMainMenu(
            $chatId,
            "🟢 МОНИТОРИНГ ЗАПУЩЕН!\n\n"
            . settingsText()
        );

        return;
    }

    if (
        $text === '/stop_monitor' ||
        $text === '⏹ Остановить'
    ) {
        $config['monitoring'] = false;

        sendMainMenu(
            $chatId,
            "🔴 Мониторинг остановлен."
        );

        return;
    }

    if (
        $text === '/test' ||
        $text === '🧪 Проверить сейчас'
    ) {
        if (
            empty($config['fromCode']) ||
            empty($config['toCode']) ||
            empty($config['dates'])
        ) {
            sendMessage(
                $chatId,
                "❌ Недостаточно настроек.\n\n"
                . settingsText()
            );
            return;
        }

        sendMessage(
            $chatId,
            "🔎 Проверяю РЖД..."
        );

        $tickets = checkRzd();

        if (count($tickets) === 0) {
            sendMessage(
                $chatId,
                "❌ Подходящих билетов сейчас нет."
            );
            return;
        }

        foreach ($tickets as $ticket) {
            sendMessage(
                $chatId,
                ticketMessage($ticket)
            );
        }

        return;
    }

    if ($text === '📍 Откуда') {
        sendMessage(
            $chatId,
            "Напиши:\n\n/from Москва"
        );
        return;
    }

    if ($text === '📍 Куда') {
        sendMessage(
            $chatId,
            "Напиши:\n\n/to Санкт-Петербург"
        );
        return;
    }

    if ($text === '📅 Даты') {
        sendMessage(
            $chatId,
            "Напиши даты через запятую:\n\n"
            . "/dates 21.08.2026, 22.08.2026"
        );
        return;
    }

    if ($text === '🚆 Вагон') {
        sendMessage(
            $chatId,
            "Выбери тип:\n\n"
            . "/wagon плацкарт\n"
            . "/wagon купе\n"
            . "/wagon св\n"
            . "/wagon любой"
        );
        return;
    }

    if ($text === '⏱ Интервал') {
        sendMessage(
            $chatId,
            "Напиши интервал в секундах:\n\n"
            . "/interval 3"
        );
        return;
    }

    if ($text === '/clear') {
        $config['from'] = null;
        $config['to'] = null;
        $config['fromCode'] = null;
        $config['toCode'] = null;
        $config['fromNodeId'] = null;
        $config['toNodeId'] = null;
        $config['dates'] = [];
        $config['wagon'] = 'любой';
        $config['interval'] = 5;
        $config['monitoring'] = false;

        sendMainMenu(
            $chatId,
            "🧹 Настройки очищены."
        );

        return;
    }

    sendMessage(
        $chatId,
        "❓ Не понял команду.\n\nНажми /help"
    );
}

/*
|--------------------------------------------------------------------------
| ЗАПУСК
|--------------------------------------------------------------------------
*/

$offset = 0;

echo "\n";
echo "========================================\n";
echo "       RZD TELEGRAM BOT\n";
echo "========================================\n";
echo "Бот запущен.\n";

while (true) {
    /*
     * Telegram.
     */
    $updates = telegram(
        'getUpdates',
        [
            'offset' => $offset,
            'timeout' => 1,
        ]
    );

    if (
        $updates &&
        !empty($updates['ok']) &&
        !empty($updates['result'])
    ) {
        foreach ($updates['result'] as $update) {
            $offset = $update['update_id'] + 1;

            if (empty($update['message'])) {
                continue;
            }

            $message = $update['message'];

            if (empty($message['chat']['id'])) {
                continue;
            }

            $chatId = $message['chat']['id'];
            $text = $message['text'] ?? '';

            echo "\n";
            echo "["
                . date('d.m.Y H:i:s')
                . "] Telegram: "
                . $text
                . "\n";

            handleCommand(
                $chatId,
                $text
            );
        }
    }

    /*
     * Мониторинг.
     *
     * Интервал считается после завершения проверки.
     * Поэтому если сам запрос РЖД занял 2 секунды,
     * при interval=3 следующая проверка начнётся
     * примерно через 3 секунды после ответа РЖД.
     */
    if (
        $config['monitoring'] &&
        $config['chatId'] !== null
    ) {
        static $nextCheck = 0;

        $now = microtime(true);

        if ($now >= $nextCheck) {
            echo "\n";
            echo "----------------------------------------\n";
            echo "Проверка РЖД: "
                . date('d.m.Y H:i:s')
                . "\n";
            echo "----------------------------------------\n";

            $checkStarted = microtime(true);

            $tickets = checkRzd();

            $checkTime =
                microtime(true) - $checkStarted;

            echo "Проверка заняла "
                . number_format($checkTime, 2)
                . " сек.\n";

            if (count($tickets) === 0) {
                echo "Билетов нет.\n";
            } else {
                echo "🚨 НАЙДЕНО: "
                    . count($tickets)
                    . "\n";

                /*
                 * Никакой защиты от повторных уведомлений нет.
                 * Пока билет есть, уведомление отправляется
                 * снова на каждом цикле.
                 */
                foreach ($tickets as $ticket) {
                    sendMessage(
                        $config['chatId'],
                        ticketMessage($ticket)
                    );
                }
            }

            $nextCheck =
                microtime(true)
                + $config['interval'];
        }
    }

    usleep(100000);
}
