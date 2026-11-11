#!/usr/bin/env python3
"""Pull the Mortez products from Printful and write site/data/merch.js.

The token never lives in this repo. Pass it in the PRINTFUL_TOKEN environment variable,
or put it in a file and point PRINTFUL_TOKEN_FILE at it.

    PRINTFUL_TOKEN_FILE=~/.mortez/printful-token python3 scripts/printful_sync.py

Product photos are saved to site/media/merch/ when the image hosts can be reached;
otherwise the original image addresses are kept so the site still shows them.
"""
import json, os, re, sys, urllib.request, urllib.error, hashlib, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT_JS = ROOT / "site" / "data" / "merch.js"
IMG_DIR = ROOT / "site" / "media" / "merch"
API = "https://api.printful.com"

# Products to leave off the site (Printful sync product ids). Hidden here, still for sale on Printful.
HIDE = set()


def token():
    t = os.environ.get("PRINTFUL_TOKEN")
    if not t and os.environ.get("PRINTFUL_TOKEN_FILE"):
        t = pathlib.Path(os.path.expanduser(os.environ["PRINTFUL_TOKEN_FILE"])).read_text().strip()
    if not t:
        sys.exit("Set PRINTFUL_TOKEN or PRINTFUL_TOKEN_FILE.")
    return t


def get(path, tok):
    req = urllib.request.Request(API + path, headers={"Authorization": "Bearer " + tok, "User-Agent": "mortez-sync"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r)["result"]


SIZE_ORDER = ["XS", "S", "M", "L", "XL", "2XL", "3XL", "4XL", "5XL", "6XL", "7XL"]


def size_key(s):
    return (SIZE_ORDER.index(s), s) if s in SIZE_ORDER else (99, s)


def kind_of(name):
    n = name.lower()
    for k, words in [("candle", ["candle"]), ("case", ["iphone", "case"]), ("jacket", ["jacket"]), ("hoodie", ["hoodie"]),
                     ("sweater", ["sweatshirt", "crewneck", "sweater"]), ("tank", ["tank"]), ("hat", ["beanie", "cap"]),
                     ("skirt", ["skirt"]), ("joggers", ["jogger"]), ("tee", ["t-shirt", "tee", "short sleeve", "long sleeve"])]:
        if any(w in n for w in words):
            return k
    return "other"


def tidy(name):
    name = re.sub(r"^\s*mortez\s*[-–]\s*", "", name, flags=re.I).strip()
    name = re.sub(r"\bmortez\b", "Mortez", name)
    return re.sub(r"\s+", " ", name.replace("®", ""))


def split_variant(vname, pname):
    """'Hoodie / Washed Black / M' -> ('Washed Black', 'M')"""
    rest = vname[len(pname):] if vname.startswith(pname) else vname
    parts = [p.strip() for p in rest.split("/") if p.strip()]
    if not parts:
        return None, None
    if len(parts) == 1:
        return (None, parts[0]) if parts[0] in SIZE_ORDER else (parts[0], None)
    return parts[-2], parts[-1]


def save_image(url, stem):
    if not url:
        return None
    ext = ".png" if url.lower().split("?")[0].endswith(".png") else ".jpg"
    for e in (".jpg", ext):
        if (IMG_DIR / (stem + e)).exists():
            return "media/merch/" + stem + e
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "mortez-sync"})
        with urllib.request.urlopen(req, timeout=30) as r:
            data = r.read()
        IMG_DIR.mkdir(parents=True, exist_ok=True)
        dest = IMG_DIR / (stem + ".jpg")
        try:  # shrink to 900px JPEGs; transparent design files go on the shop's dark ground
            from PIL import Image
            import io
            im = Image.open(io.BytesIO(data))
            if im.mode in ("RGBA", "LA", "P"):
                im = im.convert("RGBA"); bg = Image.new("RGB", im.size, (22, 20, 26)); bg.paste(im, mask=im.split()[-1]); im = bg
            im = im.convert("RGB"); im.thumbnail((900, 900))
            im.save(dest, quality=84, optimize=True, progressive=True)
        except ImportError:
            dest = IMG_DIR / (stem + ext); dest.write_bytes(data)
        return "media/merch/" + dest.name
    except Exception:
        return url  # host unreachable from here: keep the original address


def main():
    tok = token()
    listing = get("/sync/products?limit=100", tok)
    products = []
    for item in listing:
        if item["is_ignored"] or item["id"] in HIDE:
            continue
        d = get(f"/sync/products/{item['id']}", tok)
        sp, vs = d["sync_product"], d["sync_variants"]
        variants, colors, sizes, previews = [], [], [], []
        for v in vs:
            color, size = v.get("color"), v.get("size")
            if not color and not size:
                color, size = split_variant(v["name"], sp["name"])
            price = float(v["retail_price"])
            variants.append({"id": v["id"], "size": size, "color": color, "price": price})
            if color and color not in colors:
                colors.append(color)
            if size and size not in sizes:
                sizes.append(size)
            for f in v.get("files") or []:
                if f.get("type") == "preview" and f.get("preview_url") and f["preview_url"] not in previews:
                    previews.append(f["preview_url"])
        prices = [v["price"] for v in variants]
        if len(sizes) > 1 and all(s in SIZE_ORDER for s in sizes):
            sizes.sort(key=size_key)
        if sizes == ["One size"]:
            sizes = []
        name = tidy(sp["name"])
        kind = kind_of(name)
        first = vs[0].get("product") or {}
        if kind == "candle":
            d_ = f"9 oz soy candle. Scent: {colors[0]}." if colors else "9 oz soy candle."
            sizes = []
        elif kind == "case":
            d_ = "Clear case with the Mortez design, for " + ", ".join(sizes[:3]) + (" and more." if len(sizes) > 3 else ".")
        else:
            cl = (", ".join(colors[:-1]) + " and " + colors[-1]) if len(colors) > 1 else (colors[0] if colors else "")
            d_ = (("Comes in " + cl + ". ") if cl else "") + ("Sizes " + sizes[0] + " to " + sizes[-1] + "." if len(sizes) > 1 else "")
        stem = "p" + str(sp["id"])
        images = [save_image(sp.get("thumbnail_url"), stem)]
        for i, u in enumerate(previews[:3]):
            images.append(save_image(u, f"{stem}-{i + 1}"))
        products.append({
            "id": stem, "name": name, "kind": kind, "price": min(prices), "priceMax": max(prices),
            "sizes": sizes, "colors": colors, "d": d_.strip(), "images": [i for i in images if i],
            "variants": variants, "printful": bool(item["synced"]),
        })
    OUT_JS.write_text("// Generated by scripts/printful_sync.py from the band's Printful store. Do not edit by hand: rerun the script.\n"
                      "window.MORTEZ_MERCH = " + json.dumps(products, ensure_ascii=False, indent=1) + ";\n")
    local = sum(1 for p in products for i in p["images"] if i.startswith("media/"))
    print(f"{len(products)} products written to {OUT_JS.relative_to(ROOT)}; {local} photos saved locally")


if __name__ == "__main__":
    main()
