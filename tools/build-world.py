#!/usr/bin/env python3
"""Build data/world.json, the base map of the plugin, from Natural Earth.

usage: tools/build-world.py <ne_110m_admin_0_countries.geojson>

Source: Natural Earth 1:110m Cultural Vectors, Admin 0 - Countries
(https://www.naturalearthdata.com, public domain). The countries are projected
with the Equal Earth projection (Šavrič, Patterson & Jenny, 2018), simplified
and written as SVG path data, keyed by ISO 3166-1 alpha-2, the code OJS stores
in metrics_submission_geo_daily.country. Antarctica is left out: nobody reads
from there and it would take a fifth of the height.

Countries too small for the 1:110m scale get a dot at their capital
(TINY below), so a visit from Singapore or Malta still shows on the map.
"""
import json
import math
import sys

WIDTH = 1000.0
TOLERANCE = 0.35  # Douglas-Peucker tolerance, in map units

# Natural Earth draws these apart from the country OJS reports for them.
MERGE = {'N. Cyprus': 'CY', 'Somaliland': 'SO'}
SKIP = {'AQ', 'TF'}

# Countries and territories missing at 1:110m: capital, as (longitude, latitude).
TINY = {
    'AD': (1.52, 42.51), 'AG': (-61.85, 17.12), 'AI': (-63.06, 18.22), 'AS': (-170.70, -14.28),
    'AW': (-70.03, 12.52), 'BB': (-59.61, 13.10), 'BH': (50.58, 26.23), 'BM': (-64.78, 32.29),
    'BQ': (-68.27, 12.15), 'CV': (-23.51, 14.93), 'CW': (-68.93, 12.11), 'DM': (-61.39, 15.30),
    'FM': (158.16, 6.92), 'FO': (-6.77, 62.01), 'GD': (-61.75, 12.06), 'GF': (-52.33, 4.94),
    'GG': (-2.54, 49.45), 'GI': (-5.35, 36.14), 'GP': (-61.53, 16.24), 'GU': (144.79, 13.44),
    'HK': (114.17, 22.32), 'IM': (-4.48, 54.15), 'JE': (-2.13, 49.21), 'KI': (173.03, 1.45),
    'KM': (43.26, -11.70), 'KN': (-62.72, 17.30), 'KY': (-81.38, 19.29), 'LC': (-60.98, 14.01),
    'LI': (9.52, 47.14), 'MC': (7.42, 43.74), 'MF': (-63.08, 18.07), 'MH': (171.38, 7.09),
    'MO': (113.54, 22.20), 'MP': (145.75, 15.21), 'MQ': (-61.07, 14.60), 'MS': (-62.19, 16.74),
    'MT': (14.51, 35.90), 'MU': (57.50, -20.16), 'MV': (73.51, 4.18), 'NR': (166.92, -0.55),
    'NU': (-169.87, -19.05), 'PF': (-149.57, -17.54), 'PW': (134.62, 7.50), 'RE': (55.45, -20.88),
    'SC': (55.45, -4.62), 'SG': (103.82, 1.35), 'SM': (12.45, 43.94), 'ST': (6.73, 0.34),
    'SX': (-63.05, 18.03), 'TC': (-71.14, 21.46), 'TO': (-175.20, -21.14), 'TV': (179.19, -8.52),
    'VA': (12.45, 41.90), 'VC': (-61.23, 13.16), 'VG': (-64.62, 18.43), 'VI': (-64.93, 18.34),
    'WF': (-176.20, -13.28), 'WS': (-171.77, -13.83), 'YT': (45.23, -12.78), 'PM': (-56.18, 46.78),
    'BL': (-62.85, 17.90), 'CK': (-159.78, -21.21), 'NF': (167.95, -29.04), 'CX': (105.69, -10.45),
    'CC': (96.83, -12.19), 'IO': (72.42, -7.33), 'SH': (-5.72, -15.93), 'AX': (19.94, 60.10),
}

A1, A2, A3, A4 = 1.340264, -0.081106, 0.000893, 0.003796
M = math.sqrt(3) / 2


