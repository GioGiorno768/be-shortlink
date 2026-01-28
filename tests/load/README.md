# k6 Load Testing Setup Guide

## 📦 Installation

### Windows (via winget)

```powershell
winget install k6 --source winget
```

### Windows (via Chocolatey)

```powershell
choco install k6
```

### Manual Download

Download from: https://github.com/grafana/k6/releases

---

## 🚀 Quick Start

### 1. Quick Test (30 seconds, 10 users)

```bash
cd tests/load
k6 run quick-test.js
```

### 2. Full Load Test (~6 minutes, up to 100 users)

```bash
k6 run load-test.js
```

### 3. Stress Test (~38 minutes, up to 400 users)

```bash
k6 run stress-test.js
```

### 4. Spike Test (~8 minutes, 10 -> 500 users)

```bash
k6 run spike-test.js
```

---

## ⚙️ Configuration

**Before running tests, update these in the test files:**

1. `BASE_URL` - Your API URL (default: http://127.0.0.1:8000)
2. `TEST_SHORTCODES` - Array of existing shortcodes in your DB

---

## 📊 Output Options

### Save JSON Report

```bash
k6 run load-test.js --out json=results.json
```

### Custom VUs and Duration

```bash
k6 run quick-test.js --vus 50 --duration 2m
```

### Web Dashboard (k6 Cloud)

```bash
k6 run --out cloud load-test.js
```

---

## 📈 Understanding Metrics

| Metric            | Description     | Target          |
| ----------------- | --------------- | --------------- |
| http_req_duration | Response time   | p95 < 500ms     |
| http_req_failed   | Error rate      | < 5%            |
| http_reqs         | Requests/second | Higher = better |
| vus               | Virtual users   | Configurable    |

---

## 🎯 Test Scenarios

| Test        | Purpose       | Duration | Max VUs |
| ----------- | ------------- | -------- | ------- |
| quick-test  | Sanity check  | 30s      | 10      |
| load-test   | Normal load   | 6m       | 100     |
| stress-test | Find limits   | 38m      | 400     |
| spike-test  | Traffic surge | 8m       | 500     |

---

## 🔧 Troubleshooting

### "Connection refused"

-   Ensure Laravel server is running: `php artisan serve`

### "404 Not Found"

-   Update TEST_SHORTCODES with valid shortcodes from your database

### High error rate

-   Check Laravel logs: `storage/logs/laravel.log`
-   Monitor MySQL: `SHOW PROCESSLIST;`
