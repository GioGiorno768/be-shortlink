# K6 Performance Testing - ShortlinkMu Backend API

Dokumentasi untuk menjalankan performance testing menggunakan k6 di local environment.

## 📋 Prerequisites

1. **K6 sudah terinstall**
   ```bash
   sudo snap install k6
   ```

2. **Laravel server berjalan**
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000 --env=testing
   ```

3. **Database seeded** dengan test data
   - User: `test@example.com` / `password123`
   - Link codes: `abc123`, `demo`, `test1`

## 🚀 Quick Start

### Cara Termudah (Recommended)

1. **Start test server:**
   ```bash
   ./start-test-server.sh
   ```

2. **Buka terminal baru, run test:**
   ```bash
   ./run-k6-test.sh
   ```

### Cara Manual

1. **Start Laravel server dengan testing environment:**
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000 --env=testing
   ```

2. **Run k6 test:**
   ```bash
   k6 run k6-critical-endpoints.js \
     -e BASE_URL=http://localhost:8000 \
     -e TEST_EMAIL=test@example.com \
     -e TEST_PASSWORD=password123
   ```

## 📁 File Structure

```
be-shortlink/
├── k6-critical-endpoints.js    # K6 test script
├── .env.testing                # Environment config untuk testing
├── run-k6-test.sh             # Helper script untuk run test
├── start-test-server.sh       # Helper script untuk start server
└── K6-TESTING.md              # Dokumentasi ini
```

## ⚙️ Konfigurasi

### Environment Variables

File `.env.testing` sudah dikonfigurasi untuk local testing dengan:
- `APP_ENV=testing`
- `APP_URL=http://localhost:8000`
- `SESSION_DRIVER=file` (tidak perlu Redis)
- `QUEUE_CONNECTION=sync`
- `CACHE_STORE=file`

### K6 Test Configuration

Default test stages di `k6-critical-endpoints.js`:
```javascript
stages: [
    { duration: '10s', target: 5 },    // Warm up
    { duration: '30s', target: 20 },   // Ramp up
    { duration: '1m', target: 50 },    // Sustain load
    { duration: '10s', target: 0 },    // Cool down
]
```

### Custom Configuration

Anda bisa override dengan command line:
```bash
k6 run k6-critical-endpoints.js \
  --vus 100 \
  --duration 2m \
  -e BASE_URL=http://localhost:8000
```

## 📊 Endpoints yang Di-test

### 1. Public Endpoints
- `GET /api/links/{code}` - Show link (High traffic endpoint)
- `GET /api/check-alias/{alias}` - Check alias availability

### 2. Auth Endpoints
- `POST /api/login` - User login

### 3. Stress Test
- Multiple rapid requests ke show link endpoint

## 🎯 Threshold & Acceptance Criteria

```javascript
thresholds: {
    http_req_duration: ['p(95)<1000'],  // 95% response < 1 detik
    http_req_failed: ['rate<0.1'],       // Error rate < 10%
    errors: ['rate<0.1'],
}
```

## 📈 Membaca Hasil Test

### Exit Codes
- `0` - Semua threshold tercapai ✅
- `99` - Ada threshold yang gagal ❌

### Key Metrics
- **http_req_duration** - Response time
  - `avg` - Average response time
  - `p(95)` - 95th percentile (95% request lebih cepat dari ini)
  - `max` - Maximum response time

- **http_req_failed** - Percentage of failed requests

- **iterations** - Berapa kali complete test cycle

- **vus** - Virtual Users (concurrent users)

### Sample Output
```
✓ show link - status ok
✓ show link - response time < 500ms
✓ login - status ok
✗ login - response time < 1s
  ↳  50% — ✓ 63 / ✗ 63

http_req_duration..............: avg=234ms  min=25ms  med=189ms  max=1.2s  p(95)=687ms
http_req_failed................: 0.12%  3 out of 766
```

## 🔧 Troubleshooting

### Issue: Server tidak berjalan
```bash
# Check apakah ada process yang running
ps aux | grep "php artisan serve"

# Start server manually
php artisan serve --host=0.0.0.0 --port=8000 --env=testing
```

### Issue: Connection refused
```bash
# Test server dengan curl
curl http://localhost:8000/api/

# Check port availability
lsof -i :8000
```

### Issue: APP_KEY error
```bash
# Generate new APP_KEY untuk testing
php artisan key:generate --env=testing
```

### Issue: Database connection error
```bash
# Check database connection
php artisan tinker --execute="DB::connection()->getPdo();"

# Test query
php artisan tinker --execute="echo User::count();"
```

## 💡 Tips untuk Testing

### 1. Warm Up Database
Sebelum run test, warm up database cache:
```bash
curl http://localhost:8000/api/links/abc123
curl http://localhost:8000/api/links/demo
curl http://localhost:8000/api/links/test1
```

### 2. Monitor Resource Usage
```bash
# Terminal 1: Start server
./start-test-server.sh

# Terminal 2: Monitor resources
watch -n 1 "ps aux | grep php"

# Terminal 3: Run test
./run-k6-test.sh
```

### 3. Clear Cache Before Test
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

### 4. Test Berbagai Scenario

**Light Load (Baseline):**
```bash
k6 run k6-critical-endpoints.js --vus 10 --duration 30s
```

**Medium Load:**
```bash
k6 run k6-critical-endpoints.js --vus 50 --duration 1m
```

**Heavy Load (Stress Test):**
```bash
k6 run k6-critical-endpoints.js --vus 100 --duration 2m
```

**Spike Test:**
```javascript
export const options = {
    stages: [
        { duration: '10s', target: 10 },
        { duration: '30s', target: 100 },  // Sudden spike
        { duration: '10s', target: 10 },
    ],
};
```

## 📊 Generating Reports

### HTML Report
```bash
k6 run k6-critical-endpoints.js --out json=test-results.json
```

### Send to Cloud (k6 Cloud)
```bash
k6 login cloud
k6 run k6-critical-endpoints.js --out cloud
```

### InfluxDB + Grafana
```bash
k6 run k6-critical-endpoints.js --out influxdb=http://localhost:8086/k6
```

## 🎯 Performance Targets

### Current (Development Server)
- p95: ~10 seconds ❌
- Error rate: ~93% ❌
- Throughput: ~6 req/s

### Target (Production-ready)
- p95: < 500ms ✅
- Error rate: < 1% ✅
- Throughput: > 100 req/s ✅

## 🔗 Resources

- [K6 Documentation](https://k6.io/docs/)
- [K6 Test Types](https://k6.io/docs/test-types/introduction/)
- [Performance Test Report](../../../.gemini/antigravity/brain/1b5cc2a0-e7f2-4be4-ac5f-f2970cb66ef9/k6-performance-report.md)

## 📝 Notes

> **⚠️ Important**: Hasil test di development server (`php artisan serve`) akan berbeda dengan production server (Nginx + PHP-FPM). Development server NOT recommended untuk production load.

> **💡 Tip**: Untuk hasil yang lebih akurat, test menggunakan production-like setup dengan Nginx atau Apache.
