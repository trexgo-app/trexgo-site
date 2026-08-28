<?php

declare(strict_types=1);

// Точка входа выкладки сайта: GitHub Actions дёргает этот адрес, а код с GitHub
// сервер забирает сам. Почему не наоборот — в ops/pull-deploy.sh: канал
// «раннер → Макхост» рвёт и FTP, и SSH, а обратный свободен.
//
// Защита: общий секрет из private/leads-config.php, подпись HMAC по телу
// запроса и метка времени против повторной отправки перехваченного запроса.
//
// Токен GitHub здесь не хранится: Actions присылает свой GITHUB_TOKEN,
// он живёт минуты и действует только на свой репозиторий. Долгоживущего
// доступа к GitHub на сервере нет — если сервер скомпрометируют,
// красть будет нечего.
//
// api/lib/bootstrap.php не подключаем намеренно: он открывает соединение
// с базой, и при недоступной базе выкладка перестала бы работать ровно тогда,
// когда сервер нужно чинить. Читаем тот же private/leads-config.php напрямую.
//
// Возвращаемого типа never у deploy_fail() нет намеренно: он появился в PHP 8.1,
// а версия домена задаётся в панели и однажды уже менялась. Хук должен уметь
// сообщить об ошибке даже на более старом PHP — иначе он падает с fatal error
// ровно тогда, когда сервер нужно чинить.

header('Content-Type: application/json; charset=utf-8');

function deploy_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    deploy_fail(405, 'Только POST');
}

// Этот файл выкладывается и в превью веток (httpdocs/preview/<ветка>/), где
// его копия оказалась бы рабочим хуком: тот же private/leads-config.php лежит
// по абсолютному пути, значит и секрет тот же. Работать хук должен ровно один —
// в боевом корне. Сравниваем фактический каталог с ожидаемым, а не полагаемся
// на exclude в preview.yml: список исключений легко забыть, а эта проверка
// переезжает вместе с файлом.
$expectedRoot = getenv('TREXGO_DEPLOY_ROOT');
if (!is_string($expectedRoot) || $expectedRoot === '') {
    $expectedRoot = '/home/httpd/vhosts/trexgo.ru/httpdocs';
}
if (realpath(__DIR__) !== realpath($expectedRoot)) {
    deploy_fail(404, 'Not Found');
}

$configPath = getenv('TREXGO_LEADS_CONFIG');
if (!is_string($configPath) || $configPath === '') {
    $configPath = '/home/httpd/vhosts/trexgo.ru/private/leads-config.php';
}
if (!is_file($configPath)) {
    deploy_fail(500, 'Конфигурация недоступна');
}
$config = require $configPath;
if (!is_array($config)) {
    deploy_fail(500, 'Конфигурация повреждена');
}

$secret = $config['deploy']['secret'] ?? '';
if (!is_string($secret) || strlen($secret) < 32) {
    deploy_fail(500, 'Выкладка не настроена: нет deploy.secret');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 8192) {
    deploy_fail(400, 'Пустое или слишком большое тело запроса');
}

$given = $_SERVER['HTTP_X_DEPLOY_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $raw, $secret);
if (!is_string($given) || !hash_equals($expected, $given)) {
    deploy_fail(403, 'Подпись не совпала');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    deploy_fail(400, 'Тело не разбирается как JSON');
}

// Подписанный запрос, перехваченный по сети, нельзя отправить повторно
// через час: окно — пять минут.
$ts = $payload['ts'] ?? 0;
if (!is_int($ts) || abs(time() - $ts) > 300) {
    deploy_fail(403, 'Запрос устарел или пришёл из будущего');
}

$ref = $payload['ref'] ?? 'main';
if (!is_string($ref) || !preg_match('/\A[A-Za-z0-9._\/-]{1,100}\z/', $ref)) {
    deploy_fail(400, 'Некорректная ветка');
}

// Whitelist, а не «всё, что не production — считаем stage»: неизвестное
// значение, как и отсутствующее поле, должно остановить выкладку явной
// ошибкой, а не тихо попасть в одну из двух веток ops/pull-deploy.sh —
// каждый вызывающий (deploy.yml, stage.yml) обязан передавать env сам.
$env = $payload['env'] ?? null;
if (!is_string($env) || !in_array($env, ['production', 'stage'], true)) {
    deploy_fail(400, 'Некорректный env');
}
$targetsByEnv = [
    'production' => '/home/httpd/vhosts/trexgo.ru/httpdocs',
    'stage' => '/home/httpd/vhosts/trexgo.ru/subdomains/stage/httpdocs',
];

// Формат токенов GitHub со временем менялся (ghp_, ghs_, github_pat_ и дальше),
// поэтому не пытаемся угадать его целиком: отсекаем только то, что могло бы
// поломать окружение или уехать в лог — пробелы и управляющие символы.
// Верхний предел защищает хук от неограниченного payload, но не должен
// предполагать старую длину токена: штатный github.token уже бывает 377 байт.
$token = $payload['token'] ?? '';
if (!is_string($token) || !preg_match('/\A[\x21-\x7E]{20,4096}\z/', $token)) {
    deploy_fail(400, 'Некорректный токен');
}

$dryRun = !empty($payload['dry_run']);

// Токен не уходит в командную строку: там его видно в списке процессов
// любому пользователю на общем хостинге. Передаём через окружение.
$script = __DIR__ . '/ops/pull-deploy.sh';
if (!is_file($script)) {
    deploy_fail(500, 'Нет ops/pull-deploy.sh на сервере');
}

$scriptEnv = [
    'DEPLOY_TOKEN' => $token,
    'DEPLOY_REF' => $ref,
    'DEPLOY_REPO' => $config['deploy']['repo'] ?? 'trexgo-app/trexgo-site',
    'DEPLOY_TARGET' => $targetsByEnv[$env],
    'DEPLOY_ENV' => $env,
    'DEPLOY_DRY_RUN' => $dryRun ? '1' : '',
    'PATH' => '/usr/local/bin:/usr/bin:/bin',
];

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(['/bin/bash', $script], $descriptors, $pipes, null, $scriptEnv);
if (!is_resource($process)) {
    deploy_fail(500, 'Не удалось запустить выкладку');
}

$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$rc = proc_close($process);

// Токен в вывод попасть не должен, но если однажды попадёт — не отдаём наружу.
$out = str_replace($token, '***', $out);

http_response_code($rc === 0 ? 200 : 500);
echo json_encode([
    'ok' => $rc === 0,
    'message' => $rc === 0 ? 'Выложено' : 'Выкладка упала',
    'log' => $out,
], JSON_UNESCAPED_UNICODE);
