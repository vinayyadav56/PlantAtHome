# India geo master dataset (delivery coverage)

- `india_postal_codes.csv.gz` — 19,238 rows, one per unique 6-digit pincode.
  Columns: `pincode, state, district, office_name, latitude, longitude, offices`
  (`offices` = trimmed json array of up to 6 `{name, taluk}` delivery offices).
  District chosen as the DOMINANT district (most post offices) when a pincode
  spans several; lat/lng = mean of that district's offices.
- `india_districts.csv` — 638 rows (`state, district`), derived from the same
  source.
- `state_name_map.php` — dataset state name → canonical `states.name`.

Source: GeoNames postal-code export for IN (https://download.geonames.org/export/zip/IN.zip),
CC-BY 4.0, derived from the India Post directory. 155,570 office rows
aggregated on 2026-07-15.

Known limitation: the export carries no separate Ladakh state — Leh/Kargil
pincodes appear under Jammu and Kashmir.

## Refresh procedure

1. Download a fresh `IN.zip`, unzip to `IN.txt`.
2. Re-run the aggregation (same rules as above: 6-digit pins only, dominant
   district, ≤6 trimmed offices) to regenerate both CSVs.
3. `php artisan plantathome:pincodes-import` (uses the bundled gz by default,
   or pass an explicit path). The import is idempotent — upsert keyed on
   `(country_id, pincode)`.
