#!/bin/sh
set -eu
# This job may publish candidates; only its successful CI artifact qualifies them.
: "${GITHUB_REPOSITORY:?}" "${GITHUB_SHA:?}"
[ "$(git rev-parse HEAD)" = "$GITHUB_SHA" ]
registry_repository=$(printf '%s' "$GITHUB_REPOSITORY" | tr '[:upper:]' '[:lower:]')
version=$(cat VERSION)
build_timestamp=$(git show -s --format=%cI "$GITHUB_SHA" | sed 's/+00:00$/Z/')
mkdir -p release
for kind in app ingress bridge database; do
    image="ghcr.io/$registry_repository/$kind:$GITHUB_SHA"
    case "$kind" in
        app) dockerfile=Dockerfile ;;
        ingress) dockerfile=docker/ingress.Dockerfile ;;
        bridge) dockerfile=docker/proton-bridge.Dockerfile ;;
        database) dockerfile=docker/mysql.Dockerfile ;;
    esac
    if docker manifest inspect "$image" > /dev/null 2> release/registry-error.log; then
        docker pull "$image"
    else
        # An unavailable registry is not evidence that a build is missing.
        grep -Eiq 'manifest unknown|no such manifest|manifest.*not found' release/registry-error.log || {
            cat release/registry-error.log >&2; exit 1;
        }
        docker build --platform linux/amd64 --file "$dockerfile" --tag "$image" \
            --label "org.opencontainers.image.revision=$GITHUB_SHA" \
            --label "org.opencontainers.image.version=$version" \
            --label "org.opencontainers.image.source=https://github.com/$GITHUB_REPOSITORY" \
            --build-arg "DNR_BUILD_COMMIT=$GITHUB_SHA" \
            --build-arg "DNR_BUILD_TIMESTAMP=$build_timestamp" .
        docker push "$image"
        docker pull "$image"
    fi
    [ "$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")" = "$GITHUB_SHA" ]
    docker inspect --format '{{index .RepoDigests 0}}' "$image" > "release/$kind.digest"
done
python3 - <<'PY'
import json
from pathlib import Path
p = Path('release')
images = {kind: (p / (kind + '.digest')).read_text().strip() for kind in ('app', 'ingress', 'bridge', 'database')}
(p / 'images.json').write_text(json.dumps(images, indent=2) + '\n')
with open('release/images.env', 'w') as env:
    for kind, image in images.items(): env.write(f'DNR_{kind.upper()}_IMAGE={image}\n')
PY
