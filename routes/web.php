<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

// Work out which tag this visitor is (query param first, then saved cookie)
if (! function_exists('minne_tag')) {
    function minne_tag(Request $request): string
    {
        $ref = trim((string) ($request->query('ref') ?? $request->cookie('minne_ref') ?? ''));

        foreach (config('minne.tags', []) as $tag) {
            if (strcasecmp($ref, $tag) === 0) {
                return $tag; // clean casing
            }
        }

        return $ref === '' ? 'Direct' : 'Unknown';
    }
}

// Send a Telegram message (reads token + chat id from config/minne.php)
if (! function_exists('minne_notify')) {
    function minne_notify(string $text): void
    {
        $token  = config('minne.telegram_bot_token');
        $chatId = config('minne.telegram_chat_id');

        if (! $token || ! $chatId) {
            return;
        }

        try {
            Http::timeout(4)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            // never break the page over a notify
        }
    }
}

// Home page
Route::get('/', function (Request $request) {
    $tag = minne_tag($request);

    if ($request->query('ref')) {
        Cookie::queue('minne_ref', $request->query('ref'), 60 * 24 * 30); // remember 30 days
        $ip = trim(explode(',', (string) $request->header('X-Forwarded-For', $request->ip()))[0]);
        $ua = substr((string) $request->userAgent(), 0, 200);
        \Illuminate\Support\Facades\Log::info("VISIT tag={$tag} ip={$ip} ua={$ua}");
//        minne_notify("👀 <b>Page visit</b>\nTag: <b>{$tag}</b>");
    }

    return response()->file(resource_path('site/index.html'));
});

