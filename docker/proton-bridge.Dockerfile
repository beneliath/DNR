FROM golang:1.27.1-alpine@sha256:cf6fca6641884b8433441b2b0652976f975e1d0fdd26d177eaaf8596087f3125 AS go-toolchain

FROM ubuntu:26.04@sha256:2260313b31c8c011cd2eebe728008efac1b3982be73eb71348ea2648d2c0e09b AS bridge-builder
COPY --from=go-toolchain /usr/local/go /usr/local/go
ENV PATH="/usr/local/go/bin:${PATH}" GOTOOLCHAIN=local
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl build-essential pkg-config libfido2-dev libsecret-1-dev python3 \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /build
# Keep this archive and the package below at the same version. Upgrades require
# reviewing the adapter against the upstream metadata flags and header builder.
RUN curl -fsSL https://codeload.github.com/ProtonMail/proton-bridge/tar.gz/refs/tags/v3.25.0 -o bridge.tar.gz \
    && echo 'a73fea9d1868f59c36852f438e3f8a2d96a3c4e26822382f0be466edc7021bd5  bridge.tar.gz' | sha256sum --check --strict \
    && python3 -c "import tarfile; tarfile.open('bridge.tar.gz').extractall(filter='data')" && rm bridge.tar.gz
WORKDIR /build/proton-bridge-3.25.0
COPY docker/proton-bridge-auth/ /adapter/
RUN python3 /adapter/install.py && cp /adapter/*.go pkg/message/
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go test ./pkg/message \
    && (cd utils && ./credits.sh bridge) \
    && go build -trimpath -ldflags='-s -w -X "github.com/ProtonMail/proton-bridge/v3/internal/constants.FullAppName=Proton Mail Bridge" -X github.com/ProtonMail/proton-bridge/v3/internal/constants.Version=3.25.0+dnr.1 -X github.com/ProtonMail/proton-bridge/v3/internal/constants.Revision=dnr-auth-v1 -X github.com/ProtonMail/proton-bridge/v3/internal/constants.Tag=v3.25.0 -X github.com/ProtonMail/proton-bridge/v3/internal/constants.BuildEnv=live' \
        -o /build/dnr-bridge ./cmd/Desktop-Bridge

FROM ubuntu:26.04@sha256:2260313b31c8c011cd2eebe728008efac1b3982be73eb71348ea2648d2c0e09b

ARG PROTON_BRIDGE_VERSION=3.25.0-1
ARG PROTON_BRIDGE_SHA256=6b0318f4f425ef1a19b63e2bd589bc1036d95f073cb9ac26b42c0fc63a8bc275

LABEL org.opencontainers.image.title="DNR Proton Mail Bridge sidecar" \
      org.opencontainers.image.source="https://github.com/ProtonMail/proton-bridge" \
      org.opencontainers.image.version="${PROTON_BRIDGE_VERSION}"

# Ubuntu also bundles Pebble; this image uses Tini as its entrypoint.
# Remove the unused, unmanaged Go binary rather than ship its vulnerable runtime.
RUN test "$PROTON_BRIDGE_VERSION" = '3.25.0-1' \
    && rm -f /usr/bin/pebble \
    && apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        libfido2-1 \
        pass \
        tini \
    && package="protonmail-bridge_${PROTON_BRIDGE_VERSION}_amd64.deb" \
    && curl --fail --location --show-error --silent \
        "https://github.com/ProtonMail/proton-bridge/releases/download/v${PROTON_BRIDGE_VERSION%-1}/${package}" \
        --output "/tmp/${package}" \
    && echo "${PROTON_BRIDGE_SHA256}  /tmp/${package}" | sha256sum --check --strict \
    && apt-get install -y --no-install-recommends "/tmp/${package}" \
    && rm -f "/tmp/${package}" \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 10001 proton-bridge \
    && useradd --uid 10001 --gid proton-bridge --create-home \
        --home-dir /home/proton-bridge --shell /usr/sbin/nologin proton-bridge \
    && install -d -o proton-bridge -g proton-bridge -m 0700 \
        /home/proton-bridge/.config \
        /home/proton-bridge/.cache \
        /home/proton-bridge/.local/share \
        /home/proton-bridge/.gnupg \
        /home/proton-bridge/.password-store

COPY --chmod=0755 docker/proton-bridge-entrypoint.sh /usr/local/bin/proton-bridge-entrypoint
COPY --chmod=0755 docker/proton-bridge-healthcheck.sh /usr/local/bin/proton-bridge-healthcheck
COPY --from=bridge-builder /build/dnr-bridge /usr/lib/protonmail/bridge/bridge
COPY --from=bridge-builder /build/proton-bridge-3.25.0/LICENSE /usr/share/doc/dnr-proton-bridge/LICENSE
COPY docker/proton-bridge-auth/ /usr/share/doc/dnr-proton-bridge/adapter/

USER proton-bridge
WORKDIR /home/proton-bridge

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/proton-bridge-entrypoint"]
CMD ["--noninteractive"]
