/**
 * k6 Quick Test Script - Fast Sanity Check
 *
 * Usage: k6 run quick-test.js
 *
 * Purpose: Quick 30-second test to verify things work
 */

import http from "k6/http";
import { check, sleep } from "k6";

export const options = {
    vus: 10, // 10 virtual users
    duration: "30s", // 30 seconds only
    thresholds: {
        http_req_duration: ["p(95)<500"],
        http_req_failed: ["rate<0.1"],
    },
};

const BASE_URL = "http://127.0.0.1:8000";
const TEST_SHORTCODES = ["0OmWbqG", "11261Kb", "16lnLgc", "1f36Ty2", "1gjBZiu"];

export default function () {
    // Test redirect with random shortcode from DB
    const shortcode =
        TEST_SHORTCODES[Math.floor(Math.random() * TEST_SHORTCODES.length)];

    const res = http.get(`${BASE_URL}/${shortcode}`, {
        redirects: 0,
    });

    check(res, {
        "status is 302 (redirect)": (r) => r.status === 302,
        "status is valid (302 or 200)": (r) =>
            r.status === 302 || r.status === 200,
        "response time OK": (r) => r.timings.duration < 500,
    });

    sleep(1);
}
