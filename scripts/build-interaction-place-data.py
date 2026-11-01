#!/usr/bin/env python3
"""Build the offline, approximate place lookup used by MessageMonkey's map.

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

cities = {}
for feature in features("ne_10m_populated_places_simple.geojson"):
    properties = feature["properties"]
    code = properties.get("iso_a2", "")
    name = properties.get("name", "")
    if code not in countries or not name:
        continue
    longitude, latitude = feature["geometry"]["coordinates"]
    row = [properties.get("adm1name") or "", coordinate(longitude), coordinate(latitude),
           int(properties.get("pop_max") or 0)]
    for spelling in {name, properties.get("nameascii") or name}:
        candidates = cities.setdefault(code, {}).setdefault(spelling.casefold(), [])
        if row not in candidates:
            candidates.append(row)

for country in cities.values():
    for candidates in country.values():
        candidates.sort(key=lambda row: -row[3])

OUTPUT.write_text(json.dumps({"countries": dict(sorted(countries.items())),
                              "cities": {code: dict(sorted(names.items())) for code, names in sorted(cities.items())}},
                             ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
print(f"Wrote {len(countries)} countries and {sum(map(len, cities.values()))} city names to {OUTPUT}")
