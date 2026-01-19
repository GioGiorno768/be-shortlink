<?php

namespace App\Services;

use App\Models\Link;
use App\Models\LinkViolation;
use App\Models\ViolationReferrer;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ViolationService
{
    /**
     * Cache TTL for violation referrers (5 minutes)
     */
    const CACHE_TTL = 300;

    /**
     * Check if the referer URL is from a violation domain
     *
     * @param string|null $referer
     * @return bool
     */
    public function isViolationReferrer(?string $referer): bool
    {
        if (empty($referer)) {
            return false;
        }

        // Extract domain from referer URL
        $domain = $this->extractDomain($referer);
        if (empty($domain)) {
            return false;
        }

        // Get cached list of violation domains
        $violationDomains = $this->getViolationDomains();

        // Check if domain matches any violation referrer
        foreach ($violationDomains as $violationDomain) {
            // Exact match or subdomain match
            if ($domain === $violationDomain || str_ends_with($domain, '.' . $violationDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a violation and apply penalty if threshold reached
     *
     * @param Link $link
     * @param string $referer
     * @return array
     */
    public function recordViolation(Link $link, string $referer): array
    {
        $domain = $this->extractDomain($referer);
        $settings = $this->getViolationSettings();

        // Find or create violation record
        $violation = LinkViolation::updateOrCreate(
            [
                'link_id' => $link->id,
                'referrer_domain' => $domain,
            ],
            [
                'user_id' => $link->user_id,
                'last_detected_at' => Carbon::now(),
            ]
        );

        // If new record, set first_detected_at
        if ($violation->wasRecentlyCreated) {
            $violation->first_detected_at = Carbon::now();
            $violation->violation_count = 1;
        } else {
            $violation->increment('violation_count');
        }
        $violation->save();

        // Get total violations for this link
        $totalViolations = LinkViolation::where('link_id', $link->id)->sum('violation_count');

        // Check if threshold reached for penalty
        $penaltyApplied = false;
        if ($totalViolations >= $settings['threshold'] && $link->cpc_penalty_percent == 0) {
            $penaltyApplied = $this->applyPenalty($link, $settings);
        }

        // Check if auto-disable threshold reached
        $linkDisabled = false;
        if ($settings['auto_disable'] && $totalViolations >= $settings['auto_disable_threshold']) {
            $link->status = 'disabled';
            $link->save();
            $linkDisabled = true;

            Log::warning("Link auto-disabled due to violations", [
                'link_id' => $link->id,
                'total_violations' => $totalViolations,
            ]);
        }

        return [
            'violation_count' => $violation->violation_count,
            'total_violations' => $totalViolations,
            'penalty_applied' => $penaltyApplied,
            'link_disabled' => $linkDisabled,
        ];
    }

    /**
     * Apply CPC penalty to a link
     *
     * @param Link $link
     * @param array $settings
     * @return bool
     */
    public function applyPenalty(Link $link, array $settings): bool
    {
        $link->cpc_penalty_percent = $settings['penalty_percent'];
        $link->penalty_applied_at = Carbon::now();
        $link->penalty_expires_at = Carbon::now()->addDays($settings['penalty_days']);
        $link->save();

        Log::info("CPC penalty applied to link", [
            'link_id' => $link->id,
            'penalty_percent' => $settings['penalty_percent'],
            'expires_at' => $link->penalty_expires_at,
        ]);

        return true;
    }

    /**
     * Remove penalty from a link (manual or auto-expire)
     *
     * @param Link $link
     * @return bool
     */
    public function removePenalty(Link $link): bool
    {
        $link->cpc_penalty_percent = 0;
        $link->penalty_applied_at = null;
        $link->penalty_expires_at = null;
        $link->save();

        return true;
    }

    /**
     * Check and remove expired penalties
     * Can be called via scheduler
     *
     * @return int Number of penalties removed
     */
    public function cleanupExpiredPenalties(): int
    {
        $count = Link::where('cpc_penalty_percent', '>', 0)
            ->where('penalty_expires_at', '<', Carbon::now())
            ->update([
                'cpc_penalty_percent' => 0,
                'penalty_applied_at' => null,
                'penalty_expires_at' => null,
            ]);

        if ($count > 0) {
            Log::info("Cleaned up expired penalties", ['count' => $count]);
        }

        return $count;
    }

    /**
     * Get violation settings from database/cache
     *
     * @return array
     */
    public function getViolationSettings(): array
    {
        return Cache::remember('violation_settings', self::CACHE_TTL, function () {
            $settings = Setting::where('key', 'violation')->first();

            if ($settings && is_array($settings->value)) {
                return array_merge($this->getDefaultSettings(), $settings->value);
            }

            return $this->getDefaultSettings();
        });
    }

    /**
     * Update violation settings
     *
     * @param array $data
     * @return array
     */
    public function updateViolationSettings(array $data): array
    {
        $settings = Setting::updateOrCreate(
            ['key' => 'violation'],
            ['value' => $data]
        );

        Cache::forget('violation_settings');

        return $data;
    }

    /**
     * Get default violation settings
     *
     * @return array
     */
    protected function getDefaultSettings(): array
    {
        return [
            'penalty_percent' => 30,           // 30% CPC reduction
            'threshold' => 3,                   // 3 violations before penalty
            'penalty_days' => 7,                // Penalty lasts 7 days
            'auto_disable' => false,            // Don't auto-disable by default
            'auto_disable_threshold' => 10,     // Violations before auto-disable
        ];
    }

    /**
     * Get list of violation domains from cache/database
     *
     * @return array
     */
    protected function getViolationDomains(): array
    {
        return Cache::remember('violation_domains', self::CACHE_TTL, function () {
            return ViolationReferrer::active()
                ->pluck('domain')
                ->toArray();
        });
    }

    /**
     * Extract domain from URL
     *
     * @param string $url
     * @return string|null
     */
    protected function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);

        if (isset($parsed['host'])) {
            // Remove www. prefix
            $domain = preg_replace('/^www\./', '', $parsed['host']);
            return strtolower($domain);
        }

        return null;
    }

    /**
     * Clear violation domains cache
     */
    public function clearCache(): void
    {
        Cache::forget('violation_domains');
        Cache::forget('violation_settings');
    }
}
