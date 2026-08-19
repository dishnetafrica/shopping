"""
YOLOX experiment config for ShopBot Stock Object Detector v0.1 — SINGLE generic class `stock_object`.

Runs on a GPU machine with the official YOLOX package installed (Apache-2.0). Consumes the prepared
dataset (COCO export from prepare.py) with one category. Advisory subclasses are NOT trained here
(generic-first, per docs/vision-stock-object-taxonomy.md) — they live in the dataset manifest for a
later coarse→fine head.

Train:
  python -m yolox.tools.train -f training/exp_stock_object.py -d 1 -b 16 --fp16 -o -c yolox_s.pth
Export ONNX (opset 12, RKNN/TensorRT-friendly — see docs/vision-carton-detector-data-strategy.md §E):
  python -m yolox.tools.export_onnx -f training/exp_stock_object.py -c YOLOX_outputs/.../best_ckpt.pth \
      --output-name stock_object_v0_1.onnx --opset 12

This file does not run in the ShopBot sandbox (no torch/GPU). It is the training recipe artifact.
"""
try:
    from yolox.exp import Exp as _MyExp   # available only where YOLOX is installed
except Exception:                          # keep the file importable/lintable without YOLOX
    _MyExp = object


class Exp(_MyExp):
    def __init__(self):
        super().__init__()
        # yolox-s backbone (good speed/accuracy for edge; swap to -m for the RF-DETR-class benchmark)
        self.depth = 0.33
        self.width = 0.50

        self.num_classes = 1                 # stock_object (generic-first)
        self.input_size = (640, 640)
        self.test_size = (640, 640)
        self.max_epoch = 80                  # bootstrap; watch val mAP for early stop
        self.data_num_workers = 4
        self.eval_interval = 5

        # dataset (COCO export produced by prepare.py). Set on the GPU box or via env.
        import os
        self.data_dir = os.environ.get("STOCK_DATA_DIR", "/data/v0_1")
        self.train_ann = "instances_train.json"
        self.val_ann = "instances_val.json"

        # bootstrap data is heterogeneous (rigid cartons + flexible sacks) — keep strong aug
        self.mosaic_prob = 1.0
        self.mixup_prob = 0.5
        self.hsv_prob = 1.0
        self.flip_prob = 0.5
        self.exp_name = "stock_object_v0_1"
