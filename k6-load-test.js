import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// ========================================
// K6 LOAD & STRESS TEST - ShortlinkMu
// ========================================
// Script untuk menguji ketahanan VPS
//
// CARA PAKAI:
// 1. Smoke Test (cek basic): k6 run --env TEST_TYPE=smoke k6-load-test.js
// 2. Load Test (normal):     k6 run --env TEST_TYPE=load k6-load-test.js  
// 3. Stress Test (berat):    k6 run --env TEST_TYPE=stress k6-load-test.js
// 4. Spike Test (mendadak):  k6 run --env TEST_TYPE=spike k6-load-test.js
// ========================================

// === CONFIGURATION ===
const BASE_URL = __ENV.BASE_URL || 'https://shortlinkmu.space';
const TEST_TYPE = __ENV.TEST_TYPE || 'smoke';

// Test user (buat di database production terlebih dahulu)
const TEST_EMAIL = __ENV.TEST_EMAIL || 'loadtest@shortlinkmu.com';
const TEST_PASSWORD = __ENV.TEST_PASSWORD || 'LoadTest123!';

// === CUSTOM METRICS ===
const errorRate = new Rate('errors');
const requestsPerSecond = new Counter('requests_per_second');
const apiLatency = new Trend('api_latency', true);

// === TEST SCENARIOS ===
const scenarios = {
    // Smoke: 1 user, 1 menit - basic test
    smoke: {
        executor: 'constant-vus',
        vus: 1,
        duration: '1m',
    },

    // Load: Naik bertahap sampai 50 users
    load: {
        executor: 'ramping-vus',
        startVUs: 0,
        stages: [
            { duration: '2m', target: 10 },   // Warm up
            { duration: '5m', target: 50 },   // Ramp to 50 users
            { duration: '5m', target: 50 },   // Stay at 50
            { duration: '2m', target: 0 },    // Cool down
        ],
        gracefulRampDown: '30s',
    },

    // Stress: Naik sampai breaking point
    stress: {
        executor: 'ramping-vus',
        startVUs: 0,
        stages: [
            { duration: '2m', target: 50 },   // Quick ramp
            { duration: '3m', target: 100 },  // Push to 100
            { duration: '3m', target: 150 },  // Push to 150
            { duration: '3m', target: 200 },  // Push to 200 (breaking point?)
            { duration: '2m', target: 0 },    // Recovery
        ],
        gracefulRampDown: '30s',
    },

    // Spike: Lonjakan mendadak
    spike: {
        executor: 'ramping-vus',
        startVUs: 0,
        stages: [
            { duration: '1m', target: 10 },   // Normal load
            { duration: '10s', target: 200 }, // SPIKE!
            { duration: '2m', target: 200 },  // Maintain spike
            { duration: '10s', target: 10 },  // Drop back
            { duration: '2m', target: 10 },   // Recovery
            { duration: '30s', target: 0 },   // Cool down
        ],
        gracefulRampDown: '10s',
    },
};

// === OPTIONS ===
export const options = {
    scenarios: {
        default: scenarios[TEST_TYPE] || scenarios.smoke,
    },
    thresholds: {
        http_req_duration: ['p(95)<500', 'p(99)<1000'],  // 95% < 500ms, 99% < 1s
        errors: ['rate<0.1'],                            // Error < 10%
        http_req_failed: ['rate<0.05'],                  // Failed < 5%
    },
};

// === HELPER FUNCTIONS ===
function getHeaders(token = null) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }
    return { headers };
}

