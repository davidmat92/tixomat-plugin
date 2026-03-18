#!/bin/bash
# Tixomat Plugin – Deploy Script
# Pusht zu GitHub und deployed auf tixomat.de + kitchen-klub.de
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
REMOTE_TIXOMAT="runcloud@tixomat.de"
REMOTE_KITCHEN="runcloud@51.195.61.6"
DEPLOY_PATH_TIXOMAT="/home/runcloud/webapps/Tixomat/wp-content/plugins/tixomat-plugin/"
DEPLOY_PATH_KITCHEN="/home/runcloud/webapps/kitchen-klub/wp-content/plugins/tixomat/"

echo "══════════════════════════════════"
echo "  Tixomat Deploy"
echo "══════════════════════════════════"

# 1. Git push
echo ""
echo "▸ Git push..."
cd "$PLUGIN_DIR"
git push origin main

# 2. Deploy tixomat.de
echo ""
echo "▸ Deploy → tixomat.de (tixomat-plugin/)..."
rsync -avzc --delete --exclude='.git' --exclude='node_modules' --exclude='.claude' \
  "$PLUGIN_DIR/" "$REMOTE_TIXOMAT:$DEPLOY_PATH_TIXOMAT"

# OPcache reset tixomat.de
ssh "$REMOTE_TIXOMAT" "echo '<?php opcache_reset(); echo \"OK\"; unlink(__FILE__);' > /home/runcloud/webapps/Tixomat/opcache_reset.php"
curl -s https://tixomat.de/opcache_reset.php
echo ""

# 3. Deploy kitchen-klub.de
echo ""
echo "▸ Deploy → kitchen-klub.de (tixomat/)..."
rsync -avzc --delete --exclude='.git' --exclude='node_modules' --exclude='.claude' \
  "$PLUGIN_DIR/" "$REMOTE_KITCHEN:$DEPLOY_PATH_KITCHEN"

# OPcache reset kitchen
ssh "$REMOTE_KITCHEN" "php -r 'opcache_reset(); echo \"OK\n\";'" 2>/dev/null || true
echo ""

echo "══════════════════════════════════"
echo "  ✓ Deploy complete"
echo "══════════════════════════════════"
