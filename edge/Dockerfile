# ShopBot Vision — edge image (Python vision service). Reproducible, multi-arch, hardware-NEUTRAL.
#
# Build context is the REPO ROOT (so it can copy app/):
#   docker build -f edge/Dockerfile -t shopbot-vision:dev .
# Multi-arch (arm64 for RK3588/Jetson, amd64 for x86):
#   docker buildx build --platform linux/arm64,linux/amd64 -f edge/Dockerfile -t shopbot-vision:dev .
#
# This is NOT the repo's Laravel Dockerfile (that one, at repo root, builds the PHP SaaS). This image
# contains ONLY the Python vision service. No NVIDIA/RKNN/CUDA here — accelerator support is an
# optional overlay base image (docs/vision-edge-deployment-architecture.md §8), never baked in.

FROM python:3.11-slim-bookworm

# System deps: ffmpeg for RTSP decode; libgl1 + libglib2.0-0 are the two shared libs
# opencv-python-headless still dlopen's at runtime. Kept minimal.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv

COPY edge/requirements.txt /srv/requirements.txt
RUN pip install --no-cache-dir -r /srv/requirements.txt

# Copy ONLY the Python vision service — never the PHP/Laravel tree that shares app/.
COPY app/alerting.py app/auth.py app/db.py app/detector.py app/evaluation.py \
     app/events.py app/main.py app/quality.py app/redact.py app/scheduler.py /srv/app/
COPY app/cal /srv/app/cal
COPY app/movement /srv/app/movement
COPY app/web /srv/app/web

# ShopBot license VERIFICATION key — PUBLIC key only, baked at build time (safe to ship). The signing
# PRIVATE key never leaves ShopBot Cloud, and NO production credential is present in the image.
ARG SHOPBOT_LICENSE_PUBKEY=""
# The image version (the CI tag, e.g. edge-v0.1.0). Surfaced via /api/health and visionctl.
ARG SHOPBOT_VISION_VERSION="edge-dev"

# Data (SQLite outbox, config, snapshots, model cache) lives on the mounted NVMe volume.
ENV VISION_DB=/data/vision.db \
    SNAP_DIR=/data/snapshots \
    VISION_DATA=/data \
    PYTHONUNBUFFERED=1 \
    SHOPBOT_LICENSE_PUBKEY=$SHOPBOT_LICENSE_PUBKEY \
    SHOPBOT_VISION_VERSION=$SHOPBOT_VISION_VERSION
VOLUME ["/data"]
EXPOSE 8080

# Liveness: the API is up if uvicorn answers at all — 200 or 401 (auth wall) both mean "alive". Uses
# stdlib python (no curl dependency) and needs no credentials.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD \
    python -c "import urllib.request,urllib.error,sys;\
try:\
 urllib.request.urlopen('http://127.0.0.1:8080/api/health',timeout=3); sys.exit(0)\
except urllib.error.HTTPError as e: sys.exit(0 if e.code in (200,401) else 1)\
except Exception: sys.exit(1)"

# Default entrypoint: the dashboard/API service (camera config, health, selftest).
# The movement door is a separate command against the SAME image:
#   docker run ... shopbot-vision:dev python -m app.movement.run_door --selftest --store S --zone Z
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8080"]
