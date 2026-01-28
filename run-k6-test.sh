#!/bin/bash

# K6 Performance Test Runner Script
# Usage: ./run-k6-test.sh [OPTIONS]

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default values
BASE_URL="${BASE_URL:-http://localhost:8000}"
TEST_EMAIL="${TEST_EMAIL:-test@example.com}"
TEST_PASSWORD="${TEST_PASSWORD:-password123}"
ENV_FILE=".env.testing"
SCRIPT_FILE="k6-critical-endpoints.js"

# Display banner
echo -e "${BLUE}╔════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   K6 Performance Test Runner          ║${NC}"
echo -e "${BLUE}║   ShortlinkMu Backend API              ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════╝${NC}"
echo ""

# Check if k6 is installed
if ! command -v k6 &> /dev/null; then
    echo -e "${RED}❌ Error: k6 is not installed${NC}"
    echo -e "${YELLOW}Install with: sudo snap install k6${NC}"
    exit 1
fi

# Check if Laravel server is running
if ! curl -s http://localhost:8000/api/ > /dev/null 2>&1; then
    echo -e "${YELLOW}⚠️  Warning: Laravel server tidak terdeteksi di localhost:8000${NC}"
    echo -e "${YELLOW}   Jalankan dulu: php artisan serve --host=0.0.0.0 --port=8000 --env=testing${NC}"
    read -p "Lanjutkan? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Display configuration
echo -e "${GREEN}📋 Konfigurasi Test:${NC}"
echo -e "   • Base URL: ${BLUE}${BASE_URL}${NC}"
echo -e "   • Test Email: ${BLUE}${TEST_EMAIL}${NC}"
echo -e "   • Script: ${BLUE}${SCRIPT_FILE}${NC}"
echo ""

# Confirm before running
read -p "Mulai performance test? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}Test dibatalkan${NC}"
    exit 0
fi

# Run the test
echo -e "${GREEN}🚀 Memulai K6 Performance Test...${NC}"
echo ""

k6 run "${SCRIPT_FILE}" \
    -e BASE_URL="${BASE_URL}" \
    -e TEST_EMAIL="${TEST_EMAIL}" \
    -e TEST_PASSWORD="${TEST_PASSWORD}"

# Check exit code
if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✅ Test berhasil dijalankan!${NC}"
else
    echo ""
    echo -e "${RED}❌ Test gagal atau threshold tidak tercapai${NC}"
    echo -e "${YELLOW}ℹ️  Check laporan di atas untuk detail${NC}"
fi
