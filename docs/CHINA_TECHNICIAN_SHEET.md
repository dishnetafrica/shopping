# ShopBot Vision — Technician Setup Sheet

One page. Follow it top to bottom. You are installing our software on **one** AI box for a test.
You do **not** need any source code, and you will not receive any. If anything is unclear, stop and ask us —
do not guess.

---

## 1. Prerequisites (have these ready before you start)

- [ ] AI edge box, powered on, with **Ubuntu 22.04** installed.
- [ ] **Docker** installed. Check: `docker --version` prints a version.
- [ ] **Internet** working on the box. Check: `curl -I https://mycloudbss.com` returns `HTTP/... 200`.
- [ ] **One IP camera** on the same network, positioned to view the test area (a doorway or table where
      we can carry boxes/bags past it).
- [ ] The camera's **IP address, RTSP URL, username, and password** written down.
- [ ] The **pull username + pull token** we send you privately (used once during install, then removed).
- [ ] **≥ 8 GB RAM** and **≥ 32 GB free disk** (`free -h`, `df -h /`).

---

## 2. The exact command (run on the box)

```bash
curl -fsSL https://mycloudbss.com/vision/bootstrap.sh -o shopbot-install.sh
sudo bash shopbot-install.sh
```

When it asks, paste the **pull username** and **pull token** we gave you (the token is hidden as you type).
It downloads our software, removes the pull credential automatically, and starts. This takes a few minutes.

---

## 3. What to send Bhavin

When the install finishes, the screen shows two lines. **Copy both exactly and send them to us:**

```
Device ID:  SB-UG-XXXXXX
CLAIM CODE: ABC123
```

Then **wait** — we activate the box from our side. Send nothing else. Do not re-run the installer.

---

## 4. What you must NOT change (without asking us first)

- Do **not** reinstall, wipe, re-image, or factory-reset the box.
- Do **not** move the software to another machine — it is licensed to **this** box only and will not run
  elsewhere.
- Do **not** change the operating system, Docker, or the box's network/IP settings.
- Do **not** change the camera's firmware or its RTSP username/password after you have sent them to us.
- Do **not** share, publish, or keep the pull token — it is one-time and we revoke it after the test.

---

## 5. Camera connection

1. Connect the camera to the **same network/switch** as the box and power it on.
2. Confirm the camera has an IP address (from the camera's own app, or your router's device list).
3. Point it at the **test movement area** so a person carrying a box/bag past it is clearly in view —
   good light, stable mount, whole doorway/line visible.
4. Send us: **camera model, resolution/FPS, RTSP URL, username, password, ONVIF yes/no, H.264 or H.265.**

We connect the camera to the software from our side over the remote access in Section 6 — you do not need
to configure it in any dashboard.

---

## 6. Remote-access requirements (so we can run the test)

We do the software and camera setup ourselves over remote access. Please provide **one** of:

- **SSH** to the box: give us the **IP/hostname, port, username**, and add our public key (or send a
  temporary password). Confirm `sudo` works for that user.
- **or** a temporary **remote-desktop / tunnel** (e.g. AnyDesk/TeamViewer/RustDesk, or a reverse tunnel) —
  send the ID and one-time access code.

The box must be able to reach the internet while we are connected. Please send these access details
**privately**. We only need this during the test; you can remove it afterward (see Section 7 of our supplier
instructions).

---

## 7. Troubleshooting

| Symptom | What to do |
|---|---|
| `curl: command not found` | `sudo apt-get update && sudo apt-get install -y curl`, then re-run Section 2. |
| Installer says **Docker not found** | Let the installer install Docker if it offers; otherwise install Docker, then re-run. |
| **Pull denied / login failed** | The pull username/token was mistyped or expired — ask us for a fresh one; do not change anything else. |
| **No internet** during install | Fix the box's network (`curl -I https://mycloudbss.com` must return 200), then re-run Section 2. |
| **No claim code appears** | Take a photo of the full terminal output and send it to us — do not re-run. |
| **Not enough disk space** | Free space so `df -h /` shows ≥ 32 GB free, then re-run. |
| Anything else | Take a photo/paste of the terminal and send it to us. Do not reinstall or wipe the box. |

---

*That is the whole job: prepare the box and camera, run one command, send us the Device ID + Claim Code,
and give us temporary remote access. We handle all software, licensing, and camera configuration.*
