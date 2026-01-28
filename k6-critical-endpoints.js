/*
 * K6 Performance Test Script untuk shortlinkmu.space
 * 
 * Jalankan di VPS console dengan:
 * k6 run k6-critical-endpoints.js
 * 
 * Atau dengan opsi:
 * k6 run k6-critical-endpoints.js --vus 50 --duration 1m
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// Custom metrics
const errorRate = new Rate('errors');
const linkShowDuration = new Trend('link_show_duration');
const loginDuration = new Trend('login_duration');

// Konfigurasi Test
export const options = {
    stages: [
        { duration: '10s', target: 5 },    // Warm up
        { duration: '30s', target: 20 },   // Ramp up
        { duration: '1m', target: 50 },    // Sustain load
        { duration: '10s', target: 0 },    // Cool down
    ],
    thresholds: {
        http_req_duration: ['p(95)<1000'],  // 95% response < 1 detik
        http_req_failed: ['rate<0.1'],       // Error rate < 10%
        errors: ['rate<0.1'],
    },
};

// BASE URL - Ganti jika perlu
const BASE_URL = __ENV.BASE_URL || 'http://shortlinkmu.space';

// Test credentials (ganti dengan akun test)
const TEST_USER = {
    email: __ENV.TEST_EMAIL || 'test@example.com',
    password: __ENV.TEST_PASSWORD || 'password123',
};

// Sample link codes untuk test (ganti dengan code yang ada)
const SAMPLE_CODES = ['test1', 'abc123', 'demo'];

export function setup() {
    console.log('🚀 Starting K6 Performance Test');
    console.log(`📍 Target: ${BASE_URL}`);
    console.log(`👥 Max VUs: ${options.stages.reduce((max, s) => Math.max(max, s.target), 0)}`);

    // Test koneksi
    const healthCheck = http.get(`${BASE_URL}/api/`);
    console.log(`🔗 Health Check: ${healthCheck.status}`);

    return { startTime: Date.now() };
}

export default function () {
    // ===== 1. PUBLIC ENDPOINTS =====
    group('Public Endpoints', function () {

        // Test: Show Link (Critical - High Traffic)
        const randomCode = SAMPLE_CODES[Math.floor(Math.random() * SAMPLE_CODES.length)];
        const showRes = http.get(`${BASE_URL}/api/links/${randomCode}`, {
            tags: { name: 'ShowLink' },
        });

        linkShowDuration.add(showRes.timings.duration);

        const showCheck = check(showRes, {
            'show link - status ok': (r) => [200, 302, 404].includes(r.status),
            'show link - response time < 500ms': (r) => r.timings.duration < 500,
        });
        errorRate.add(!showCheck);

        sleep(0.5);

        // Test: Check Alias
        const aliasRes = http.get(`${BASE_URL}/api/check-alias/testAlias${Date.now()}`, {
            tags: { name: 'CheckAlias' },
        });

        check(aliasRes, {
            'check alias - status ok': (r) => [200, 404].includes(r.status),
        });

        sleep(0.5);
    });

    // ===== 2. AUTH ENDPOINTS =====
    group('Auth Endpoints', function () {

        // Test: Login
        const loginRes = http.post(`${BASE_URL}/api/login`, JSON.stringify({
            email: TEST_USER.email,
            password: TEST_USER.password,
        }), {
            headers: { 'Content-Type': 'application/json' },
            tags: { name: 'Login' },
        });

        loginDuration.add(loginRes.timings.duration);

        const loginCheck = check(loginRes, {
            'login - status ok': (r) => [200, 401, 422].includes(r.status),
            'login - response time < 1s': (r) => r.timings.duration < 1000,
        });
        errorRate.add(!loginCheck);

        sleep(1);
    });

    // ===== 3. STRESS TEST (Optional) =====
    group('Stress Test', function () {

        // Multiple rapid requests
        for (let i = 0; i < 3; i++) {
            const code = SAMPLE_CODES[i % SAMPLE_CODES.length];
            const res = http.get(`${BASE_URL}/api/links/${code}`, {
                tags: { name: 'RapidRequest' },
            });

            check(res, {
                'rapid - no server error': (r) => r.status !== 500,
            });
        }

        sleep(1);
    });
}

export function teardown(data) {
    const duration = ((Date.now() - data.startTime) / 1000).toFixed(2);
    console.log('\n' + '='.repeat(60));
    console.log('📊 TEST COMPLETED');
    console.log('='.repeat(60));
    console.log(`⏱️  Duration: ${duration}s`);
    console.log('='.repeat(60));
}

/*
 * CARA MENJALANKAN DI VPS:
 * 
 * 1. Install K6:
 *    sudo snap install k6
 * 
 * 2. Upload file ini ke VPS atau copy-paste
 * 
 * 3. Jalankan test:
 *    k6 run k6-critical-endpoints.js
 * 
 * 4. Dengan custom settings:
 *    k6 run k6-critical-endpoints.js --vus 100 --duration 2m
 * 
 * 5. Dengan test credentials:
 *    k6 run k6-critical-endpoints.js -e TEST_EMAIL=user@email.com -e TEST_PASSWORD=pass123
 */
