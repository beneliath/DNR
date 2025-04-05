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
- [x] fix Mailing & Physical Address issue with 'Add Organization'
- [x] fix dark-mode theme problem where lower-scrolled section left/right columns render background as white (double check clearing browser cache re same)
- [x] globbing for Mailing & Physical Address entry
- [x] login ui/ux redesign
- [x] complete minimal buildout of 'Add Organization' functionality
- [x] ensure docker container auto-restarts
- [x] add creation and last-modification dates for user accounts
- [x] minimal buildout of 'Add Contact' functionality
- [x] add anticipated compensation block for 'Add Engagement'
- [x] minimal buildout of 'Add Presentation' functionality
- [x] !! CORRECT FORMATTING ERRORS INTRODUCED IN PRESENTATION BUILDOUT !! 2025-04-04 15:12:56
- [x] add contacts during Organization add
- [ ] view/add contact for selected Organization
- [ ] view/add presentation for selected Event
- [x] code Mailing Address / Physical Address
- [x] code: Is Mailing the same as Physical?
- [ ] TEST 'Add Engagement'; on pass, tag restore point
- [ ] TEST 'Add Organization'; on pass, tag restore point
- [ ] TEST 'Add Contact'; on pass, tag restore point
- [ ] TEST 'Add Presentation'; on pass, tag restore point
- [ ] header navigation bar redesign (only after base functionality completed)
- [ ] doublecheck password entry for new user creation
- [ ] mod edit user to allow for change of password (with doublecheck)
- [ ] build out profiles for users (including email sub-system for initialization and password reset)
- [ ] add functionality to activate/deactivate users
- [ ] build out for Contact(s): Admin, Pastor
- [ ] build out for Anticipated Compensation
- [ ] 'Caller' in Engagement/Event to be listed system user
- [ ] convert from plaintext to hashed passwords
- [ ] build out multiple Presentation functionality
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

Under active development
