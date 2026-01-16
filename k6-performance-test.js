import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ========================================
// K6 PERFORMANCE TEST - ShortlinkMu API
// ========================================
// Menguji response time semua endpoint penting
// 
// Cara menjalankan:
// 1. Install k6: https://k6.io/docs/getting-started/installation/
// 2. Jalankan: k6 run k6-performance-test.js
// 3. Untuk load test: k6 run --vus 10 --duration 30s k6-performance-test.js
// ========================================

// === KONFIGURASI ===
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000/api';

// Test dengan user yang sudah ada (ganti dengan credentials yang valid)
const TEST_USER = {
    email: __ENV.TEST_EMAIL || 'test@example.com',
    password: __ENV.TEST_PASSWORD || 'password123',
};

// Custom metrics
const errorRate = new Rate('errors');
const loginDuration = new Trend('login_duration');
const dashboardDuration = new Trend('dashboard_overview_duration');
const trendsDuration = new Trend('dashboard_trends_duration');
const analyticsDuration = new Trend('analytics_duration');

// === TEST OPTIONS ===
export const options = {
    // Skenario testing
    scenarios: {
        // Smoke test - cek basic functionality
        smoke: {
            executor: 'constant-vus',
            vus: 1,
            duration: '30s',
            gracefulStop: '5s',
        },
        // Uncomment untuk load test
        // load: {
        //     executor: 'ramping-vus',
        //     startVUs: 0,
        //     stages: [
        //         { duration: '30s', target: 10 },  // ramp up
        //         { duration: '1m', target: 10 },   // stay at 10
        //         { duration: '30s', target: 0 },   // ramp down
        //     ],
        //     gracefulRampDown: '10s',
        // },
    },
    thresholds: {
        http_req_duration: ['p(95)<500'], // 95% requests < 500ms
        errors: ['rate<0.1'],              // Error rate < 10%
    },
};

// === HELPER FUNCTIONS ===
function getAuthHeaders(token) {
    return {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
        },
    };
}

function getPublicHeaders() {
    return {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };
}

