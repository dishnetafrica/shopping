# ShopBot Vision — Development Test Box (Supplier Instructions)

Thank you for preparing the AI edge box. We will remotely install and activate **ShopBot Vision** on it
for a development test. **You do not need any source code, and you will not receive any.** Our software is
downloaded from our server and activated for this one device only.

## Please prepare

1. **The AI edge box**, powered on, with **Ubuntu Linux** installed.
2. **Docker** installed (or allow our installer to install it).
3. **Internet access** (Ethernet, Wi‑Fi, or 4G).
4. A **temporary remote-access method** for us (SSH access, or a temporary remote-desktop/tunnel). Please
   share the access details with us privately.
5. **One IP camera** with **RTSP** (and **ONVIF** if available), connected on the same network as the box.
6. The camera **positioned to view the test movement area** (e.g. a doorway or a table where we can carry
   boxes/bags past it).
7. The camera's **IP address, RTSP URL, username and password**.

## The registry pull credential (what you receive)

The ShopBot Vision software is in our **private registry**. For this test we provide a **temporary,
read-only pull credential** so the box can download the image. This credential:

- can **only download** the `shopbot-vision:edge-v0.1.0` image — nothing else;
- **cannot** upload, change, or delete anything;
- **cannot** access our GitHub, source code, servers, database, or any other software;
- is **revocable**, and we will **revoke it after the test**.

We will either run the installation ourselves over remote access (you don't type anything), or send you
this pull credential privately to use when the installer asks. **Please do not share it, publish it, or
keep it after the test.** It is the only credential you receive, and it does nothing except pull this one
image.

## What happens next

1. We connect remotely and run **one install command**. Our software downloads and starts automatically.
2. The box will show a short **CLAIM CODE**. **Please send us that code.**
3. We activate the box from our side. It then becomes **ACTIVE** and connects to the camera.
4. We run the vision test together.

The one command (we will run it, or guide you):
```bash
curl -fsSL https://mycloudbss.com/vision/bootstrap.sh | sudo bash
```

## Please do NOT (without telling us first)

- change the operating system, Docker, or network configuration,
- change the camera firmware or its RTSP settings,
- reinstall or wipe the box,
- move the software to a different machine (it is licensed to **this** device and will not run elsewhere).

## Minimum hardware (for reference)

ARM64 (RK3588 / Jetson Orin) or x86_64 · Ubuntu 22.04 · **≥ 8 GB RAM** · NVMe/SSD (**≥ 32 GB free**) ·
Gigabit Ethernet (or Wi‑Fi/4G) · 12 V power · HDMI for setup · watchdog if available.

## Please send us, before we begin

- **AI box:** model, CPU, GPU/NPU, RAM, storage, Ubuntu version, network (Ethernet/Wi‑Fi/4G), remote-access method.
- **Camera:** model, resolution/FPS, RTSP URL, ONVIF support, H.264/H.265.
- **Price** for one sample unit.

That's everything. We handle the software; you provide the box, the camera, and temporary access.
