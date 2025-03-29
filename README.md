# DNR: Deploy and Report

DNR (Deploy and Report) is a web-based application for managing speaking engagements, presentations, and organizational contacts.

## To Do

- [x] rename project to "deploy and report"
- [ ] code Event Mailing Address / Event Physical Address
- [ ] code: Is Mailing the same as Physical?
- [ ] consider address data locus in Event or Organization
- [ ] build out for Contact(s): Admin, Pastor
- [ ] build out for Anticipated Compensation
- [ ] 'Caller' in Engagement/Event to be listed system user
- [ ] build out profiles for users that includes email and phone for contact
- [ ] convert from plaintext to hashed passwords
- [ ] build out Presentation functionality (e.g., single Engagement may have multiple Presentations)
- [ ] consider using `session_regenerate_id()` after login to prevent session fixation attacks (as has already been implemented in index.php)
- [ ] evaluate for sql-injection vulnerabilities
- [ ] minify JS and CSS
- [ ] when above is complete: build out REPORT functionality
- [ ] when above is complete: build out FOLLOW-UP functionality
- [ ] when above is complete: build out printed document support

## Installation

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

### Configuration

Database Initialization:

The init.sql file contains the necessary SQL commands to set up the initial database schema and data. Ensure that this script is executed when the database service starts.

Environment Variables:

Configure the following environment variables to customize the application's behavior:

{_VARIABLE_NAME: Description of the variable._}

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

### License

This project is licensed under the MIT License. See the LICENSE file for more details.
