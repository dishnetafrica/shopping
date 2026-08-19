# ShopBot Vision — First Box Guide (for the owner)

A simple checklist to take one Chinese AI box from "in the box" to "producing stock-movement events into
tenant 22 (Bhavin)". You do not need to be a Linux engineer — every command you run is copy‑paste.

**Before you start,** the one-time setup in `docs/SHOPBOT_EDGE_OWNER_RUNBOOK.md` must be done: license
keypair generated, GitHub variables/secrets set, `edge-v0.1.0` image built + pushed, `DEFAULT_IMAGE` in
`public/vision/bootstrap.sh` set, and tenant 22 verified READY.

---

### A. What you give China
The AI box, temporary internet + remote access, one IP camera + its credentials, and
`docs/CHINA_SUPPLIER_INSTRUCTIONS.md`. **Nothing else** — no source, no Git, no keys, no tokens.

### B. What China installs
Ubuntu + Docker + internet + one RTSP/ONVIF camera pointed at the test area. That's it.

### C. The one command (you or China run it on the box)
```bash
curl -fsSL https://mycloudbss.com/vision/bootstrap.sh -o shopbot-install.sh
sudo bash shopbot-install.sh
```
The installer prompts for the **read-only GHCR pull token** (username + token you provide), pulls the
image, then **`docker logout`s** — no registry credential stays on the box.

### D. Expected output on the box
```
✓ ARM64 detected     ✓ Docker ready     ✓ Internet reachable
✓ Config written  — Device ID SB-UG-XXXXXX
  Registry username (ghcr.io): <you enter>
  Read-only pull token: <you enter, hidden>
✓ Registry login OK (ghcr.io, pull-only)
✓ Downloading Vision Engine (ghcr.io/dishnetafrica/shopbot-vision:edge-v0.1.0)
✓ Pull credential discarded (docker logout ghcr.io)
✓ Activating device against ShopBot Cloud…
CLAIM CODE: ABC123
…(waits for you to claim)…
```

### E. What China sends you
The **CLAIM CODE** (e.g. `ABC123`) and the **Device ID**.

### F. What you run (on mycloudbss.com)
```bash
php artisan vision:claim ABC123 --tenant=22 --type=development
```

### G. Confirm activation
On the box (or ask China to paste it):
```bash
visionctl status
```
Look for: `Version: edge-v0.1.0 · License: DEVELOPMENT · Tenant: 22 · Status: ACTIVE`. On the cloud:
```bash
php artisan tinker --execute='App\Models\VisionBox::where("tenant_id",22)->get(["box_uid","status","license_type","expires_at"]);'
```
Expect one row, `status=claimed`, `license_type=development`.

### H. Connect the camera
Open `http://<box-ip>:8080` (login shown at install). **Discover** (ONVIF) or **Add camera** by RTSP URL
→ **Test** (frames appear).

### I. Configure the movement door
On the box, point the door at the camera + the counting line:
```bash
cd /opt/shopbot-vision
# edit docker-compose.yml vision-door command → --rtsp rtsp://user:pass@cam/... --detector <mod:fn> --line 0,540,1920,540
docker compose up -d vision-door
```
(Until a detector model is wired, keep the default `--selftest` — see J.)

### J. Test synthetic movement (no camera/model needed)
The default `vision-door` emits one synthetic `stock.move`. Confirm it reached tenant 22:
```bash
php artisan tinker --execute='App\Models\VisionEvent::withoutGlobalScopes()->where("tenant_id",22)->latest("id")->take(3)->get(["event","zone","occurred_at","recon_result"]);'
```

### K. Test real camera movement
With a real detector wired (I), carry a box/bag past the line → a `stock.move` should appear in the
review screen for tenant 22. **Note:** the v0.1 detector is not trained yet, so this step proves *plumbing
+ crossing* once a model is attached; detection accuracy comes with the model.

### L. Test internet failure
Unplug the box's internet. Movement events keep being produced and **queue locally** (nothing lost).
Check the queue: `visionctl status` → `queue.pending` rises.

### M. Test internet recovery
Reconnect internet. The outbox drains automatically; `queue.pending` returns to 0 and the events appear
in tenant 22.

### N. Revoke the China box
```bash
php artisan vision:revoke <box_uid>
```
It stops licensed operation at its next heartbeat (online) or at license expiry (offline). Local
evidence/outbox remains.

### O. Remove supplier remote access
On the box: remove their SSH keys (`~/.ssh/authorized_keys`), uninstall any remote-desktop/tunnel agent
they added, and rotate the box dashboard password (`visionctl password` shows it; set a new one in
`/opt/shopbot-vision/.env` → `docker compose up -d --force-recreate`).

### P. Wipe development credentials
On the box:
```bash
cd /opt/shopbot-vision && docker compose down
rm -f data/device_key data/box_secret data/credential data/license.json
```
The device identity + license + token are gone; a fresh install would enrol as a new device.

### Q. Convert to a production customer box later
**Cloud-side only — no reinstall, no new software, no source shared.** Re-bind the box row to the real
customer tenant and set production:
```bash
php artisan tinker --execute='$b=App\Models\VisionBox::where("box_uid","<box_uid>")->first();
$b->tenant_id=<CUSTOMER_TENANT>; $b->license_type="production"; $b->save();'
```
The box's next poll re-issues a **production** license and it keeps running. (A tidy
`vision:license <box_uid> --type=production --tenant=<n>` command can replace this — ask when you want it.)

---

## Honest status of this guide
- **SANDBOX VERIFIED:** installer syntax, compose is pull-based, workflow valid, licensing/movement tests
  green, license keys config:cache-safe.
- **PHYSICAL TEST REQUIRED:** every step above on real hardware — actual ARM box, real image pull, real
  Docker start, real RTSP camera, real internet failure/recovery, and the clone-rejection test. None of
  those are provable in our dev environment; run them on the sample box and record the results.