// === MAIN TEST ===
export default function() {
    let authToken = null;

    // ==========================================
    // 1. PUBLIC ENDPOINTS (No Auth Required)
    // ==========================================
    group('🌐 Public Endpoints', function() {
        
        // 1.1 Health Check
        group('Health Check', function() {
            const res = http.get(`${BASE_URL.replace('/api', '')}/up`);
            check(res, {
                'health is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 1.2 Access Settings (Landing page)
        group('GET /settings/access', function() {
            const res = http.get(`${BASE_URL}/settings/access`, getPublicHeaders());
            check(res, {
                'access settings is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 1.3 Check Alias Availability
        group('GET /check-alias/{alias}', function() {
            const res = http.get(`${BASE_URL}/check-alias/testlink123`, getPublicHeaders());
            check(res, {
                'check alias is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 1.4 Referral Info (Public)
        group('GET /referral/info', function() {
            const res = http.get(`${BASE_URL}/referral/info?code=TESTCODE`, getPublicHeaders());
            check(res, {
                'referral info status ok': (r) => r.status === 200 || r.status === 404,
            });
        });
    });

    sleep(0.5);

    // ==========================================
    // 2. AUTHENTICATION
    // ==========================================
    group('🔐 Authentication', function() {
        
        // 2.1 Login
        group('POST /login', function() {
            const startTime = Date.now();
            const res = http.post(`${BASE_URL}/login`, JSON.stringify({
                email: TEST_USER.email,
                password: TEST_USER.password,
            }), getPublicHeaders());
            
            loginDuration.add(Date.now() - startTime);
            
            const success = check(res, {
                'login is 200': (r) => r.status === 200,
                'login has token': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        return body.data && body.data.token;
                    } catch {
                        return false;
                    }
                },
            });

            if (success) {
                try {
                    const body = JSON.parse(res.body);
                    authToken = body.data.token;
                } catch (e) {
                    console.log('Failed to parse login response');
                }
            }
            errorRate.add(!success);
        });
    });

    // Skip authenticated tests if login failed
    if (!authToken) {
        console.log('⚠️ Login failed, skipping authenticated tests');
        return;
    }

    sleep(0.5);

    // ==========================================
    // 3. USER ENDPOINTS (Auth Required)
    // ==========================================
    group('👤 User Endpoints', function() {
        
        // 3.1 Get Current User
        group('GET /user/me', function() {
            const res = http.get(`${BASE_URL}/user/me`, getAuthHeaders(authToken));
            check(res, {
                'user me is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 3.2 User Stats (Header)
        group('GET /user/stats', function() {
            const res = http.get(`${BASE_URL}/user/stats`, getAuthHeaders(authToken));
            check(res, {
                'user stats is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 3.3 User Levels
        group('GET /user/levels', function() {
            const res = http.get(`${BASE_URL}/user/levels`, getAuthHeaders(authToken));
            check(res, {
                'user levels is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 3.4 Login History
        group('GET /user/login-history', function() {
            const res = http.get(`${BASE_URL}/user/login-history`, getAuthHeaders(authToken));
            check(res, {
                'login history is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });
    });

    sleep(0.5);

    // ==========================================
    // 4. DASHBOARD ENDPOINTS (🔥 OPTIMIZED)
    // ==========================================
    group('📊 Dashboard Endpoints (Optimized)', function() {
        
        // 4.1 Dashboard Overview
        group('GET /dashboard/overview', function() {
            const startTime = Date.now();
            const res = http.get(`${BASE_URL}/dashboard/overview`, getAuthHeaders(authToken));
            dashboardDuration.add(Date.now() - startTime);
            
            check(res, {
                'dashboard overview is 200': (r) => r.status === 200,
                'dashboard has summary': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        return body.data && body.data.summary;
                    } catch {
                        return false;
                    }
                },
            });
            errorRate.add(res.status !== 200);
        });

        // 4.2 Dashboard Trends (🔥 OPTIMIZED - Uses aggregate table)
        group('GET /dashboard/trends', function() {
            const startTime = Date.now();
            const res = http.get(`${BASE_URL}/dashboard/trends?period=weekly`, getAuthHeaders(authToken));
            trendsDuration.add(Date.now() - startTime);
            
            check(res, {
                'trends is 200': (r) => r.status === 200,
                'trends has correct structure': (r) => {
                    try {
                        const body = JSON.parse(r.body);
                        return body.data && body.data.period && body.data.trends !== undefined;
                    } catch {
                        return false;
                    }
                },
            });
            errorRate.add(res.status !== 200);
        });

        // 4.3 Dashboard Messages
        group('GET /dashboard/messages', function() {
            const res = http.get(`${BASE_URL}/dashboard/messages`, getAuthHeaders(authToken));
            check(res, {
                'dashboard messages is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });
    });

    sleep(0.5);

    // ==========================================
    // 5. ANALYTICS ENDPOINTS (🔥 OPTIMIZED)
    // ==========================================
    group('📈 Analytics Endpoints (Optimized)', function() {
        
        // 5.1 Earnings Summary
        group('GET /dashboard/summary/earnings', function() {
            const res = http.get(`${BASE_URL}/dashboard/summary/earnings?range=month`, getAuthHeaders(authToken));
            check(res, {
                'earnings is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 5.2 Clicks Summary
        group('GET /dashboard/summary/clicks', function() {
            const res = http.get(`${BASE_URL}/dashboard/summary/clicks?range=month`, getAuthHeaders(authToken));
            check(res, {
                'clicks is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 5.3 Referrals Summary
        group('GET /dashboard/summary/referrals', function() {
            const res = http.get(`${BASE_URL}/dashboard/summary/referrals?range=month`, getAuthHeaders(authToken));
            check(res, {
                'referrals is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 5.4 Average CPM
        group('GET /dashboard/summary/cpm', function() {
            const res = http.get(`${BASE_URL}/dashboard/summary/cpm?range=month`, getAuthHeaders(authToken));
            check(res, {
                'cpm is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 5.5 Analytics Chart Data
        group('GET /dashboard/analytics', function() {
            const startTime = Date.now();
            const res = http.get(`${BASE_URL}/dashboard/analytics?metric=earnings&range=month`, getAuthHeaders(authToken));
            analyticsDuration.add(Date.now() - startTime);
            
            check(res, {
                'analytics is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 5.6 Monthly Performance
        group('GET /analytics/monthly-performance', function() {
            const res = http.get(`${BASE_URL}/analytics/monthly-performance?range=6months`, getAuthHeaders(authToken));
            check(res, {
                'monthly performance is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 5.7 Top Countries (🔥 Uses aggregate table)
        group('GET /analytics/top-countries', function() {
            const res = http.get(`${BASE_URL}/analytics/top-countries`, getAuthHeaders(authToken));
            check(res, {
                'top countries is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 5.8 Top Referrers (🔥 Uses aggregate table)
        group('GET /analytics/top-referrers', function() {
            const res = http.get(`${BASE_URL}/analytics/top-referrers`, getAuthHeaders(authToken));
            check(res, {
                'top referrers is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });
    });

    sleep(0.5);

    // ==========================================
    // 6. LINKS ENDPOINTS
    // ==========================================
    group('🔗 Links Endpoints', function() {
        
        // 6.1 Get User Links (Paginated)
        group('GET /links', function() {
            const res = http.get(`${BASE_URL}/links?per_page=10`, getAuthHeaders(authToken));
            check(res, {
                'links is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 6.2 Get Link Settings
        group('GET /settings/link', function() {
            const res = http.get(`${BASE_URL}/settings/link`, getAuthHeaders(authToken));
            check(res, {
                'link settings is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 6.3 Ad Levels
        group('GET /ad-levels', function() {
            const res = http.get(`${BASE_URL}/ad-levels`, getAuthHeaders(authToken));
            check(res, {
                'ad levels is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });
    });

    sleep(0.5);

    // ==========================================
    // 7. PAYMENT & WITHDRAWAL ENDPOINTS
    // ==========================================
    group('💰 Payment Endpoints', function() {
        
        // 7.1 Get Payment Methods
        group('GET /payment-methods', function() {
            const res = http.get(`${BASE_URL}/payment-methods`, getAuthHeaders(authToken));
            check(res, {
                'payment methods is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 7.2 Get Payment Templates
        group('GET /payment-templates', function() {
            const res = http.get(`${BASE_URL}/payment-templates`, getAuthHeaders(authToken));
            check(res, {
                'payment templates is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 7.3 Get Withdrawals
        group('GET /withdrawals', function() {
            const res = http.get(`${BASE_URL}/withdrawals`, getAuthHeaders(authToken));
            check(res, {
                'withdrawals is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 7.4 Get Referrals
        group('GET /referrals', function() {
            const res = http.get(`${BASE_URL}/referrals`, getAuthHeaders(authToken));
            check(res, {
                'referrals is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });
    });

    sleep(0.5);

    // ==========================================
    // 8. NOTIFICATIONS ENDPOINTS
    // ==========================================
    group('🔔 Notification Endpoints', function() {
        
        // 8.1 Get Notifications
        group('GET /notifications', function() {
            const res = http.get(`${BASE_URL}/notifications`, getAuthHeaders(authToken));
            check(res, {
                'notifications is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });

        // 8.2 Get Unread Count
        group('GET /notifications/unread', function() {
            const res = http.get(`${BASE_URL}/notifications/unread`, getAuthHeaders(authToken));
            check(res, {
                'unread count is 200': (r) => r.status === 200,
            });
            errorRate.add(res.status !== 200);
        });
    });

    sleep(1);
}

// === SUMMARY HANDLER ===
export function handleSummary(data) {
    const summary = {
        '📊 Total Requests': data.metrics.http_reqs.values.count,
        '⏱️ Avg Response Time': `${data.metrics.http_req_duration.values.avg.toFixed(2)}ms`,
        '⏱️ 95th Percentile': `${data.metrics.http_req_duration.values['p(95)'].toFixed(2)}ms`,
        '❌ Error Rate': `${(data.metrics.errors.values.rate * 100).toFixed(2)}%`,
    };

    if (data.metrics.dashboard_overview_duration) {
        summary['📊 Dashboard Overview Avg'] = `${data.metrics.dashboard_overview_duration.values.avg.toFixed(2)}ms`;
    }
    if (data.metrics.dashboard_trends_duration) {
        summary['📈 Trends (Optimized) Avg'] = `${data.metrics.dashboard_trends_duration.values.avg.toFixed(2)}ms`;
    }
    if (data.metrics.analytics_duration) {
        summary['📉 Analytics Avg'] = `${data.metrics.analytics_duration.values.avg.toFixed(2)}ms`;
    }

    console.log('\n========================================');
    console.log('📋 PERFORMANCE TEST SUMMARY');
    console.log('========================================');
    Object.entries(summary).forEach(([key, value]) => {
        console.log(`${key}: ${value}`);
    });
    console.log('========================================\n');

    return {
        stdout: JSON.stringify(summary, null, 2),
    };
}
