/**
 * k6 Load Testing Script for Shortlinkmu API
 *
 * Installation:
 * - Windows: winget install k6 --source winget
 * - Or download from: https://github.com/grafana/k6/releases
 *
 * Usage:
 * - Basic: k6 run load-test.js
 * - With more VUs: k6 run --vus 50 --duration 1m load-test.js
 * - Save report: k6 run load-test.js --out json=results.json
 */

import http from "k6/http";
import { check, sleep, group } from "k6";
import { Rate, Trend } from "k6/metrics";

// Custom metrics
const errorRate = new Rate("errors");
const redirectLatency = new Trend("redirect_latency");

// Test configuration
export const options = {
    // Stages untuk gradual load increase
    stages: [
        { duration: "30s", target: 20 }, // Warm up: 0 -> 20 users
        { duration: "1m", target: 50 }, // Ramp up: 20 -> 50 users
        { duration: "2m", target: 50 }, // Stay at 50 users
        { duration: "30s", target: 100 }, // Spike: 50 -> 100 users
        { duration: "1m", target: 100 }, // Stay at 100 users
        { duration: "30s", target: 0 }, // Ramp down: 100 -> 0
    ],

    // Thresholds - test fails if these are not met
    thresholds: {
        http_req_duration: ["p(95)<500"], // 95% requests < 500ms
        http_req_failed: ["rate<0.05"], // Error rate < 5%
        redirect_latency: ["p(99)<1000"], // 99% redirects < 1s
        errors: ["rate<0.1"], // Custom error rate < 10%
    },
};

// Configuration - GANTI DENGAN DATA YANG SESUAI
const BASE_URL = "http://127.0.0.1:8000";
const API_URL = `${BASE_URL}/api`;

// Sample shortcodes to test (ganti dengan shortcode yang ada di DB)
const TEST_SHORTCODES = ["test123", "demo456", "sample789"];

// Test user credentials (untuk authenticated endpoints)
const TEST_USER = {
    email: "test@example.com",
    password: "password123",
};

// Helper function untuk random shortcode
function getRandomShortcode() {
    return TEST_SHORTCODES[Math.floor(Math.random() * TEST_SHORTCODES.length)];
}

// Setup function - runs once before test
export function setup() {
    console.log("🚀 Starting Load Test for Shortlinkmu API");
    console.log(`Target URL: ${BASE_URL}`);

    // Optional: Login untuk dapat token
    // const loginRes = http.post(`${API_URL}/auth/login`, JSON.stringify(TEST_USER), {
    //   headers: { 'Content-Type': 'application/json' },
    // });
    // return { token: loginRes.json('token') };

    return {};
}

// Main test function - runs for each VU iteration
export default function (data) {
    // ===== TEST 1: Shortlink Redirect (Most Critical!) =====
    group("Shortlink Redirect", function () {
        const shortcode = getRandomShortcode();
        const startTime = new Date();

        const res = http.get(`${BASE_URL}/${shortcode}`, {
            redirects: 0, // Don't follow redirects, just measure first response
            tags: { name: "redirect" },
        });

        const latency = new Date() - startTime;
        redirectLatency.add(latency);

        const success = check(res, {
            "redirect status is 302": (r) => r.status === 302,
            "has location header": (r) => r.headers["Location"] !== undefined,
            "response time < 200ms": (r) => r.timings.duration < 200,
        });

        errorRate.add(!success);
    });

    sleep(1); // Pause between requests

    // ===== TEST 2: API Health Check =====
    group("API Health", function () {
        const res = http.get(`${API_URL}/health`, {
            tags: { name: "health" },
        });

        check(res, {
            "health check status is 200": (r) => r.status === 200,
        });
    });

    sleep(0.5);

    // ===== TEST 3: Public Stats (if available) =====
    group("Public Endpoints", function () {
        // Example: Get link stats
        const shortcode = getRandomShortcode();
        const res = http.get(`${API_URL}/links/${shortcode}/stats`, {
            tags: { name: "link_stats" },
        });

        check(res, {
            "stats response is valid": (r) =>
                r.status === 200 || r.status === 401 || r.status === 404,
        });
    });

    sleep(0.5);
}

// Teardown function - runs once after test
export function teardown(data) {
    console.log("✅ Load Test Completed");
}

// Handle summary at the end
export function handleSummary(data) {
    console.log("\n📊 Test Summary:");
    console.log(`Total Requests: ${data.metrics.http_reqs.values.count}`);
    console.log(
        `Avg Response Time: ${data.metrics.http_req_duration.values.avg.toFixed(
            2
        )}ms`
    );
    console.log(
        `95th Percentile: ${data.metrics.http_req_duration.values[
            "p(95)"
        ].toFixed(2)}ms`
    );
    console.log(
        `Error Rate: ${(data.metrics.http_req_failed.values.rate * 100).toFixed(
            2
        )}%`
    );

    return {
        stdout: textSummary(data, { indent: " ", enableColors: true }),
        "results/summary.json": JSON.stringify(data, null, 2),
    };
}

// Text summary helper
function textSummary(data, options) {
    const indent = options.indent || "";
    let summary = "\n=== K6 LOAD TEST RESULTS ===\n\n";

    summary += `${indent}Iterations: ${data.metrics.iterations.values.count}\n`;
    summary += `${indent}VUs Max: ${data.metrics.vus_max.values.max}\n`;
    summary += `${indent}Duration: ${(
        data.state.testRunDurationMs / 1000
    ).toFixed(1)}s\n\n`;

    summary += `${indent}HTTP Requests:\n`;
    summary += `${indent}  Total: ${data.metrics.http_reqs.values.count}\n`;
    summary += `${indent}  Rate: ${data.metrics.http_reqs.values.rate.toFixed(
        2
    )}/s\n\n`;

    summary += `${indent}Response Times:\n`;
    summary += `${indent}  Avg: ${data.metrics.http_req_duration.values.avg.toFixed(
        2
    )}ms\n`;
    summary += `${indent}  Min: ${data.metrics.http_req_duration.values.min.toFixed(
        2
    )}ms\n`;
    summary += `${indent}  Max: ${data.metrics.http_req_duration.values.max.toFixed(
        2
    )}ms\n`;
    summary += `${indent}  p(90): ${data.metrics.http_req_duration.values[
        "p(90)"
    ].toFixed(2)}ms\n`;
    summary += `${indent}  p(95): ${data.metrics.http_req_duration.values[
        "p(95)"
    ].toFixed(2)}ms\n\n`;

    return summary;
}
