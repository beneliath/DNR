## DNR: Deploy and Report

### Description

DNR (Deploy and Report) is a web-based application for managing speaking engagements, presentations, and organizational contacts.

### Installation

To set up the project on your local machine, follow these steps:

1. **Clone the repository**

   ```
   git clone https://github.com/beneliath/DNR.git
   ```

2. **Navigate to the project directory**
   ```
   cd DNR
   ```
3. **Build and run the application using Docker Compose**
   `docker-compose up --build`
   This command will build the Docker images and start the services defined in the docker-compose.yaml file.

### Roadmap / To Do

- [x] rename project to "deploy and report"
- [x] include check for Event End Date >= Event Start Date
- [x] add default username/password (admin/p@55word)
- [x] complete minimal buildout of 'Add Engagement' functionality
- [ ] !!! fix Mailing & Physical Address issue with 'Add Organization' (_n.b._, problem stems for alteration of database schema to include multiple fields for addresses instead of just one field)
- [ ] complete minimal buildout of 'Add Organization' functionality
- [ ] complete minimal buildout of 'Add Contact' functionality
- [ ] complete minimal buildout of 'Add Presentation(s)' functionality
- [ ] fix white-stripe at bottom for dark theme on 'Add Organization'
- [ ] code Event Mailing Address / Event Physical Address
- [ ] code: Is Mailing the same as Physical?
- [ ] consider address data locus in Event or Organization
- [ ] header navigation bar redesign
- [ ] doublecheck password entry for new user creation
- [ ] mod edit user to allow for change of password (with doublecheck)
- [ ] build out profiles for users that includes email and phone for contact
- [ ] add functionality to activate/deactivate users
- [ ] build out for Contact(s): Admin, Pastor
- [ ] build out for Anticipated Compensation
- [ ] 'Caller' in Engagement/Event to be listed system user
- [ ] convert from plaintext to hashed passwords
- [ ] build out Presentation functionality (e.g., single Engagement may have multiple Presentations)
- [ ] consider using `session_regenerate_id()` after login to prevent session fixation attacks (as has already been implemented in index.php)
- [ ] evaluate for sql-injection vulnerabilities
- [ ] minify JS and CSS
- [ ] when above is complete: build out REPORT functionality
- [ ] when above is complete: build out FOLLOW-UP functionality
- [ ] when above is complete: build out printed document support

### Configuration

Database Initialization:

The init.sql file contains the necessary SQL commands to set up the initial database schema and data. Ensure that this script is executed when the database service starts.

Environment Variables:

Configure the following environment variables to customize the application's behavior:

[*VARIABLE_NAME: Description of the variable.*]

### Usage

[*Provide instructions on how to use the application, including examples and screenshots if applicable.*]

### Contributing

We welcome contributions to the DNR project. To contribute:

- fork the repository
- create a new branch for your feature or bug fix
- commit your changes with descriptive messages
- push your branch to your forked repository
- submit a pull request to the main repository

Please ensure that your contributions adhere to the project's coding standards and include appropriate tests.

### Authors and Acknowledgment

### License

This project is licensed under the MIT License. See the LICENSE file for more details.

### Project Status

Under active development as of 2025-03-28 23:05:30
