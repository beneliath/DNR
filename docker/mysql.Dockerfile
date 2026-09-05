FROM golang:1.27.1-alpine@sha256:cf6fca6641884b8433441b2b0652976f975e1d0fdd26d177eaaf8596087f3125 AS gosu
# Pin the 1.19 release commit; rebuild with the supported Go toolchain.
RUN CGO_ENABLED=0 go install github.com/tianon/gosu@6456aaa0f3c854d199d0f037f068eb97515b7513

FROM mysql:26.7@sha256:66aec17cd21a956029b83f083b813073859e8355dc1a00e55df6ba02f0e32345
# Keep the server, mysql client and mysqldump; the unused shell bundles its own
# obsolete Python libraries. Patch the base OS before CI qualifies the digest.
RUN microdnf remove -y mysql-shell \
    && microdnf update -y \
    && microdnf clean all
COPY --from=gosu /go/bin/gosu /usr/local/bin/gosu
