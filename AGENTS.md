# Project Instructions

When changes affect MOED Docker networking, container IP allocation, account provisioning, or deployment/startup procedures, read and follow [.cursor/rules/docker-ip-collision-checks.mdc](.cursor/rules/docker-ip-collision-checks.mdc). Check collisions across shared networks and ensure static addresses remain protected when their containers are stopped.

For MOED development, releases, account provisioning, and demo-data refreshes, read and follow [.cursor/rules/account-release-parity.mdc](.cursor/rules/account-release-parity.mdc). Use one implementation and keep all active production accounts on the same latest qualified release; enforce demo visibility and provisioning restrictions through shared authorization policies.

For every database schema or migration change, also read [.cursor/rules/test-account-baseline-refresh.mdc](.cursor/rules/test-account-baseline-refresh.mdc). Update Test Account refresh compatibility and dependency cleanup in the same work, and qualify an isolated approved-baseline restore against the target/deployed schema before its next nightly run.

For a requested Test Account data refresh, read and follow [.cursor/rules/test-account-baseline-refresh.mdc](.cursor/rules/test-account-baseline-refresh.mdc). Restore the approved baseline with dates remapped to three months before and nine months after the then-current Chicago date, preserving demo logins and access controls.
