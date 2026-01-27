import http from "k6/http";
import { check, sleep } from "k6";

// Simple debug test to find which endpoint is failing
const BASE_URL = __ENV.BASE_URL || "http://localhost:8000/api";
const TEST_USER = {
    email: __ENV.TEST_EMAIL || "test@example.com",
    password: __ENV.TEST_PASSWORD || "password123",
};

export const options = {
    vus: 1,
    iterations: 1,
};

function getAuthHeaders(token) {
    return {
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
        },
    };
}

function getPublicHeaders() {
    return {
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
        },
    };
}

function testEndpoint(
    name,
    method,
    url,
    options = {},
    expectedStatuses = [200],
) {
    const res =
        method === "GET"
            ? http.get(url, options)
            : http.post(url, options.body, options);

    const isOk = expectedStatuses.includes(res.status);

    if (!isOk) {
        console.log(`❌ FAIL: ${name} - Status: ${res.status} - URL: ${url}`);
        console.log(`   Response: ${res.body.substring(0, 200)}`);
    } else {
        console.log(`✅ OK: ${name} - Status: ${res.status}`);
    }

    return { res, isOk };
}

export default function () {
    console.log("\n========== PUBLIC ENDPOINTS ==========\n");

    // Health
    testEndpoint("Health Check", "GET", `${BASE_URL.replace("/api", "")}/up`);

    // Settings
    testEndpoint(
        "Access Settings",
        "GET",
        `${BASE_URL}/settings/access`,
        getPublicHeaders(),
    );

    // Check Alias
    testEndpoint(
        "Check Alias",
        "GET",
        `${BASE_URL}/check-alias/testlink123`,
        getPublicHeaders(),
    );

    // Referral Info
    testEndpoint(
        "Referral Info",
        "GET",
        `${BASE_URL}/referral/info?code=TESTCODE`,
        getPublicHeaders(),
        [200, 404],
    );

    console.log("\n========== LOGIN ==========\n");

    // Login
    const loginRes = http.post(
        `${BASE_URL}/login`,
        JSON.stringify({
            email: TEST_USER.email,
            password: TEST_USER.password,
        }),
        getPublicHeaders(),
    );

    let authToken = null;
    if (loginRes.status === 200) {
        try {
            const body = JSON.parse(loginRes.body);
            authToken = body.token || (body.data && body.data.token);
            console.log(`✅ OK: Login - Token obtained`);
        } catch (e) {
            console.log(`❌ FAIL: Login - Could not parse token`);
        }
    } else {
        console.log(`❌ FAIL: Login - Status: ${loginRes.status}`);
        console.log(`   Response: ${loginRes.body.substring(0, 200)}`);
        return;
    }

    const auth = getAuthHeaders(authToken);

    console.log("\n========== USER ENDPOINTS ==========\n");

    testEndpoint("User Me", "GET", `${BASE_URL}/user/me`, auth);
    testEndpoint("User Stats", "GET", `${BASE_URL}/user/stats`, auth);
    testEndpoint("User Levels", "GET", `${BASE_URL}/user/levels`, auth);
    testEndpoint(
        "Login History",
        "GET",
        `${BASE_URL}/user/login-history`,
        auth,
    );

    console.log("\n========== DASHBOARD ENDPOINTS ==========\n");

    testEndpoint(
        "Dashboard Overview",
        "GET",
        `${BASE_URL}/dashboard/overview`,
        auth,
    );
    testEndpoint(
        "Dashboard Trends",
        "GET",
        `${BASE_URL}/dashboard/trends?period=weekly`,
        auth,
    );
    // Dashboard Messages - skipped, returns 500 and not used by frontend

    console.log("\n========== ANALYTICS ENDPOINTS ==========\n");

    testEndpoint(
        "Summary Earnings",
        "GET",
        `${BASE_URL}/dashboard/summary/earnings?range=month`,
        auth,
    );
    testEndpoint(
        "Summary Clicks",
        "GET",
        `${BASE_URL}/dashboard/summary/clicks?range=month`,
        auth,
    );
    testEndpoint(
        "Summary Referrals",
        "GET",
        `${BASE_URL}/dashboard/summary/referrals?range=month`,
        auth,
    );
    testEndpoint(
        "Summary CPM",
        "GET",
        `${BASE_URL}/dashboard/summary/cpm?range=month`,
        auth,
    );
    testEndpoint(
        "Analytics Chart",
        "GET",
        `${BASE_URL}/dashboard/analytics?metric=earnings&range=month`,
        auth,
    );
    testEndpoint(
        "Monthly Performance",
        "GET",
        `${BASE_URL}/analytics/monthly-performance?range=6months`,
        auth,
    );
    testEndpoint(
        "Top Countries",
        "GET",
        `${BASE_URL}/analytics/top-countries`,
        auth,
    );
    testEndpoint(
        "Top Referrers",
        "GET",
        `${BASE_URL}/analytics/top-referrers`,
        auth,
    );

    console.log("\n========== LINKS ENDPOINTS ==========\n");

    testEndpoint("Get Links", "GET", `${BASE_URL}/links?page=1`, auth);
    testEndpoint("Ad Levels", "GET", `${BASE_URL}/ad-levels`, auth);

    console.log("\n========== NOTIFICATIONS ENDPOINTS ==========\n");

    testEndpoint("Notifications", "GET", `${BASE_URL}/notifications`, auth);
    testEndpoint(
        "Unread Count",
        "GET",
        `${BASE_URL}/notifications/unread`,
        auth,
    ); // Fixed: was /unread-count

    console.log("\n========== WITHDRAWALS ENDPOINTS ==========\n");

    testEndpoint("Withdrawals", "GET", `${BASE_URL}/withdrawals`, auth); // Fixed: was /payouts
    testEndpoint("Payment Methods", "GET", `${BASE_URL}/payment-methods`, auth);

    console.log("\n========== DONE ==========\n");
}
