"""
Assemble ShopBot Stock Object Dataset v0.1 from the approved sources in datasets.yaml.

Pipeline: for each commercial-approved source → parse its annotations (COCO / YOLO / Open Images CSV) →
remap classes via classmap.remap (→ stock_object, or drop as negative) → perceptual-hash dedup →
deterministic train/val/test split → write a unified single-class YOLO dataset + a provenance manifest.

v0.1 trains a SINGLE class `stock_object`. The advisory subclass is recorded per box in the manifest
(sidecar) for a later coarse→fine head, but the YOLO label files are single-class.

This is the GPU-box / Colab entry point — it needs the datasets downloaded and (Pillow/opencv for
dedup). It does NOT run in the ShopBot sandbox (no datasets, no heavy deps). The pure parts (classmap,
split assignment, manifest shaping) are unit-tested in selftest.py.

Usage (on a machine with the data): python training/prepare.py --root /data/sources --out /data/v0_1
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os

from classmap import PRIMARY, remap


def assign_split(image_id: str, ratios=(0.8, 0.1, 0.1)) -> str:
    """Deterministic split by a stable hash of the image id — reproducible, no RNG, so re-runs and
    incremental additions keep the same image in the same split (no train/test leakage on re-runs)."""
    h = int(hashlib.md5(image_id.encode("utf-8")).hexdigest(), 16) % 1000
    tr, va, _te = ratios
    if h < tr * 1000:
        return "train"
    if h < (tr + va) * 1000:
        return "val"
    return "test"


def phash(path: str) -> str:
    """Perceptual hash for near-duplicate removal. Lazy Pillow import — only when actually deduping."""
    from PIL import Image  # lazy
    img = Image.open(path).convert("L").resize((8, 8))
    px = list(img.getdata())
    avg = sum(px) / len(px)
    bits = "".join("1" if p >= avg else "0" for p in px)
    return f"{int(bits, 2):016x}"


def normalize_source(source: dict, root: str) -> list[dict]:
    """Return a list of {image_path, image_id, boxes:[{xyxy, subclass}]} for one source, classes already
    remapped to stock_object (negatives dropped). Parser dispatch by source['format']. The per-format
    parsers (COCO/YOLO/OpenImages) are straightforward and live here; omitted bodies raise so a missing
    parser is loud, never silently empty."""
    fmt = source["format"]
    src_dir = os.path.join(root, source["id"])
    if fmt == "coco":
        return _parse_coco(src_dir)
    if fmt == "yolo":
        return _parse_yolo(src_dir)
    if fmt == "openimages_csv":
        return _parse_openimages(src_dir, source.get("classes_wanted", []))
    raise ValueError(f"no parser for format {fmt!r} (source {source['id']})")


def _keep_boxes(raw_boxes) -> list[dict]:
    """Apply classmap to a source's raw boxes; keep only stock_object, tag advisory subclass."""
    out = []
    for b in raw_boxes:
        m = remap(b["class"])
        if m is None:
            continue                        # negative / ignored — never a positive
        out.append({"xyxy": b["xyxy"], "subclass": m[1]})
    return out


def build(root: str, out: str, sources: list[dict]) -> dict:
    os.makedirs(out, exist_ok=True)
    seen_hashes: set[str] = set()
    manifest = {"version": "0.1", "primary_class": PRIMARY, "counts": {}, "provenance": [], "boxes": []}
    for source in sources:
        if not source.get("commercial"):
            continue
        items = normalize_source(source, root)
        kept = dropped_dupes = 0
        for it in items:
            boxes = _keep_boxes(it["boxes"])
            if not boxes:
                continue
            try:
                h = phash(it["image_path"])
            except Exception:
                h = it["image_id"]
            if h in seen_hashes:
                dropped_dupes += 1
                continue
            seen_hashes.add(h)
            split = assign_split(it["image_id"])
            _write_yolo_label(out, split, it, boxes)     # single-class stock_object label file
            manifest["boxes"].append({"image_id": it["image_id"], "split": split,
                                      "subclasses": [b["subclass"] for b in boxes]})
            kept += 1
        manifest["counts"][source["id"]] = {"kept_images": kept, "dropped_dupes": dropped_dupes}
        manifest["provenance"].append({"id": source["id"], "license": source["license"],
                                       "url": source["url"], "attribution": source.get("attribution")})
    with open(os.path.join(out, "manifest.json"), "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, indent=2)
    return manifest


# ── format parsers + label writer (real on a data machine; bodies elided here are the only TODO) ──
def _parse_coco(d):        raise NotImplementedError("COCO parser: read instances.json → boxes[{class,xyxy}]")
def _parse_yolo(d):        raise NotImplementedError("YOLO parser: read data.yaml + labels/*.txt")
def _parse_openimages(d, want): raise NotImplementedError("Open Images CSV parser: filter to `want` classes")
def _write_yolo_label(out, split, item, boxes): raise NotImplementedError("write images/<split> + labels/<split>/*.txt (class 0)")


def main(argv):
    import yaml  # lazy
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", required=True, help="dir containing downloaded source subfolders")
    ap.add_argument("--out", required=True)
    ap.add_argument("--datasets", default=os.path.join(os.path.dirname(__file__), "datasets.yaml"))
    args = ap.parse_args(argv)
    with open(args.datasets) as fh:
        cfg = yaml.safe_load(fh)
    m = build(args.root, args.out, cfg["sources"])
    print(json.dumps(m["counts"], indent=2))
    return 0


if __name__ == "__main__":
    import sys
    raise SystemExit(main(sys.argv[1:]))
