<?php
declare(strict_types=1);

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        json_error("Method not allowed, expected {$method}", 405);
    }
}

function uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Fetches a URL via cURL. Returns
 * ['ok' => bool, 'status' => int|null, 'body' => string, 'final_url' => string,
 *  'error' => string|null, 'elapsed_ms' => int|null]
 */
function http_get(string $url, int $timeoutSeconds = 10, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
        CURLOPT_USERAGENT => crawler_user_agent(),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $start = microtime(true);
    $body = curl_exec($ch);
    $elapsedMs = (int) round((microtime(true) - $start) * 1000);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => null, 'body' => '', 'final_url' => $url, 'error' => $error, 'elapsed_ms' => $elapsedMs];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return ['ok' => true, 'status' => $status, 'body' => $body, 'final_url' => $finalUrl, 'error' => null, 'elapsed_ms' => $elapsedMs];
}

function clamp_int($value, int $min, int $max, int $default): int
{
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int) $value));
}
