# Project Instructions

When changes affect MOED Docker networking, container IP allocation, account provisioning, or deployment/startup procedures, read and follow [.cursor/rules/docker-ip-collision-checks.mdc](.cursor/rules/docker-ip-collision-checks.mdc). Check collisions across shared networks and ensure static addresses remain protected when their containers are stopped.

For MOED development, releases, account provisioning, and demo-data refreshes, read and follow [.cursor/rules/account-release-parity.mdc](.cursor/rules/account-release-parity.mdc). Use one implementation and keep all active production accounts on the same latest qualified release; enforce demo visibility and provisioning restrictions through shared authorization policies.
