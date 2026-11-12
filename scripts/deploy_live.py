#!/usr/bin/env python3
"""Deploy live/worker.js to Cloudflare as the "mortez-live" Worker (with its Durable Object) and turn on its workers.dev address.

    CF_TOKEN_FILE=~/.mortez/cloudflare-token CF_ACCOUNT=<account id> python3 scripts/deploy_live.py

The token stays outside the repo. Same result as `npx wrangler deploy` in live/.
"""
import json, os, pathlib, sys, urllib.request, uuid

ROOT = pathlib.Path(__file__).resolve().parent.parent
NAME = "mortez-live"
tok = os.environ.get("CF_TOKEN") or pathlib.Path(os.path.expanduser(os.environ.get("CF_TOKEN_FILE", "~/.mortez/cloudflare-token"))).read_text().strip()
acct = os.environ.get("CF_ACCOUNT") or sys.exit("Set CF_ACCOUNT")
API = f"https://api.cloudflare.com/client/v4/accounts/{acct}"


def call(method, path, body=None, ctype="application/json"):
    req = urllib.request.Request(API + path, data=body, method=method, headers={"Authorization": "Bearer " + tok, "Content-Type": ctype})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        return json.loads(e.read() or b"{}")


# is the Durable Object class already there? (the migration may only run once)
existing = call("GET", f"/workers/scripts/{NAME}/settings")
first = not existing.get("success")
meta = {
    "main_module": "worker.js",
    "compatibility_date": "2025-04-01",
    "bindings": [{"type": "durable_object_namespace", "name": "WORLD", "class_name": "World"}],
}
if first:
    meta["migrations"] = {"new_tag": "v1", "new_sqlite_classes": ["World"]}
b = "----mortez" + uuid.uuid4().hex
src = (ROOT / "live" / "worker.js").read_bytes()
body = (f"--{b}\r\nContent-Disposition: form-data; name=\"metadata\"\r\nContent-Type: application/json\r\n\r\n{json.dumps(meta)}\r\n"
        f"--{b}\r\nContent-Disposition: form-data; name=\"worker.js\"; filename=\"worker.js\"\r\nContent-Type: application/javascript+module\r\n\r\n").encode() + src + f"\r\n--{b}--\r\n".encode()
r = call("PUT", f"/workers/scripts/{NAME}", body, "multipart/form-data; boundary=" + b)
if not r.get("success"):
    sys.exit("Upload failed: " + json.dumps(r.get("errors")))
print("uploaded", NAME, "(first deploy)" if first else "")
r = call("POST", f"/workers/scripts/{NAME}/subdomain", json.dumps({"enabled": True, "previews_enabled": False}).encode())
sub = call("GET", "/workers/subdomain")
if sub.get("success") and sub["result"].get("subdomain"):
    print(f"live at https://{NAME}.{sub['result']['subdomain']}.workers.dev  (WebSocket: wss://{NAME}.{sub['result']['subdomain']}.workers.dev/world)")
else:
    print("workers.dev subdomain:", json.dumps(sub.get("errors") or sub.get("result")))
