<?php
declare(strict_types=1);

set_time_limit(60);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    header('Content-Length: 0');
    exit();
}

class RequestValidator {
    private static $requiredKeys = [
        'socks5_server_port', 'curl_connection_timeout',
        'curl_max_timeout', 'curl_http_method', 'curl_http_version',
        'curl_user_agent', 'link'
    ];

    public static function validateRequest($data) {
        if (!is_array($data)) {
            return self::errorResponse("POST данные не являются массивом.");
        }
        foreach (self::$requiredKeys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                return self::errorResponse("Отсутствует или null значение для ключа: $key");
            }
        }
        return true;
    }

    public static function validateSocksPort($value) {
        return self::validateNumeric($value, 1, 65535, "Ошибка значения socks5_server_port.");
    }

    public static function validateConnectionTimeout($value) {
        return self::validateNumeric($value, 1, 15, "Ошибка значения curl_connection_timeout.");
    }

    public static function validateMaxTimeout($value) {
        return self::validateNumeric($value, 1, 30, "Ошибка значения curl_max_timeout.");
    }

    public static function validateHttpMethod($value) {
        if (!is_string($value) || !in_array(strtolower($value), ['get', 'head'])) {
            return self::errorResponse("Ошибка значения curl_http_method.");
        }
        return true;
    }

    public static function validateHttpVersion($value) {
        if (!is_string($value) || !in_array($value, ['http1-0', 'http1-1', 'http2'])) {
            return self::errorResponse("Ошибка значения curl_http_version.");
        }
        return true;
    }

    public static function validateUserAgent($value) {
        return self::validateNumeric($value, 1, 3, "Ошибка значения curl_user_agent.");
    }

    public static function validateLink(&$value) {
        if (!is_string($value)) {
            return self::errorResponse("Ошибка: link должен быть строкой.");
        }
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return self::errorResponse("Ошибка значения link.");
        }
        return true;
    }

    private static function validateNumeric($value, $min, $max, $errorMessage) {
        if (!is_numeric($value) || $value < $min || $value > $max) {
            return self::errorResponse($errorMessage);
        }
        return true;
    }

    private static function errorResponse($message) {
        return [
            'result' => false,
            'message' => $message,
            'http_response_code' => '000'
        ];
    }
}

class CurlHandler {
    private static $userAgents = [
        1 => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0',
        2 => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0',
        3 => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36'
    ];

    public static function executeRequest($data) {
        // Попробуем найти системные сертификаты Alpine Linux
        $systemCertPaths = [
            '/etc/ssl/certs/ca-certificates.crt',  // Alpine Linux
            '/etc/ssl/cert.pem',                   // BSD systems
            '/etc/pki/tls/certs/ca-bundle.crt',   // CentOS/RHEL
            '/etc/ssl/certs/ca-bundle.crt',       // openSUSE
            '/usr/local/share/certs/ca-root-nss.crt' // FreeBSD
        ];

        $certPath = null;
        $certInfo = [];

        // Детальная проверка каждого пути к сертификатам
        foreach ($systemCertPaths as $path) {
            $exists = file_exists($path);
            $readable = $exists ? is_readable($path) : false;
            $size = ($exists && $readable) ? filesize($path) : 0;

            $certInfo[] = [
                'path' => $path,
                'exists' => $exists,
                'readable' => $readable,
                'size' => $size
            ];

            if ($exists && $readable && $size > 0) {
                $certPath = $path;
                break;
            }
        }

        // Если не найден ни один сертификат, логируем информацию
        if (!$certPath) {
            error_log('ByeDPI-WM: Системные сертификаты не найдены. Проверенные пути: ' . json_encode($certInfo));
        } else {
            error_log('ByeDPI-WM: Используем системные сертификаты: ' . $certPath);
        }

        $ch = curl_init();
        if (!$ch) {
            return [
                'result' => false,
                'message' => 'Не удалось инициализировать CURL.',
                'http_response_code' => '000'
            ];
        }

        $port = (int)$data['socks5_server_port'];
        $connectTimeout = (int)$data['curl_connection_timeout'];
        $maxTimeout = (int)$data['curl_max_timeout'];
        $userAgentId = (int)$data['curl_user_agent'];

        $curlOptions = [
            CURLOPT_URL => $data['link'],
            CURLOPT_PROXY => "127.0.0.1:".$port,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $maxTimeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];

        // Устанавливаем путь к сертификатам только если найден
        if ($certPath) {
            $curlOptions[CURLOPT_CAINFO] = $certPath;
        }
        // Если системные сертификаты не найдены, curl будет использовать встроенные

        curl_setopt_array($ch, $curlOptions);

        if (strtolower($data['curl_http_method']) === 'head') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        curl_setopt($ch, CURLOPT_HTTP_VERSION, self::getHttpVersion($data['curl_http_version']));

        $userAgent = self::$userAgents[$userAgentId] ?? '';
        if (empty($userAgent)) {
            curl_close($ch);
            return [
                'result' => false,
                'message' => 'Неверный curl_user_agent.',
                'http_response_code' => '000'
            ];
        }
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errorCode = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            return [
                'result' => false,
                'message' => "CURL ошибка: $error (код: $errorCode)",
                'http_response_code' => '000'
            ];
        }

        return [
            'result' => true,
            'message' => 'OK',
            'http_response_code' => (string)$httpCode ?: '000'
        ];
    }

    private static function getHttpVersion($version) {
        switch ($version) {
            case 'http1-0': return CURL_HTTP_VERSION_1_0;
            case 'http1-1': return CURL_HTTP_VERSION_1_1;
            case 'http2':   return CURL_HTTP_VERSION_2;
            default:        return CURL_HTTP_VERSION_NONE;
        }
    }
}

try {
    $inputRaw = file_get_contents('php://input');
    if ($inputRaw === false) {
        throw new Exception('Не удалось прочитать POST данные.');
    }
    $input = json_decode($inputRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ошибка декодирования JSON: ' . json_last_error_msg());
    }

    $validationResult = RequestValidator::validateRequest($input);
    if ($validationResult !== true) {
        echo json_encode($validationResult, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $validations = [
        'validateSocksPort' => $input['socks5_server_port'],
        'validateConnectionTimeout' => $input['curl_connection_timeout'],
        'validateMaxTimeout' => $input['curl_max_timeout'],
        'validateHttpMethod' => $input['curl_http_method'],
        'validateHttpVersion' => $input['curl_http_version'],
        'validateUserAgent' => $input['curl_user_agent'],
        'validateLink' => &$input['link']
    ];

    foreach ($validations as $method => $value) {
        $result = RequestValidator::$method($value);
        if ($result !== true) {
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $response = CurlHandler::executeRequest($input);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'result' => false,
        'message' => 'Ошибка выполнения: ' . $e->getMessage(),
        'http_response_code' => '000'
    ], JSON_UNESCAPED_UNICODE);
}
?>
