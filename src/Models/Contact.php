<?php

namespace DNR\Models;

class Contact extends BaseModel {
    protected $table = 'contacts';
    protected $fillable = [
        'organization_id',
        'contact_name',
        'contact_role',
        'contact_role_other',
        'contact_email',
        'contact_phone'
    ];

    public function getByOrganization($organizationId) {
        return $this->findAll(['organization_id' => $organizationId]);
    }

    public function validateRole($role) {
        return in_array($role, ['pastor', 'admin', 'other']);
    }

    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function findByEmail($email) {
        $sql = "SELECT * FROM {$this->table} WHERE contact_email = :email";
        return $this->db->fetch($sql, ['email' => $email]);
    }

    public function create(array $data) {
        // Validate required fields
        $requiredFields = ['contact_name', 'contact_role', 'contact_email', 'contact_phone'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("$field is required");
            }
        }

        // Validate email
        if (!$this->validateEmail($data['contact_email'])) {
            throw new \InvalidArgumentException("Invalid email format");
        }

        // Validate role
        if (!$this->validateRole($data['contact_role'])) {
            throw new \InvalidArgumentException("Invalid role");
        }

        // If role is 'other', ensure other_role is provided
        if ($data['contact_role'] === 'other' && empty($data['contact_role_other'])) {
            throw new \InvalidArgumentException("Other role description is required");
        }

        return parent::create($data);
    }

    public function update($id, array $data) {
        // Validate email if provided
        if (isset($data['contact_email']) && !$this->validateEmail($data['contact_email'])) {
            throw new \InvalidArgumentException("Invalid email format");
        }

        // Validate role if provided
        if (isset($data['contact_role']) && !$this->validateRole($data['contact_role'])) {
            throw new \InvalidArgumentException("Invalid role");
        }

        // If role is 'other', ensure other_role is provided
        if (isset($data['contact_role']) && 
            $data['contact_role'] === 'other' && 
            empty($data['contact_role_other'])) {
            throw new \InvalidArgumentException("Other role description is required");
        }

        return parent::update($id, $data);
    }
} 