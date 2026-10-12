"""Builds install/gazetteer/us-places.tsv and install/gazetteer/us-zips.tsv from the
US Census Bureau's 2023 Gazetteer files (public domain):

  https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2023_Gazetteer/2023_Gaz_place_national.zip
  https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2023_Gazetteer/2023_Gaz_zcta_national.zip

Unzip both next to this script and run:  python build-gazetteer.py

us-places.tsv: state, place key, latitude, longitude. The key is the place
name without its Census descriptor ("city", "CDP", "(balance)"...),
normalised exactly as app/geo.php geo_place_key() normalises what people
type. Where one state has two places with the same key, the larger land
area wins. Consolidated governments ("Nashville-Davidson metropolitan
government") also answer to their first name ("nashville").

us-zips.tsv: ZIP code (ZCTA), latitude, longitude.
"""
import os, re, unicodedata

HERE = os.path.dirname(os.path.abspath(__file__))
SUFFIX = re.compile(r'\s+(city and borough|city|town|village|borough|CDP|comunidad|zona urbana|municipality|township|corporation|urban county'
                    r'|(?:unified|consolidated|metropolitan|metro) government)$')
GOVERNMENT = re.compile(r'(\(balance\)|government|urban county|County)$')
WORDS = {'st': 'saint', 'ste': 'sainte', 'ft': 'fort', 'mt': 'mount'}


def place_key(name):
    s = unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode().lower()
    s = re.sub(r"[.']", '', s)
    s = re.sub(r'[-/,]', ' ', s)
    return ' '.join(WORDS.get(w, w) for w in s.split())


def strip(name):
    name = re.sub(r'\s*\(balance\)$', '', name)
    for _ in range(2):
        name = SUFFIX.sub('', name)
    return re.sub(r'\s+County$', '', name) if ' County' in name and ('-' in name or '/' in name) else name


places = {}
with open(os.path.join(HERE, '2023_Gaz_place_national.txt'), encoding='latin-1') as f:
    next(f)
    for line in f:
        c = line.rstrip().split('\t')
        state, name, land, lat, lng = c[0], c[3], int(c[6]), c[10], c[11]
        keys = {place_key(strip(name))}
        if GOVERNMENT.search(name.strip()) and re.search(r'[-/,]', name):
            keys.add(place_key(re.split(r'[-/,]', name)[0]))
        for key in keys:
            if key and ((state, key) not in places or land > places[(state, key)][0]):
                places[(state, key)] = (land, float(lat), float(lng))
with open(os.path.join(HERE, 'us-places.tsv'), 'w', encoding='utf-8', newline='\n') as out:
    for (state, key), (_, lat, lng) in sorted(places.items()):
        out.write('%s\t%s\t%.5f\t%.5f\n' % (state, key, lat, lng))

with open(os.path.join(HERE, '2023_Gaz_zcta_national.txt'), encoding='latin-1') as f, \
        open(os.path.join(HERE, 'us-zips.tsv'), 'w', encoding='utf-8', newline='\n') as out:
    next(f)
    for line in f:
        c = line.rstrip().split('\t')
        out.write('%s\t%.5f\t%.5f\n' % (c[0], float(c[5]), float(c[6])))
print(len(places), 'places')
