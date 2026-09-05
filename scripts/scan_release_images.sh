#!/bin/sh
set -eu
scanner=aquasec/trivy@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969
mkdir -p release/scans
for kind in app ingress bridge database; do
    image=$(cat "release/$kind.digest")
    # Save the image rather than sharing the Docker daemon socket with the scanner.
    docker save "$image" > release/scan-image.tar
    docker run --rm -v "$PWD/release:/scan" "$scanner" image --input /scan/scan-image.tar \
        --scanners vuln --format json --output "/scan/scans/$kind.vulnerabilities.json"
    docker run --rm -v "$PWD/release:/scan" "$scanner" image --input /scan/scan-image.tar \
        --format cyclonedx --output "/scan/scans/$kind.sbom.json"
    # Keep the complete report, including unfixed advisories; block fixable high/critical findings.
    python3 - "$kind" <<'PY'
import json, sys
from pathlib import Path
report=json.loads(Path('release/scans/' + sys.argv[1] + '.vulnerabilities.json').read_text())
blocked=[v for r in report.get('Results', []) for v in r.get('Vulnerabilities', [])
         if v['Severity'] in ('HIGH', 'CRITICAL') and v.get('FixedVersion')]
for v in blocked: print(v['VulnerabilityID'], v['PkgName'], v['InstalledVersion'], '->', v['FixedVersion'])
if blocked: sys.exit('Image has fixable HIGH/CRITICAL vulnerabilities')
PY
    rm release/scan-image.tar
done
