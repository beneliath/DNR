## DNR: deploy and report

### Description

DNR (deploy and report) is a web-based application for managing speaking engagements, presentations, and organizational contacts.

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
   - browse to localhost:8080 (default port)
   - user=admin / pass=p@55word

### Configuration

Database Initialization:

The init.sql file contains the necessary SQL commands to set up the initial database schema and data. Ensure that this script is executed when the database service starts.

Environment Variables:

Configure the following environment variables to customize the application's behavior:

placeholder >> [*VARIABLE_NAME: Description of the variable.*]

### Usage

[*Provide instructions on how to use the application, including examples and screenshots if applicable.*]

### Contributing

Contributions to the DNR project are welcome. To contribute:

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
