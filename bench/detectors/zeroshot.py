"""
Zero-shot / open-vocabulary detector — RESEARCH & PRE-LABELLING ONLY.

  ┌─────────────────────────────────────────────────────────────────────────────────────────┐
  │  THIS IS NOT A PRODUCTION DETECTOR AND MUST NOT SHIP IN THE COMMERCIAL EDGE IMAGE.         │
  │  Its outputs are LABEL PROPOSALS a human must correct. They are never ground truth, and    │
  │  benchmark accuracy is NEVER computed against them. See bench/MODEL_LICENSES.md.           │
  └─────────────────────────────────────────────────────────────────────────────────────────┘

Purpose: turn the first raw warehouse clips into *proposed* carton boxes fast, so a human can correct
them into a verified ground-truth set (AI proposal → human correction → verified GT). That verified
set — not this model — is the asset and the yardstick.

Default candidate: Grounding DINO (IDEA-Research, Apache-2.0 code + weights; commercial use asserted by
the authors, provenance still to be logged in MODEL_LICENSES.md). YOLO-World is deliberately NOT wired
here: its official repo is GPL-3.0, so it is excluded from anything that could touch production.

The heavy deps (torch, groundingdino) are imported lazily and only inside ``load()`` — importing this
module costs nothing and never drags a model into the process. If they are absent the harness still
runs everything else; only pre-labelling is unavailable.
"""
from __future__ import annotations

RESEARCH_ONLY = True
CANDIDATE = "grounding-dino"
DEFAULT_PROMPT = "cardboard box . carton . box on pallet . stacked boxes"


class ZeroShotDetector:
    """Open-vocabulary detector for PRE-LABELLING only. Emits carton-class detections for a text
    prompt. Not pipeline-scored as truth; used by the pre-label tool to seed a human's corrections."""

    def __init__(self, prompt: str = DEFAULT_PROMPT, box_threshold: float = 0.35,
                 text_threshold: float = 0.25, carton_class_id: int = 1):
        self.prompt = prompt
        self.box_threshold = box_threshold
        self.text_threshold = text_threshold
        self.carton_class_id = carton_class_id
        self._model = None
        self.name = f"zeroshot:{CANDIDATE}"

    def load(self):
        """Lazily construct the underlying open-vocabulary model. Kept as a single seam so a different
        research candidate (also license-cleared) can be swapped without touching the pre-label tool.
        Raises a clear error, never a silent import failure, if the research deps are not installed."""
        try:
            from groundingdino.util.inference import Model  # type: ignore
        except Exception as exc:  # pragma: no cover - depends on optional research deps
            raise RuntimeError(
                "Zero-shot pre-labelling needs the research deps (torch + groundingdino), which are "
                "intentionally NOT part of the production image. Install them in a research venv only. "
                f"Underlying import error: {exc}"
            )
        # NOTE: config + checkpoint paths are environment-specific; set them via env or a research
        # config file. Left explicit rather than hard-coded so no weight path is assumed here.
        import os

        cfg = os.environ.get("GDINO_CONFIG")
        ckpt = os.environ.get("GDINO_CHECKPOINT")
        if not cfg or not ckpt:
            raise RuntimeError(
                "Set GDINO_CONFIG and GDINO_CHECKPOINT to your license-logged Grounding DINO config "
                "and checkpoint (research only). See bench/MODEL_LICENSES.md before downloading weights."
            )
        self._model = Model(model_config_path=cfg, model_checkpoint_path=ckpt)
        return self

    def propose(self, frame):
        """Return PROPOSED carton boxes [(box, class_id, confidence)] for a human to correct.
        Named `propose`, not `detect`, so it can never be mistaken for a scored pipeline detector."""
        if self._model is None:
            self.load()
        import supervision as sv  # noqa: F401  (Model returns sv.Detections)

        detections, _ = self._model.predict_with_caption(
            image=frame, caption=self.prompt,
            box_threshold=self.box_threshold, text_threshold=self.text_threshold,
        )
        out = []
        for i in range(len(detections)):
            box = tuple(float(v) for v in detections.xyxy[i])
            conf = float(detections.confidence[i]) if detections.confidence is not None else 0.0
            out.append((box, self.carton_class_id, conf))
        return out
