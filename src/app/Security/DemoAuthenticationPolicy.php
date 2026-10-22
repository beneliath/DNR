<?php
declare(strict_types=1);
namespace Dnr\Security;

/** Operator-pinned exceptions for ordinary demo users in the fictional Test Account. */
final class DemoAuthenticationPolicy
{
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $profile
     */
    public static function allows(array $user, array $profile, string $key, string $mode, bool $enabled): bool
    {
        $name = $user['username'] ?? '';
        $roles = ['TestAdmin' => 'admin', 'TestEditor' => 'editor', 'TestReviewer' => 'reviewer'];
        $pinned = $name === 'TestAdmin' ? ($profile['settings']['demo_password_only_user_id'] ?? null)
            : ($profile['settings']['demo_password_only_user_ids'][$name] ?? null);
        return $enabled && $mode === 'member' && $key === 'test-account'
            && ($profile['account_key'] ?? null) === 'test-account'
            && is_int($pinned) && $pinned > 0 && (int) ($user['id'] ?? 0) === $pinned
            && isset($roles[$name]) && ($user['role'] ?? null) === $roles[$name]
            && ($user['account_status'] ?? null) === 'active'
            && array_key_exists('is_superadmin', $user) && !(bool) $user['is_superadmin']
            && array_key_exists('platform_identity_id', $user) && $user['platform_identity_id'] === null
            && array_key_exists('two_factor_enabled', $user) && !(bool) $user['two_factor_enabled'];
    }

    /** Restrictions follow the pinned identity even if its role, name or MFA state changes.
     * @param array<string, mixed> $user
     * @param array<string, mixed> $profile
     */
    public static function isRestrictedUser(array $user, array $profile, string $key, string $mode, bool $enabled): bool
    {
        $pinned = $profile['settings']['demo_password_only_user_id'] ?? null;
        return $enabled && $mode === 'member' && $key === 'test-account'
            && ($profile['account_key'] ?? null) === 'test-account'
            && is_int($pinned) && $pinned > 0 && (int) ($user['id'] ?? 0) === $pinned;
    }
}
