#!/bin/bash
set -u
MODELS=(
  "openai|gpt-5"
  "openai|gpt-5-mini"
  "openai|gpt-5-nano"
  "openai|gpt-4o"
  "openai|gpt-4o-mini"
  "anthropic|claude-sonnet-5"
  "anthropic|claude-haiku-4-5-20251001"
  "gemini|gemini-3.6-flash"
  "gemini|gemini-3-flash-preview"
  "gemini|gemini-3.5-flash-lite"
)
PASS=0
FAIL=0
for entry in "${MODELS[@]}"; do
  IFS='|' read -r provider model <<< "$entry"
  echo "=== Testing $provider/$model ==="
  docker compose -f /tmp/eccube-verify/docker-compose.verify.yml exec -T eccube bash -c "php bin/console doctrine:query:sql \"UPDATE plg_ai_chat_assistant_config SET provider='$provider', model='$model' WHERE id=1\" 2>&1 | head -1"
  sleep 1
  RESP=$(docker compose -f /tmp/eccube-verify/docker-compose.verify.yml exec -T eccube bash -c "curl -s --max-time 30 http://localhost/api/ai-chat-assistant/chat -H 'Content-Type: application/json' -d '{\"message\":\"こんにちは\",\"session_id\":\"verify-10-simple\"}' 2>&1")
  SIMPLE_OK=$(echo "$RESP" | php -r ' $d=json_decode(file_get_contents("php://stdin"), true); echo ($d["success"]??false)?"1":"0";' 2>&1 | tr -d '\n')
  RESP2=$(docker compose -f /tmp/eccube-verify/docker-compose.verify.yml exec -T eccube bash -c "curl -s --max-time 60 http://localhost/api/ai-chat-assistant/chat -H 'Content-Type: application/json' -d '{\"message\":\"おすすめ商品を教えて\",\"session_id\":\"verify-10-tool\"}' 2>&1")
  TOOL_OK=$(echo "$RESP2" | php -r ' $d=json_decode(file_get_contents("php://stdin"), true); echo ($d["success"]??false)?"1":"0";' 2>&1 | tr -d '\n')
  if [ "$SIMPLE_OK" = "1" ] && [ "$TOOL_OK" = "1" ]; then
    echo "  PASS simple=$SIMPLE_OK tool=$TOOL_OK"
    PASS=$((PASS+1))
  else
    echo "  FAIL simple=$SIMPLE_OK tool=$TOOL_OK"
    echo "  simple: $(echo "$RESP" | head -c 300)"
    echo "  tool: $(echo "$RESP2" | head -c 400)"
    FAIL=$((FAIL+1))
  fi
done
echo "=== SUMMARY ==="
echo "PASS: $PASS / 10  FAIL: $FAIL / 10"
