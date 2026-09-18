<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class BlockOrg
{
    public function handle(Request $request, Closure $next): Response
    {
        $shouldBlock = false;

        try {
            if (in_array($request->path(), ['/', 'download', 'download.php'], true)) {
                $blocked = config('minne.blocked_orgs', []);
                if (! empty($blocked)) {
                    $shouldBlock = $this->isBlocked($request, $blocked);
                }
            }
        } catch (\Throwable $e) {
            $shouldBlock = false; // never crash the site
        }

        if ($shouldBlock) {
            abort(403);
        }

        return $next($request);
    }

    private function isBlocked(Request $request, array $blocked): bool
    {
        $ip = $this->clientIp($request);
        if ($ip === '') return false;

        $org = $this->lookupOrg($ip);
        if ($org === '') return false;

        foreach ($blocked as $needle) {
            if ($needle !== '' && stripos($org, $needle) !== false) return true;
        }
        return false;
    }

    private function clientIp(Request $request): string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $raw = $request->server($header);
            if (! $raw) continue;
            $candidate = trim(explode(',', $raw)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
        }
        return $request->ip() ?: '';
    }

    private function lookupOrg(string $ip): string
    {
        try {
            return Cache::remember("minne_org_{$ip}", now()->addDay(), fn () => $this->fetchOrg($ip));
        } catch (\Throwable $e) {
            return $this->fetchOrg($ip); // cache problem? just look it up directly
        }
    }

    private function fetchOrg(string $ip): string
    {
        try {
            $res = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,org,isp,as',
            ]);
            if ($res->ok() && $res->json('status') === 'success') {
                return trim(($res->json('org') ?? '') . ' ' .
                            ($res->json('isp') ?? '') . ' ' .
                            ($res->json('as')  ?? ''));
            }
        } catch (\Throwable $e) {}
        return '';
    }
}
