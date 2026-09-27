# Amazon Location maps

The map and pin editor support Amazon Location's current Maps API using the
existing MapLibre renderer. This changes the background map only. Cached pins,
manual corrections, and Nominatim/Geoapify address lookup remain independent.

## AWS configuration

Create an Amazon Location API key in the region used by the application:

- Name: `moed-maps-dev`
- Region: `us-east-2` (Ohio)
- Maps action: `GetTile`
- Resource: `arn:aws:geo-maps:us-east-2::provider/default`
- Allowed referrers: `https://moed.beneliath.com/*` and `http://localhost:8080/*`
- Expiration selected for this setup: December 31, 2028 (UTC)

Use a separate key for deployments that need different referrers or expiration.
Keep AWS/provider attribution visible in MapLibre's attribution control.

## Local configuration

Save the `v1.public.` key as plain text in `secrets/amazon_location_api_key`.
The directory is excluded from Git and Docker build context. Keep the file
owner-readable only (`chmod 600 secrets/amazon_location_api_key`). For Linux
deployments, grant the web container's UID 33 read access through a file ACL,
as for the other web service secrets.

Optional `.env` settings:

```dotenv
DNR_MAP_AMAZON_REGION=us-east-2
DNR_MAP_AMAZON_STYLE=Standard
DNR_MAP_AMAZON_API_KEY_FILE=./secrets/amazon_location_api_key
```

The deployment wrapper includes `docker-compose.amazon-maps.yaml` whenever the
default key file is nonempty. This overlay mounts the key only into `web`.
Custom key paths must also be exported to the wrapper's environment so its
file detection uses the same path as Compose.

For an existing local development stack, apply the web configuration with:

```sh
./scripts/compose_with_provenance.sh development up -d --no-deps web
```

The development overlay mounts the current source. Production requires a
qualified image built from the integration branch and the normal release
workflow; do not use the local development command for production.

For direct Compose usage, include `-f docker-compose.amazon-maps.yaml` alongside
the existing base and deployment overlays. With non-Compose PHP, set
`DNR_MAP_PROVIDER=amazon`, `DNR_MAP_AMAZON_REGION`, `DNR_MAP_AMAZON_STYLE`, and
`DNR_MAP_AMAZON_API_KEY_FILE` to an absolute readable key path.

The key is intentionally sent to authenticated users' browsers to request maps.
It is not an AWS IAM access key. Referrer and action restrictions limit its use;
map requests are metered by AWS. Never commit the real key.

## Verification and rollback

Open `http://localhost:8080/map.php` while signed in. Check labels and map tiles,
zoom/pan, engagement pins, popups, and the pin editor. Confirm AWS requests use
`maps.geo.us-east-2.amazonaws.com` and return successfully. A 403 usually needs
checking key expiration, region, GetTile permission, and the exact page origin.
A CSP error needs checking that the Amazon overlay is active. Empty pins do not
necessarily mean a tile failure: address lookup runs separately.

To return to the configured raster tiles, run the same wrapper command with
`DNR_MAP_PROVIDER=openstreetmap` exported for that invocation. Do not include
the Amazon overlay when using Compose directly.

References: [AWS API keys](https://docs.aws.amazon.com/location/latest/developerguide/using-apikeys.html)
and [MapLibre map display](https://docs.aws.amazon.com/location/latest/developerguide/how-to-display-a-map.html).