// === MAIN TEST FUNCTION ===
export default function () {
    let authToken = null;

    // ==========================================
    // 1. PUBLIC ENDPOINTS (No Auth)
    // ==========================================
    group('Public Endpoints', function () {

        // Health check
        let start = Date.now();
        let res = http.get(`${BASE_URL}/up`);
        apiLatency.add(Date.now() - start);

        check(res, { 'health OK': (r) => r.status === 200 });
        errorRate.add(res.status !== 200);
        requestsPerSecond.add(1);

        sleep(0.1);

        // Check alias
        start = Date.now();
        res = http.get(`${BASE_URL}/api/check-alias/test${Date.now()}`, getHeaders());
        apiLatency.add(Date.now() - start);

        check(res, { 'check alias OK': (r) => r.status === 200 });
        errorRate.add(res.status !== 200);
        requestsPerSecond.add(1);

        sleep(0.1);

        // Create guest link (rate limited)
        if (Math.random() < 0.1) { // Only 10% of iterations
            start = Date.now();
            res = http.post(`${BASE_URL}/api/links`, JSON.stringify({
                original_url: `https://example.com/test-${Date.now()}`,
                is_guest: true,
            }), getHeaders());
            apiLatency.add(Date.now() - start);

            check(res, { 'create link OK': (r) => r.status === 201 || r.status === 429 });
            requestsPerSecond.add(1);
        }
    });

    sleep(0.2);

    // ==========================================
    // 2. AUTHENTICATION
    // ==========================================
    group('Authentication', function () {
        const start = Date.now();
        const res = http.post(`${BASE_URL}/api/login`, JSON.stringify({
            email: TEST_EMAIL,
            password: TEST_PASSWORD,
        }), getHeaders());
        apiLatency.add(Date.now() - start);

        const success = check(res, {
            'login status 200': (r) => r.status === 200,
            'login has token': (r) => {
                try {
                    const body = JSON.parse(r.body);
                    return body.data && body.data.token;
                } catch { return false; }
            },
        });

        if (success) {
            try {
                authToken = JSON.parse(res.body).data.token;
            } catch { }
        }

        errorRate.add(!success);
        requestsPerSecond.add(1);
    });

    // Skip auth endpoints if login failed
    if (!authToken) {
        sleep(0.5);
        return;
    }

    sleep(0.2);

    // ==========================================
    // 3. DASHBOARD ENDPOINTS (Heavy)
    // ==========================================
    group('Dashboard (Heavy)', function () {

        // Dashboard overview
        let start = Date.now();
        let res = http.get(`${BASE_URL}/api/dashboard/overview`, getHeaders(authToken));
        apiLatency.add(Date.now() - start);

        check(res, { 'overview OK': (r) => r.status === 200 });
        errorRate.add(res.status !== 200);
        requestsPerSecond.add(1);

        sleep(0.1);

        // Dashboard trends
        start = Date.now();
        res = http.get(`${BASE_URL}/api/dashboard/trends?period=weekly`, getHeaders(authToken));
        apiLatency.add(Date.now() - start);

        check(res, { 'trends OK': (r) => r.status === 200 });
        errorRate.add(res.status !== 200);
        requestsPerSecond.add(1);
    });

    sleep(0.2);

    // ==========================================
    // 4. ANALYTICS ENDPOINTS
    // ==========================================
    group('Analytics', function () {
        const endpoints = [
            '/api/dashboard/summary/earnings?range=month',
            '/api/dashboard/summary/clicks?range=month',
            '/api/analytics/top-countries',
            '/api/analytics/top-referrers',
        ];

        for (const endpoint of endpoints) {
            const start = Date.now();
            const res = http.get(`${BASE_URL}${endpoint}`, getHeaders(authToken));
            apiLatency.add(Date.now() - start);

            check(res, { [`${endpoint} OK`]: (r) => r.status === 200 });
            errorRate.add(res.status !== 200);
            requestsPerSecond.add(1);

            sleep(0.05);
        }
    });

    sleep(0.2);

    // ==========================================
    // 5. USER DATA ENDPOINTS
    // ==========================================
    group('User Data', function () {

        // User profile
        let start = Date.now();
        let res = http.get(`${BASE_URL}/api/user/me`, getHeaders(authToken));
        apiLatency.add(Date.now() - start);

        check(res, { 'user me OK': (r) => r.status === 200 });
        errorRate.add(res.status !== 200);
        requestsPerSecond.add(1);

        sleep(0.1);

        // User links
        start = Date.now();
        res = http.get(`${BASE_URL}/api/links?per_page=10`, getHeaders(authToken));
        apiLatency.add(Date.now() - start);

        check(res, { 'links OK': (r) => r.status === 200 });
        errorRate.add(res.status !== 200);
        requestsPerSecond.add(1);
    });

    // Random delay between iterations
    sleep(Math.random() * 0.5 + 0.3);
}

// === SUMMARY OUTPUT ===
export function handleSummary(data) {
    const duration = data.metrics.http_req_duration?.values || {};
    const errors = data.metrics.errors?.values || {};

    const summary = `
========================================
📊 LOAD TEST RESULTS - ${TEST_TYPE.toUpperCase()}
========================================
Target: ${BASE_URL}
Type: ${TEST_TYPE}

📈 REQUESTS
   Total: ${data.metrics.http_reqs?.values?.count || 0}
   Rate: ${(data.metrics.http_reqs?.values?.rate || 0).toFixed(2)}/s

⏱️ RESPONSE TIME
   Average: ${(duration.avg || 0).toFixed(2)}ms
   Median: ${(duration.med || 0).toFixed(2)}ms
   P90: ${(duration['p(90)'] || 0).toFixed(2)}ms
   P95: ${(duration['p(95)'] || 0).toFixed(2)}ms
   P99: ${(duration['p(99)'] || 0).toFixed(2)}ms
   Min: ${(duration.min || 0).toFixed(2)}ms
   Max: ${(duration.max || 0).toFixed(2)}ms

❌ ERRORS
   Error Rate: ${((errors.rate || 0) * 100).toFixed(2)}%
   Failed Requests: ${data.metrics.http_req_failed?.values?.passes || 0}

🎯 THRESHOLDS
   p95 < 500ms: ${duration['p(95)'] < 500 ? '✅ PASS' : '❌ FAIL'}
   p99 < 1000ms: ${duration['p(99)'] < 1000 ? '✅ PASS' : '❌ FAIL'}
   Error < 10%: ${(errors.rate || 0) < 0.1 ? '✅ PASS' : '❌ FAIL'}
========================================
`;

    console.log(summary);

    return {
        'stdout': summary,
        'summary.json': JSON.stringify(data, null, 2),
    };
}
