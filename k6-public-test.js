import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ========================================
// K6 PERFORMANCE TEST - Public Endpoints Only
// ========================================
// Version yang bisa berjalan tanpa database/auth
// 
// Cara menjalankan:
// k6 run k6-public-test.js
// ========================================

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// Custom metrics
const errorRate = new Rate('errors');
const healthDuration = new Trend('health_check_duration');
const aliasDuration = new Trend('check_alias_duration');

export const options = {
    scenarios: {
        smoke: {
            executor: 'constant-vus',
            vus: 1,
            duration: '15s',
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<200'], // 95% < 200ms
        errors: ['rate<0.2'],
    },
};

export default function () {

    group('🌐 Health Check', function () {
        const startTime = Date.now();
        const res = http.get(`${BASE_URL}/up`);
        healthDuration.add(Date.now() - startTime);

        const success = check(res, {
            'health is 200': (r) => r.status === 200,
        });
        errorRate.add(!success);
    });

    sleep(0.2);

    group('🔍 Check Alias', function () {
        const startTime = Date.now();
        const res = http.get(`${BASE_URL}/api/check-alias/testlink123`);
        aliasDuration.add(Date.now() - startTime);

        check(res, {
            'check alias responds': (r) => r.status === 200 || r.status === 401,
        });
    });

    sleep(0.2);

    group('📡 API Ping', function () {
        const res = http.get(`${BASE_URL}/api/referral/info?code=ABC123`);
        check(res, {
            'referral info responds': (r) => r.status === 200 || r.status === 404,
        });
    });

    sleep(0.5);
}

export function handleSummary(data) {
    console.log('\n========================================');
    console.log('📋 PUBLIC ENDPOINTS TEST SUMMARY');
    console.log('========================================');
    console.log(`📊 Total Requests: ${data.metrics.http_reqs.values.count}`);
    console.log(`⏱️ Avg Response Time: ${data.metrics.http_req_duration.values.avg.toFixed(2)}ms`);
    console.log(`⏱️ 95th Percentile: ${data.metrics.http_req_duration.values['p(95)'].toFixed(2)}ms`);
    console.log(`⏱️ Min: ${data.metrics.http_req_duration.values.min.toFixed(2)}ms`);
    console.log(`⏱️ Max: ${data.metrics.http_req_duration.values.max.toFixed(2)}ms`);
    console.log(`❌ Error Rate: ${(data.metrics.errors.values.rate * 100).toFixed(2)}%`);

    if (data.metrics.health_check_duration) {
        console.log(`🏥 Health Check Avg: ${data.metrics.health_check_duration.values.avg.toFixed(2)}ms`);
    }
    if (data.metrics.check_alias_duration) {
        console.log(`🔍 Check Alias Avg: ${data.metrics.check_alias_duration.values.avg.toFixed(2)}ms`);
    }

    console.log('========================================\n');

    return {};
}