// Download handler (your full original logic + tag support)
$download = function (Request $request) {
    $name = basename((string) config('minne.download_filename'));
    $path = resource_path('site/files/' . $name);

    abort_unless($name !== '' && is_file($path), 404);

    // Notify Telegram. Wrapped so a failure here never blocks the download.
    try {
        $token  = config('minne.telegram_bot_token');
        $chatId = config('minne.telegram_chat_id');

        $clientIp = function (Request $request): string {
            foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                $raw = $request->server($header);
                if (! $raw) {
                    continue;
                }
                $candidate = trim(explode(',', $raw)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }

            return $request->ip() ?: 'UNKNOWN';
        };

        $detectDevice = function (string $ua): string {
            if (preg_match('/iPhone\s*OS\s*([\d_]+)/i', $ua, $m)) {
                return 'iPhone (iOS ' . str_replace('_', '.', $m[1]) . ')';
            }
            if (preg_match('/iPad.*OS\s*([\d_]+)/i', $ua, $m)) {
                return 'iPad (iOS ' . str_replace('_', '.', $m[1]) . ')';
            }
            if (preg_match('/Android\s*([\d.]+).*?;\s*([^)]+)\)/i', $ua, $m)) {
                return trim($m[2]) . ' (Android ' . $m[1] . ')';
            }
            if (stripos($ua, 'Windows NT 10') !== false) {
                return 'Windows 10/11 PC';
            }
            if (stripos($ua, 'Windows NT 6.3') !== false) {
                return 'Windows 8.1 PC';
            }
            if (stripos($ua, 'Macintosh') !== false) {
                return 'Mac';
            }
            if (stripos($ua, 'Linux') !== false) {
                return 'Linux PC';
            }

            return 'Unknown Device';
        };

        $detectBrowser = function (string $ua): string {
            if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) {
                return 'Edge ' . $m[1];
            }
            if (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) {
                return 'Opera ' . $m[1];
            }
            if (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) {
                return 'Chrome ' . $m[1];
            }
            if (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
                return 'Firefox ' . $m[1];
            }
            if (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) {
                return 'Safari ' . $m[1];
            }

            return 'Other';
        };

        $detectReferrer = function (Request $request): string {
            $ref = (string) $request->headers->get('referer', '');
            if ($ref === '') {
                return 'Direct / Unknown';
            }

            $host = strtolower((string) (parse_url($ref, PHP_URL_HOST) ?? ''));

            if (str_contains($host, 'mail.google') || str_contains($host, 'gmail')) {
                return 'Gmail';
            }
            if (str_contains($host, 'outlook') || str_contains($host, 'hotmail')) {
                return 'Outlook';
            }
            if (str_contains($host, 'whatsapp')) {
                return 'WhatsApp';
            }
            if (str_contains($host, 'telegram')) {
                return 'Telegram';
            }
            if (str_contains($host, 'facebook') || str_contains($host, 'fb.com')) {
                return 'Facebook';
            }
            if (str_contains($host, 'twitter') || str_contains($host, 't.co')) {
                return 'Twitter/X';
            }
            if (str_contains($host, 'instagram')) {
                return 'Instagram';
            }
            if (str_contains($host, 'linkedin')) {
                return 'LinkedIn';
            }
            if (str_contains($host, 'yahoo')) {
                return 'Yahoo Mail';
            }

            return $host !== '' ? $host : 'Unknown';
        };

        $countryFlag = function (string $countryCode): string {
            if (strlen($countryCode) !== 2) {
                return '🌍';
            }
            $code = strtoupper($countryCode);

            return mb_chr(0x1F1E6 + ord($code[0]) - ord('A'))
                 . mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));
        };

        $isUniqueVisitor = function (string $ip): bool {
            $logFile = storage_path('app/visitors.log');
            $dir     = dirname($logFile);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $seen = is_file($logFile)
                ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                : [];

            if (in_array($ip, $seen, true)) {
                return false;
            }

            file_put_contents($logFile, $ip . "\n", FILE_APPEND | LOCK_EX);

            return true;
        };

        $ip        = $clientIp($request);
        $ua        = (string) $request->userAgent();
        $device    = $detectDevice($ua);
        $browser   = $detectBrowser($ua);
        $referrer  = $detectReferrer($request);
        $type      = $request->query('type', 'download');
        $isUnique  = $isUniqueVisitor($ip);
        $tag       = minne_tag($request);   // ← new tagging support

        $city = $country = $countryCode = $isp = $timezone = 'Unknown';

        $geo = Http::timeout(4)
            ->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,country,countryCode,city,isp,timezone',
            ])
            ->json();

        if (is_array($geo) && ($geo['status'] ?? null) === 'success') {
            $city        = $geo['city'] ?? 'Unknown';
            $country     = $geo['country'] ?? 'Unknown';
            $countryCode = $geo['countryCode'] ?? '';
            $isp         = $geo['isp'] ?? 'Unknown';
            $timezone    = $geo['timezone'] ?? 'Unknown';
        }

        $flag = $countryFlag((string) $countryCode);

        $localTime = 'Unknown';
        if ($timezone !== 'Unknown') {
            try {
                $localTime = now($timezone)->format('Y-m-d H:i:s') . " ({$timezone})";
            } catch (\Throwable $e) {
                $localTime = 'Unknown';
            }
        }

        $uniqueLabel = $isUnique ? '🆕 NEW VISITOR' : '🔁 RETURNING VISITOR';

        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $configTag = (string) config('minne.telegram_tag', '');

        $message  = "🚨 <b>UPDATE EXE ALERT</b>\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📁 <b>File:</b> " . $e($name) . "\n";
        $message .= "🏷 <b>Tag:</b> " . $e($tag) . "\n";          // ← added tag line
        $message .= "👤 <b>Visitor:</b> {$uniqueLabel}\n";
        $message .= "📱 <b>Device:</b> " . $e($device) . "\n";
        $message .= "🌐 <b>Browser:</b> " . $e($browser) . "\n";
        $message .= "{$flag} <b>Location:</b> " . $e($city) . ", " . $e($country) . "\n";
        $message .= "🧭 <b>ISP:</b> " . $e($isp) . "\n";
        $message .= "🕒 <b>Server Time:</b> " . now()->toDateTimeString() . " UTC\n";
        $message .= "🌍 <b>Local Time:</b> " . $e($localTime) . "\n";
        $message .= "📨 <b>Source:</b> " . $e($referrer) . "\n";
        $message .= "🔗 <b>Type:</b> " . $e($type) . "\n";
        $message .= "🔒 <b>IP:</b> " . $e($ip) . "\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= $configTag;

        $logLine = implode(' | ', [
            now()->toDateTimeString(),
            $ip,
            "{$country}/{$city}",
            $browser,
            $device,
            $referrer,
            $isUnique ? 'UNIQUE' : 'RETURNING',
            $name,
            $tag,                       // ← also log the tag
        ]) . "\n";

        file_put_contents(storage_path('logs/downloads.log'), $logLine, FILE_APPEND | LOCK_EX);

        if ($token && $chatId) {
            Http::timeout(4)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'HTML',
            ]);
        }
    } catch (\Throwable $e) {
        // Ignore notification errors and still serve the file.
    }

    return response()->download($path);
};

Route::get('/download', $download);
Route::get('/download.php', $download);
