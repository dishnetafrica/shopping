"""
Class normalization — the intellectual core of Track-A dataset assembly.

Every bootstrap source names its classes differently ("cardboard box", "rice bag", "small load
carrier", "pallet", …). This maps them all onto ShopBot's taxonomy:

  • PRIMARY label = `stock_object` — what the movement pipeline actually needs (a physical package
    crossed). ShopBot Stock Object Detector v0.1 trains on THIS single class (generic-first, per
    docs/vision-stock-object-taxonomy.md).
  • ADVISORY subclass (carton/box/bag/sack/parcel/pallet/wrapped_package/container) is retained in the
    dataset labels so a later coarse→fine head can emit `object_type` — but it is NOT what v0.1 detects.
  • NEGATIVE classes (person, forklift, shelf, …) map to None → they become background/negatives, the
    critical "person carrying nothing" guard. They are never `stock_object`.

Pure stdlib. Deterministic. Unit-tested in selftest.py.
"""
from __future__ import annotations

PRIMARY = "stock_object"

# advisory subclass -> the synonyms (lowercased) that map to it, across all approved sources
_SUBCLASS_SYNONYMS = {
    "carton":         {"carton", "cardboard box", "cardboard", "cardboardbox", "carton box", "fmcg carton"},
    "box":            {"box", "boxes", "package box", "packing box", "shipping box"},
    "bag":            {"bag", "bags", "plastic bag", "shopping bag", "poly bag"},
    "sack":           {"sack", "sacks", "rice bag", "rice bags", "sugar bag", "flour bag", "gunny",
                       "gunny bag", "grain sack", "rice", "rice sack"},
    "parcel":         {"parcel", "parcels", "package", "packages", "postal parcel", "courier parcel"},
    "pallet":         {"pallet", "pallets", "loaded pallet", "wooden pallet"},
    "wrapped_package": {"wrapped package", "shrink wrap", "shrink-wrapped", "wrapped", "stretch wrapped",
                        "plastic wrapped", "wrapped goods"},
    "container":      {"container", "small load carrier", "small_load_carrier", "slc", "tote", "crate",
                       "bin", "kli", "klt"},
}

# things that are NOT stock objects — mapped out so they become negatives, never a package
_NEGATIVES = {
    "person", "people", "worker", "human", "pedestrian",
    "forklift", "fork lift", "pallet truck", "pallet jack", "hand truck", "trolley", "cart",
    "stillage", "rack", "racking", "shelf", "shelves", "gondola", "door", "wall", "floor",
    "machinery", "machine", "conveyor", "vehicle", "truck", "car", "backpack", "handbag", "suitcase",
}

# build the reverse index once
_SYN_TO_SUB = {}
for _sub, _syns in _SUBCLASS_SYNONYMS.items():
    for _s in _syns:
        _SYN_TO_SUB[_s] = _sub


def normalize_name(raw: str) -> str:
    return (raw or "").strip().lower().replace("_", " ").replace("-", " ").strip()


def remap(source_class: str) -> tuple[str, str] | None:
    """Map a source class name to (PRIMARY, advisory_subclass), or None if it is a negative/ignored.

    Unknown class names return None (treated as negative) rather than being force-fit into
    `stock_object` — a mystery label must never silently inflate the positive set."""
    name = normalize_name(source_class)
    if not name:
        return None
    if name in _NEGATIVES:
        return None
    # exact synonym hit
    if name in _SYN_TO_SUB:
        return (PRIMARY, _SYN_TO_SUB[name])
    # token containment (e.g. "closed carton", "big rice bag") — first matching subclass wins
    tokens = set(name.split())
    for syn, sub in _SYN_TO_SUB.items():
        st = set(syn.split())
        if st and st.issubset(tokens):
            return (PRIMARY, sub)
    # a bare "object"/"stock"/"item"/"goods" is a generic package
    if tokens & {"object", "stock", "item", "goods", "packet", "load"}:
        return (PRIMARY, "box")
    return None


# The single class v0.1 actually trains on. Advisory subclasses live in the dataset for later.
V01_CLASSES = [PRIMARY]
ADVISORY_SUBCLASSES = list(_SUBCLASS_SYNONYMS.keys())
