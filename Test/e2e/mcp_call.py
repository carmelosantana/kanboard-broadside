# Minimal MCP stdio client: python3 mcp_call.py '<tool args json>' ...  (one tools/call per arg)
import json, os, subprocess, sys
env = {**os.environ, "KANBOARD_API_ENDPOINT": "http://127.0.0.1:8097/jsonrpc.php", "KANBOARD_AUTH_METHOD": "basic",
       "KANBOARD_USERNAME": "pagebot", "KANBOARD_PASSWORD": open(os.environ.get("BROADSIDE_PAGEBOT_PASS_FILE", os.path.dirname(__file__) + "/pagebot-pass")).read().strip(),
       "KANBOARD_SKIP_RBAC": "true", "KANBOARD_CHAT_URL": "http://127.0.0.1:9"}
p = subprocess.Popen(["/tmp/kanboard-mcp-broadside"], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=env, text=True)
def send(m): p.stdin.write(json.dumps(m) + "\n"); p.stdin.flush()
def recv(i):
    while True:
        m = json.loads(p.stdout.readline())
        if m.get("id") == i: return m
send({"jsonrpc": "2.0", "id": 0, "method": "initialize", "params": {"protocolVersion": "2025-03-26", "capabilities": {}, "clientInfo": {"name": "demo", "version": "1"}}}); recv(0)
send({"jsonrpc": "2.0", "method": "notifications/initialized"})
for n, a in enumerate(sys.argv[1:], 1):
    send({"jsonrpc": "2.0", "id": n, "method": "tools/call", "params": {"name": "artifact", "arguments": json.loads(a)}})
    r = recv(n)["result"]; print(("ERROR " if r.get("isError") else "") + r["content"][0]["text"])
p.terminate()
