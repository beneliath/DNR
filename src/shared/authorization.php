<?php

declare(strict_types=1);

// Check if the logged-in user has any of the specified roles
function hasRole($roles = []) {
    return (isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true));
}

function canArchiveEntries($role) {
    return in_array($role, ['admin', 'editor'], true);
}

function canDeleteEntries($role) {
    return $role === 'admin';
}