def equal_earth(lon, lat):
    lam = math.radians(lon)
    theta = math.asin(M * math.sin(math.radians(lat)))
    t2 = theta * theta
    t6 = t2 * t2 * t2
    x = 2 * math.sqrt(3) * lam * math.cos(theta) / (3 * (9 * A4 * t6 * t2 + 7 * A3 * t6 + 3 * A2 * t2 + A1))
    y = A4 * t6 * t2 * theta + A3 * t6 * theta + A2 * t2 * theta + A1 * theta
    return x, y


X_MAX, _ = equal_earth(180, 0)
_, Y_TOP = equal_earth(0, 84)
_, Y_BOTTOM = equal_earth(0, -58)
SCALE = WIDTH / (2 * X_MAX)
HEIGHT = (Y_TOP - Y_BOTTOM) * SCALE


def to_map(lon, lat):
    x, y = equal_earth(lon, max(min(lat, 84), -58))
    return (x + X_MAX) * SCALE, (Y_TOP - y) * SCALE


def simplify(points, tolerance):
    if len(points) < 4:
        return points
    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]
    while stack:
        first, last = stack.pop()
        (x1, y1), (x2, y2) = points[first], points[last]
        dx, dy = x2 - x1, y2 - y1
        length = math.hypot(dx, dy)
        index, distance = None, tolerance
        for i in range(first + 1, last):
            x0, y0 = points[i]
            # A closed ring starts and ends on the same point: measure from it.
            d = abs(dy * x0 - dx * y0 + x2 * y1 - y2 * x1) / length if length else math.hypot(x0 - x1, y0 - y1)
            if d > distance:
                index, distance = i, d
        if index is not None:
            keep[index] = True
            stack += [(first, index), (index, last)]
    return [p for p, k in zip(points, keep) if k]


def ring_path(ring):
    points = simplify([to_map(lon, lat) for lon, lat in ring], TOLERANCE)
    if len(points) < 3:
        return ''
    rounded = [(round(x, 1), round(y, 1)) for x, y in points]
    out = ['M%s %s' % fmt(rounded[0])]
    previous = rounded[0]
    for point in rounded[1:-1]:
        delta = (round(point[0] - previous[0], 1), round(point[1] - previous[1], 1))
        if delta != (0, 0):
            out.append('l%s %s' % fmt(delta))
        previous = point
    return ''.join(out) + 'z'


def fmt(pair):
    return tuple(('%g' % v) for v in pair)


def area(ring):
    return abs(sum(x1 * y2 - x2 * y1 for (x1, y1), (x2, y2) in zip(ring, ring[1:]))) / 2


def main(path):
    features = json.load(open(path, encoding='utf-8'))['features']
    countries = {}
    for feature in features:
        props = feature['properties']
        code = MERGE.get(props['NAME']) or props.get('ISO_A2_EH')
        if not code or code == '-99' or code in SKIP:
            continue
        geometry = feature['geometry']
        polygons = geometry['coordinates'] if geometry['type'] == 'MultiPolygon' else [geometry['coordinates']]
        parts = []
        for polygon in polygons:
            for ring in polygon:
                projected = [to_map(lon, lat) for lon, lat in ring]
                if area(projected) < 0.5:
                    continue
                part = ring_path(ring)
                if part:
                    parts.append(part)
        if parts:
            countries[code] = countries.get(code, '') + ''.join(parts)

    tiny = {}
    for code, (lon, lat) in sorted(TINY.items()):
        if code not in countries:
            x, y = to_map(lon, lat)
            tiny[code] = [round(x, 1), round(y, 1)]

    world = {
        'source': 'Natural Earth 1:110m Admin 0 - Countries (public domain), Equal Earth projection',
        'width': WIDTH,
        'height': round(HEIGHT, 1),
        'countries': dict(sorted(countries.items())),
        'tiny': tiny,
    }
    json.dump(world, open('data/world.json', 'w', encoding='utf-8'), separators=(',', ':'), ensure_ascii=False)
    size = sum(len(d) for d in countries.values())
    print('countries: %d, tiny: %d, height: %.1f, path bytes: %d' % (len(countries), len(tiny), HEIGHT, size))


if __name__ == '__main__':
    main(sys.argv[1])
