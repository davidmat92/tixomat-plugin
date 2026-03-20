#!/bin/bash
# Tixomat Plugin – Deploy Script
# Pusht zu GitHub und deployed auf alle Server
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
REMOTE_SERVER="runcloud@51.195.61.6"
RSYNC_OPTS="-avzc --delete --exclude=.git --exclude=node_modules --exclude=.claude"

echo "══════════════════════════════════"
echo "  Tixomat Deploy"
echo "══════════════════════════════════"

# 1. Git push (IMMER!)
echo ""
echo "▸ Git push..."
cd "$PLUGIN_DIR"
git push origin main

# 2. Deploy tixomat.de (tixomat-plugin Ordner)
echo ""
echo "▸ Deploy → tixomat.de..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_SERVER:/home/runcloud/webapps/Tixomat/wp-content/plugins/tixomat-plugin/"

# 3. Deploy kitchenklub.de (ehemals kitchen.mdj.events / demo-mdj-events)
echo ""
echo "▸ Deploy → kitchenklub.de..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_SERVER:/home/runcloud/webapps/demo-mdj-events/wp-content/plugins/tixomat/"

# 4. Deploy kitchen-klub
echo ""
echo "▸ Deploy → kitchen-klub..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_SERVER:/home/runcloud/webapps/kitchen-klub/wp-content/plugins/tixomat/"

# 5. Deploy evendis
echo ""
echo "▸ Deploy → evendis..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_SERVER:/home/runcloud/webapps/evendis/wp-content/plugins/tixomat/"

# 6. OPcache reset
echo ""
echo "▸ OPcache reset..."
ssh "$REMOTE_SERVER" "echo '<?php opcache_reset(); echo \"OK\"; unlink(__FILE__);' > /home/runcloud/webapps/Tixomat/opcache_reset.php"
curl -s https://tixomat.de/opcache_reset.php
echo ""
ssh "$REMOTE_SERVER" "php -r 'opcache_reset(); echo \"OK\n\";'" 2>/dev/null || true

echo ""
echo "══════════════════════════════════"
echo "  ✓ Deploy complete"
echo "══════════════════════════════════"
