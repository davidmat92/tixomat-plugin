#!/bin/bash
# ============================================================
# Tixomat Bot – One-Command Deploy
# ============================================================
# Usage: cd bot/ && ./deploy.sh
#
# Does: git push → PythonAnywhere git pull → reload webapp
# ============================================================

set -e

# --- Config ---
PA_USER="dpconnect"
PA_DOMAIN="tixomat-dpconnect.pythonanywhere.com"
PA_API="https://www.pythonanywhere.com/api/v0/user/${PA_USER}"
PA_REPO_DIR="/home/dpconnect/tixomat-bot"

# API Token from environment or .env file
if [ -z "$PA_API_TOKEN" ]; then
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    if [ -f "$SCRIPT_DIR/.env.deploy" ]; then
        source "$SCRIPT_DIR/.env.deploy"
    fi
fi

if [ -z "$PA_API_TOKEN" ]; then
    echo "❌ PA_API_TOKEN nicht gesetzt!"
    echo ""
    echo "Entweder:"
    echo "  export PA_API_TOKEN=dein_token"
    echo "  oder erstelle bot/.env.deploy mit: PA_API_TOKEN=dein_token"
    echo ""
    echo "Token findest du unter: https://www.pythonanywhere.com/account/#api_token"
    exit 1
fi

AUTH="Token ${PA_API_TOKEN}"

echo "🚀 Tixomat Bot Deploy"
echo "======================"

# Step 1: Git push (from repo root)
echo ""
echo "📤 Git push..."
cd "$(dirname "$0")/.."
git push origin main
cd -
echo "✅ Code gepusht"

# Step 2: Git pull on PythonAnywhere
echo ""
echo "📥 Git pull auf PythonAnywhere..."

# Upload a pull script and use files API
cat > /tmp/pa_tixomat_pull.sh << 'PULLSCRIPT'
#!/bin/bash
cd /home/dpconnect/tixomat-bot
git pull origin main 2>&1
echo "PULL_DONE"
PULLSCRIPT

curl -s -X POST "${PA_API}/files/path/home/dpconnect/pa_tixomat_pull.sh" \
    -H "Authorization: ${AUTH}" \
    -F "content=@/tmp/pa_tixomat_pull.sh" > /dev/null

# Create always-on task to run it
TASK_ID=$(curl -s -X POST "${PA_API}/always_on/" \
    -H "Authorization: ${AUTH}" \
    -H "Content-Type: application/json" \
    -d '{"command": "bash /home/dpconnect/pa_tixomat_pull.sh", "description": "tixomat pull", "enabled": true}' \
    | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null)

if [ -n "$TASK_ID" ]; then
    sleep 15
    curl -s -X DELETE "${PA_API}/always_on/${TASK_ID}/" -H "Authorization: ${AUTH}" > /dev/null 2>&1
    echo "✅ Git pull erledigt"
else
    echo "⚠️  Git pull fehlgeschlagen - manuell auf PA pullen"
fi

# Step 3: Reload webapp
echo ""
echo "🔄 Webapp reloaden..."
RELOAD_RESULT=$(curl -s -X POST "${PA_API}/webapps/${PA_DOMAIN}/reload/" \
    -H "Authorization: ${AUTH}")

if echo "$RELOAD_RESULT" | grep -q "OK"; then
    echo "✅ Webapp reloaded!"
else
    echo "⚠️  Reload: $RELOAD_RESULT"
    echo "   (Evtl. manuell reloaden: PythonAnywhere → Web → Reload)"
fi

# Step 4: Health check
echo ""
echo "🏥 Health Check..."
sleep 3
HEALTH=$(curl -s "https://${PA_DOMAIN}/health" 2>/dev/null)
if echo "$HEALTH" | grep -q "ok"; then
    echo "✅ Bot läuft! $HEALTH"
else
    echo "⚠️  Health Check Response: $HEALTH"
fi

echo ""
echo "🎉 Deploy fertig!"
