FROM golang:1.27.2-alpine@sha256:85dc1069ac644ea3c527b177303a406eb3358192816cd7f9e5848eb658851673 AS gosu
# Pin the 1.19 release commit; rebuild with the supported Go toolchain.
RUN CGO_ENABLED=0 go install github.com/tianon/gosu@6456aaa0f3c854d199d0f037f068eb97515b7513

FROM mysql:8.4@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242
# Keep the server, mysql client and mysqldump; the unused shell bundles its own
# obsolete Python libraries. Patch the base OS before CI qualifies the digest.
RUN microdnf remove -y mysql-shell \
    && microdnf update -y \
    && microdnf clean all
COPY --from=gosu /go/bin/gosu /usr/local/bin/gosu
COPY docker/mysqld.my /usr/sbin/mysqld.my
COPY docker/component_keyring_file.cnf /usr/lib64/mysql/plugin/component_keyring_file.cnf
COPY docker/mysql-encryption.cnf /etc/mysql/conf.d/dnr-encryption.cnf
RUN install -d -m 0700 -o mysql -g mysql /var/lib/mysql-keyring
# Logical restore verification also needs a writable, isolated keyring.
VOLUME /var/lib/mysql-keyring
COPY --chmod=0755 docker/mysql-encryption-entrypoint.sh /usr/local/bin/dnr-mysql-entrypoint
ENTRYPOINT ["dnr-mysql-entrypoint"]
CMD ["mysqld"]
