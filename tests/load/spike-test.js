/**
 * k6 Spike Test Script - Test Sudden Traffic Spike
 *
 * Usage: k6 run spike-test.js
 *
 * Purpose: Test how system handles sudden traffic spikes
 */

import http from "k6/http";
import { check, sleep } from "k6";
import { Rate } from "k6/metrics";

const errorRate = new Rate("errors");

export const options = {
    stages: [
        { duration: "10s", target: 10 }, // Warm up
        { duration: "1m", target: 10 }, // Stay low
        { duration: "10s", target: 500 }, // SPIKE! 10 -> 500 dalam 10 detik
        { duration: "3m", target: 500 }, // Stay at spike
        { duration: "10s", target: 10 }, // Drop back down
        { duration: "3m", target: 10 }, // Recovery period
        { duration: "10s", target: 0 }, // Ramp down
    ],
    thresholds: {
        http_req_duration: ["p(95)<3000"], // 95% under 3s during spike
        errors: ["rate<0.3"], // Allow up to 30% errors during spike
    },
};

const BASE_URL = "http://127.0.0.1:8000";
const TEST_SHORTCODES = ["test123", "demo456", "sample789"];

export default function () {
    const shortcode =
        TEST_SHORTCODES[Math.floor(Math.random() * TEST_SHORTCODES.length)];

    const res = http.get(`${BASE_URL}/${shortcode}`, {
        redirects: 0,
    });

    const success = check(res, {
        "status is 302": (r) => r.status === 302,
        "response time < 1s": (r) => r.timings.duration < 1000,
    });

    errorRate.add(!success);

    sleep(0.1);
}
