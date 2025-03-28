# DNR: Deputation and Report

DNR (Deputation and Report) is a project designed to [*provide a brief description of the project's purpose and functionality*].

## To Do

- [ ] code Event Mailing Address / Event Physical Address
- [ ] code selection: Is Mailing the same as Physical?
- [ ] consider what of the above should be included in Event and/or Organization
- [ ] code/build out for Contact(s): Admin, Pastor
- [ ] code/build out for Anticipated Compensation
- [ ] code for 'Caller' in Engagement/Event to be listed system user
- [ ] code/build out profiles for users that includes email and phone for contact
- [ ] code/build out Presentation functionality (e.g., single Engagement may have multiple Presentations)
- [ ] when above is complete: code/build out REPORT functionality
- [ ] when above is complete: code/build out FOLLOW-UP functionality
- [ ] when above is complete: code/build out printed document support

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

VARIABLE_NAME: Description of the variable.

### Usage

[*Provide instructions on how to use the application, including examples and screenshots if applicable.*]

### Contributing

We welcome contributions to the DNR project. To contribute:

- [ ] fork the repository
- [ ] create a new branch for your feature or bug fix
- [ ] commit your changes with descriptive messages
- [ ] push your branch to your forked repository

- [ ] submit a pull request to the main repository

Please ensure that your contributions adhere to the project's coding standards and include appropriate tests.

### License

This project is licensed under the MIT License. See the LICENSE file for more details.
