FROM golang:1.27.1-alpine@sha256:cf6fca6641884b8433441b2b0652976f975e1d0fdd26d177eaaf8596087f3125 AS gosu
# Pin the 1.19 release commit; rebuild with the supported Go toolchain.
RUN CGO_ENABLED=0 go install github.com/tianon/gosu@6456aaa0f3c854d199d0f037f068eb97515b7513

FROM mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a
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
