<?php

namespace DNR\Models;

class Organization extends BaseModel {
    protected $table = 'organizations';
    protected $fillable = [
        'organization_name',
        'notes',
        'affiliation',
        'distinctives',
        'website_url',
        'phone',
        'fax',
        'mailing_address_line_1',
        'mailing_address_line_2',
        'mailing_city',
        'mailing_state',
        'mailing_zipcode',
        'mailing_country',
        'physical_address_line_1',
        'physical_address_line_2',
        'physical_city',
        'physical_state',
        'physical_zipcode',
        'physical_country'
    ];

    public function getContacts($organizationId) {
        $sql = "SELECT * FROM contacts WHERE organization_id = :organization_id";
        return $this->db->fetchAll($sql, ['organization_id' => $organizationId]);
    }

    public function getEngagements($organizationId) {
        $sql = "SELECT * FROM engagements WHERE organization_id = :organization_id";
        return $this->db->fetchAll($sql, ['organization_id' => $organizationId]);
    }

    public function createWithContact(array $orgData, array $contactData) {
        try {
            $this->beginTransaction();

            // Create organization
            $organizationId = $this->create($orgData);

            // Create contact
            $contactData['organization_id'] = $organizationId;
            $contactModel = new Contact();
            $contactModel->create($contactData);

            $this->commit();
            return $organizationId;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function search($term) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE organization_name LIKE :term 
                OR affiliation LIKE :term 
                OR distinctives LIKE :term";
        
        $term = "%$term%";
        return $this->db->fetchAll($sql, ['term' => $term]);
    }
} 