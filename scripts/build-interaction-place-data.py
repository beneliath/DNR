#!/usr/bin/env python3
"""Build the offline country label points used by MessageMonkey's map.

Natural Earth data is public domain:
https://github.com/nvkelso/natural-earth-vector/tree/master/geojson
The generated JSON is committed so report rendering never makes a geocoder request.
"""

import json
from pathlib import Path
from urllib.request import urlopen


BASE = "https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/"
OUTPUT = Path(__file__).resolve().parents[1] / "src/assets/data/interaction-place-coordinates.json"


def features(name):
    with urlopen(BASE + name, timeout=30) as response:
        return json.load(response)["features"]


def coordinate(value):
    return round(float(value), 3)


countries = {}
for feature in features("ne_50m_admin_0_countries.geojson"):
    properties = feature["properties"]
    code = properties.get("ISO_A2_EH", "")
    if len(code) != 2 or not code.isalpha():
        continue
    countries[code] = [coordinate(properties["LABEL_X"]), coordinate(properties["LABEL_Y"])]

OUTPUT.write_text(json.dumps({"countries": dict(sorted(countries.items()))}, separators=(",", ":")) + "\n",
                  encoding="utf-8")
print(f"Wrote {len(countries)} country label points to {OUTPUT}")
