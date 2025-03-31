<?php

namespace DNR\Controllers;

use DNR\Models\Organization;
use DNR\Models\Contact;
use DNR\Utils\Security;

class OrganizationController {
    private $organizationModel;
    private $contactModel;

    public function __construct() {
        $this->organizationModel = new Organization();
        $this->contactModel = new Contact();
    }

    public function index() {
        $organizations = $this->organizationModel->findAll();
        require 'templates/pages/organizations/index.php';
    }

    public function create() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Validate CSRF token
                if (!Security::validateCSRFToken($_POST['csrf_token'])) {
                    throw new \Exception('Invalid CSRF token');
                }

                // Sanitize input
                $orgData = Security::sanitizeInput([
                    'organization_name' => $_POST['organization_name'],
                    'notes' => $_POST['notes'],
                    'affiliation' => $_POST['affiliation'],
                    'distinctives' => $_POST['distinctives'],
                    'website_url' => $_POST['website_url'],
                    'phone' => $_POST['phone'],
                    'fax' => $_POST['fax'],
                    'mailing_address_line_1' => $_POST['mailing_address_line_1'],
                    'mailing_address_line_2' => $_POST['mailing_address_line_2'],
                    'mailing_city' => $_POST['mailing_city'],
                    'mailing_state' => $_POST['mailing_state'],
                    'mailing_zipcode' => $_POST['mailing_zipcode'],
                    'mailing_country' => $_POST['mailing_country'],
                    'physical_address_line_1' => $_POST['physical_address_line_1'],
                    'physical_address_line_2' => $_POST['physical_address_line_2'],
                    'physical_city' => $_POST['physical_city'],
                    'physical_state' => $_POST['physical_state'],
                    'physical_zipcode' => $_POST['physical_zipcode'],
                    'physical_country' => $_POST['physical_country']
                ]);

                $contactData = Security::sanitizeInput([
                    'contact_name' => $_POST['contact_name'],
                    'contact_role' => $_POST['contact_role'],
                    'contact_role_other' => $_POST['contact_role_other'],
                    'contact_email' => $_POST['contact_email'],
                    'contact_phone' => $_POST['contact_phone']
                ]);

                // Validate email match
                if ($_POST['contact_email'] !== $_POST['contact_email_confirm']) {
                    throw new \Exception('Email addresses do not match');
                }

                // Create organization with contact
                $organizationId = $this->organizationModel->createWithContact($orgData, $contactData);

                $_SESSION['success'] = 'Organization created successfully';
                header('Location: organizations.php');
                exit;

            } catch (\Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
        }

        // Generate CSRF token for the form
        $csrfToken = Security::generateCSRFToken();
        require 'templates/pages/organizations/create.php';
    }

    public function edit($id) {
        $organization = $this->organizationModel->find($id);
        if (!$organization) {
            $_SESSION['error'] = 'Organization not found';
            header('Location: organizations.php');
            exit;
        }

        $contacts = $this->contactModel->getByOrganization($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Validate CSRF token
                if (!Security::validateCSRFToken($_POST['csrf_token'])) {
                    throw new \Exception('Invalid CSRF token');
                }

                $orgData = Security::sanitizeInput($_POST);
                $this->organizationModel->update($id, $orgData);

                $_SESSION['success'] = 'Organization updated successfully';
                header('Location: organizations.php');
                exit;

            } catch (\Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
        }

        $csrfToken = Security::generateCSRFToken();
        require 'templates/pages/organizations/edit.php';
    }

    public function delete($id) {
        try {
            // Validate CSRF token
            if (!Security::validateCSRFToken($_POST['csrf_token'])) {
                throw new \Exception('Invalid CSRF token');
            }

            $this->organizationModel->delete($id);
            $_SESSION['success'] = 'Organization deleted successfully';

        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header('Location: organizations.php');
        exit;
    }

    public function view($id) {
        $organization = $this->organizationModel->find($id);
        if (!$organization) {
            $_SESSION['error'] = 'Organization not found';
            header('Location: organizations.php');
            exit;
        }

        $contacts = $this->contactModel->getByOrganization($id);
        require 'templates/pages/organizations/view.php';
    }

    public function search() {
        $term = isset($_GET['q']) ? Security::sanitizeInput($_GET['q']) : '';
        $organizations = $this->organizationModel->search($term);
        require 'templates/pages/organizations/search.php';
    }
} 