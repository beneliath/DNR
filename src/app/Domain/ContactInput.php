<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class ContactInput
{
    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, int|string|null>, errors: list<string>, error_fields: list<string|null>}
     */
    public static function normalize(array $input): array
    {
        $organization_id_value = is_scalar($input['organization_id'] ?? null)
            ? trim((string) $input['organization_id'])
            : '';
        $organization_id_is_invalid = $organization_id_value !== ''
            && (!ctype_digit($organization_id_value) || (int) $organization_id_value < 1);
        $organization_id = !$organization_id_is_invalid && $organization_id_value !== ''
            ? (int) $organization_id_value
            : null;
        $birthday_value = InputText::value($input, 'contact_birthday');
        [$contact_birthday, $birthday_error] = self::normalizeBirthday($birthday_value);
        $data = [
            'organization_id' => $organization_id > 0 ? $organization_id : null,
            'contact_first_name' => InputText::value($input, 'contact_first_name'),
            'contact_last_name' => InputText::value($input, 'contact_last_name'),
            'contact_role' => strtolower(InputText::value($input, 'contact_role')),
            'contact_role_other' => InputText::value($input, 'contact_role_other'),
            'contact_email' => InputText::value($input, 'contact_email'),
            'contact_email_confirm' => array_key_exists('contact_email_confirm', $input)
                ? InputText::value($input, 'contact_email_confirm') : InputText::value($input, 'contact_email'),
            'contact_phone' => InputText::value($input, 'contact_phone'),
            'contact_birthday' => $contact_birthday,
            'contact_notes' => InputText::value($input, 'contact_notes'),
            'contact_phone_country_code' => InputText::value($input, 'contact_phone_country_code')
                ?: \applicationDefaultPhoneCountryCode(),
        ];
        $errors = [];
        $error_fields = [];
        $add_error = static function (?string $field, string $message) use (&$errors, &$error_fields): void {
            if (in_array($message, $errors, true)) return;
            $errors[] = $message;
            $error_fields[] = $field;
        };
        if ($organization_id_is_invalid) {
            $add_error('organization_id', 'Select a valid organization.');
        }
        if ($birthday_error !== null) {
            $add_error('contact_birthday', $birthday_error);
        }
        if ($data['contact_first_name'] === '') {
            $add_error('contact_first_name', 'First name is required.');
        }
        if ($data['contact_last_name'] === '') {
            $add_error('contact_last_name', 'Last name is required.');
        }
        foreach ([
            'contact_first_name' => [255, 'First name'],
            'contact_last_name' => [255, 'Last name'],
            'contact_role_other' => [255, 'Other role'],
            'contact_email' => [255, 'Email address'],
            'contact_email_confirm' => [255, 'Email confirmation'],
        ] as $field => [$maximum, $label]) {
            $length_error = InputText::lengthError((string) $data[$field], $maximum, $label);
            if ($length_error !== null) {
                $add_error($field === 'contact_email_confirm' ? 'contact_email' : $field, $length_error);
            }
        }
        $notes_error = InputText::textStorageError((string) $data['contact_notes'], 'Contact notes');
        if ($notes_error !== null) {
            $add_error('contact_notes', $notes_error);
        }
        if (!in_array($data['contact_role'], ReferenceData::contactRoles(), true)) {
            $add_error('contact_role', 'A valid role is required.');
        }
        if ($data['contact_role'] === 'other' && $data['contact_role_other'] === '') {
            $add_error('contact_role_other', 'Please specify the other role.');
        }
        if (!filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $add_error('contact_email', 'Please provide a valid email address.');
        } elseif (!hash_equals($data['contact_email'], $data['contact_email_confirm'])) {
            $add_error('contact_email', 'Email addresses do not match.');
        }
        try {
            $data['contact_phone'] = \normalizePhoneNumber(
                $data['contact_phone_country_code'],
                $data['contact_phone'],
                'Phone number'
            );
        } catch (\InvalidArgumentException $exception) {
            $add_error('contact_phone', $exception->getMessage());
        }

        return ['data' => $data, 'errors' => $errors, 'error_fields' => $error_fields];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function normalizeBirthday(string $value): array
    {
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/\A(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])\z/', $value, $matches) !== 1) {
            return [null, 'Birthday must use MM/DD format.'];
        }

        $month = (int) $matches[1];
        $day = (int) $matches[2];
        if (!checkdate($month, $day, 2000)) {
            return [null, 'Birthday must be a valid date.'];
        }

        return [sprintf('%02d/%02d', $month, $day), null];
    }

    /**
     * Normalize a contact nested inside the organization creation form.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   data: array{first_name: string, last_name: string, role: string,
     *     role_other: string, email: string, phone: string, birthday: string|null, notes: string,
     *     phone_country_code: string},
     *   errors: list<string>,
     *   error_fields: list<string|null>
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
            'contact_email_confirm' => $input['email_confirm'] ?? ($input['email'] ?? ''),
            'contact_phone' => $input['phone'] ?? '',
            'contact_birthday' => $input['birthday'] ?? '',
            'contact_notes' => $input['notes'] ?? '',
            'contact_phone_country_code' => $input['phone_country_code']
                ?? \applicationDefaultPhoneCountryCode(),
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
                'birthday' => $data['contact_birthday'],
                'notes' => (string) $data['contact_notes'],
                'phone_country_code' => (string) $data['contact_phone_country_code'],
            ],
            'errors' => $normalized['errors'],
            'error_fields' => array_map(
                static fn(?string $field): ?string => $field === null ? null : preg_replace('/\Acontact_/', '', $field),
                $normalized['error_fields']
            ),
        ];
    }
}
