FROM ubuntu:24.04@sha256:33ceb71981b602c1a7443a53469e4dba065f7503eab3078a2d7a57a2ab987517

ARG PROTON_BRIDGE_VERSION=3.25.0-1
ARG PROTON_BRIDGE_SHA256=6b0318f4f425ef1a19b63e2bd589bc1036d95f073cb9ac26b42c0fc63a8bc275

LABEL org.opencontainers.image.title="DNR Proton Mail Bridge sidecar" \
      org.opencontainers.image.source="https://github.com/ProtonMail/proton-bridge" \
      org.opencontainers.image.version="${PROTON_BRIDGE_VERSION}"

RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
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

USER proton-bridge
WORKDIR /home/proton-bridge

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/proton-bridge-entrypoint"]
CMD ["--noninteractive"]
