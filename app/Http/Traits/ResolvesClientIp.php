<?php

namespace App\Http\Traits;

/**
 * 🚀 PERFORMANCE OPTIMIZATION: Centralized IP Resolution
 * 
 * This trait provides a consistent way to resolve client IP address
 * across all controllers, avoiding code duplication and ensuring
 * consistent behavior in local vs production environments.
 */
trait ResolvesClientIp
{
    /**
     * Get the resolved client IP address.
     * In local environment, replaces 127.0.0.1 with a real IP for testing.
     *
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected function resolveClientIp($request): string
    {
        $ip = $request->ip();
        
        // In local development, use a real IP for GeoIP testing
        if (app()->environment('local') && $ip === '127.0.0.1') {
            return '36.84.69.10'; // Indonesian IP for testing
        }
        
        return $ip;
    }
    
    /**
     * Generate a cache key based on IP and User-Agent for token validation.
     *
     * @param string $code Link code
     * @param \Illuminate\Http\Request $request
     * @return string
     */
    protected function getTokenCacheKey(string $code, $request): string
    {
        $ip = $this->resolveClientIp($request);
        $userAgent = $request->header('User-Agent') ?? '';
        
        return "token:{$code}:" . md5("{$ip}-{$userAgent}");
    }
}
