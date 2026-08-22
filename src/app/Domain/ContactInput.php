<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class ContactInput
{
    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, int|string>, errors: list<string>}
     */
    public static function normalize(array $input): array
    {
        $data = [
            'organization_id' => max(0, (int) ($input['organization_id'] ?? 0)),
            'contact_first_name' => self::text($input, 'contact_first_name'),
            'contact_last_name' => self::text($input, 'contact_last_name'),
            'contact_role' => strtolower(self::text($input, 'contact_role')),
            'contact_role_other' => self::text($input, 'contact_role_other'),
            'contact_email' => self::text($input, 'contact_email'),
            'contact_email_confirm' => self::text($input, 'contact_email_confirm'),
            'contact_phone' => self::text($input, 'contact_phone'),
            'contact_notes' => self::text($input, 'contact_notes'),
            'contact_phone_country_code' => self::text($input, 'contact_phone_country_code') ?: '+1',
        ];
        $errors = [];
        if ($data['organization_id'] < 1) {
            $errors[] = 'Organization is required.';
        }
        if ($data['contact_first_name'] === '') {
            $errors[] = 'First name is required.';
        }
        if ($data['contact_last_name'] === '') {
            $errors[] = 'Last name is required.';
        }
        if (!in_array($data['contact_role'], ReferenceData::contactRoles(), true)) {
            $errors[] = 'A valid role is required.';
        }
        if ($data['contact_role'] === 'other' && $data['contact_role_other'] === '') {
            $errors[] = 'Please specify the other role.';
        }
        if (!filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        } elseif (!hash_equals($data['contact_email'], $data['contact_email_confirm'])) {
            $errors[] = 'Email addresses do not match.';
        }
        try {
            $data['contact_phone'] = \normalizePhoneNumber(
                $data['contact_phone_country_code'],
                $data['contact_phone'],
                'Phone number'
            );
        } catch (\InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }

        return ['data' => $data, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Normalize a contact nested inside the organization creation form.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   data: array{first_name: string, last_name: string, role: string,
     *     role_other: string, email: string, phone: string, notes: string,
     *     phone_country_code: string},
     *   errors: list<string>
     * }
     */
    public static function normalizeEmbedded(array $input): array
    {
        $normalized = self::normalize([
            'organization_id' => 1,
            'contact_first_name' => $input['first_name'] ?? '',
            'contact_last_name' => $input['last_name'] ?? '',
            'contact_role' => $input['role'] ?? '',
            'contact_role_other' => $input['role_other'] ?? '',
            'contact_email' => $input['email'] ?? '',
            'contact_email_confirm' => $input['email_confirm'] ?? '',
            'contact_phone' => $input['phone'] ?? '',
            'contact_notes' => $input['notes'] ?? '',
            'contact_phone_country_code' => $input['phone_country_code'] ?? '+1',
        ]);
        $data = $normalized['data'];
        return [
            'data' => [
                'first_name' => (string) $data['contact_first_name'],
                'last_name' => (string) $data['contact_last_name'],
                'role' => (string) $data['contact_role'],
                'role_other' => (string) $data['contact_role_other'],
                'email' => (string) $data['contact_email'],
                'phone' => (string) $data['contact_phone'],
                'notes' => (string) $data['contact_notes'],
                'phone_country_code' => (string) $data['contact_phone_country_code'],
            ],
            'errors' => $normalized['errors'],
        ];
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key): string
    {
        return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }
}
