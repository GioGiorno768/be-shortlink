#!/bin/bash

# Laravel Server Runner for Testing
# Usage: ./start-test-server.sh

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}╔════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   Laravel Test Server                 ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════╝${NC}"
echo ""

# Check if server is already running
if curl -s http://localhost:8000/api/ > /dev/null 2>&1; then
    echo -e "${YELLOW}⚠️  Server sudah berjalan di localhost:8000${NC}"
    echo ""
    read -p "Restart server? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Menghentikan server yang lama...${NC}"
        pkill -f "php artisan serve" || true
        sleep 2
    else
        exit 0
    fi
fi

# Start server with testing environment
echo -e "${GREEN}🚀 Starting Laravel server dengan environment testing...${NC}"
echo -e "${BLUE}   URL: http://localhost:8000${NC}"
echo -e "${YELLOW}   Press Ctrl+C to stop${NC}"
echo ""

php artisan serve --host=0.0.0.0 --port=8000 --env=testing
