/**
 * k6 Stress Test Script - Push System to Breaking Point
 *
 * Usage: k6 run stress-test.js
 *
 * Purpose: Find the maximum capacity of the system
 */

import http from "k6/http";
import { check, sleep } from "k6";
import { Rate } from "k6/metrics";

const errorRate = new Rate("errors");

export const options = {
    stages: [
        { duration: "2m", target: 100 }, // Ramp up to 100 users
        { duration: "5m", target: 100 }, // Stay at 100 for 5m
        { duration: "2m", target: 200 }, // Ramp up to 200
        { duration: "5m", target: 200 }, // Stay at 200 for 5m
        { duration: "2m", target: 300 }, // Ramp up to 300
        { duration: "5m", target: 300 }, // Stay at 300 for 5m
        { duration: "2m", target: 400 }, // Push to 400
        { duration: "5m", target: 400 }, // Stay at 400
        { duration: "10m", target: 0 }, // Ramp down
    ],
    thresholds: {
        errors: ["rate<0.5"], // Allow up to 50% errors in stress test
        http_req_duration: ["p(95)<2000"], // 95% under 2 seconds
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
        "status is 302 or 200": (r) => r.status === 302 || r.status === 200,
    });

    errorRate.add(!success);

    sleep(0.1); // Minimal sleep for stress test
}
