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
        // Only guard the page and the download; ignore health checks/assets
        if (! in_array($request->path(), ['/', 'download', 'download.php'], true)) {
            return $next($request);
        }

        $blocked = config('minne.blocked_orgs', []);
        if (! empty($blocked) && $this->isBlocked($request, $blocked)) {
            abort(403);
        }

        return $next($request);
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

    private function isBlocked(Request $request, array $blocked): bool
    {
        $ip = $this->clientIp($request);
        if ($ip === '') return false;

        // Look each IP up only once a day
        $org = Cache::remember("minne_org_{$ip}", now()->addDay(), function () use ($ip) {
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
            return ''; // any failure => don't block
        });

        foreach ($blocked as $needle) {
            if ($needle !== '' && stripos($org, $needle) !== false) return true;
        }
        return false;
    }
}
