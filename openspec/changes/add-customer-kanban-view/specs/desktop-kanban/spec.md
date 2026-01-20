# Desktop Kanban - Customer View Delta

## ADDED Requirements

### Requirement: Customer List View
The system SHALL provide a "客户" (Customer) view tab in the project kanban page that displays all customers with their associated projects.

#### Scenario: View customer list
- **WHEN** user clicks the "客户" tab in project kanban
- **THEN** system displays a list of all customers
- **AND** each customer shows name, group code, and project count
- **AND** customers are sorted by recent activity

#### Scenario: Search customers
- **WHEN** user enters search text in the customer view
- **THEN** system filters customers by name or group code
- **AND** results update as user types (debounced)

#### Scenario: Expand customer projects
- **WHEN** user clicks on a customer row
- **THEN** system expands to show the customer's project list
- **AND** each project shows name, status, and assigned tech users

### Requirement: Create Project from Customer
The system SHALL allow users with project management permission to create new projects directly from the customer view.

#### Scenario: Open create project dialog
- **WHEN** user clicks "新建项目" button on a customer row
- **THEN** system displays a modal dialog
- **AND** dialog shows project name input field
- **AND** dialog shows tech user selector (multi-select)

#### Scenario: Create project successfully
- **WHEN** user fills project name and selects tech users
- **AND** user clicks confirm button
- **THEN** system creates the project for that customer
- **AND** system assigns selected tech users to the project
- **AND** system refreshes the customer's project list
- **AND** system shows success toast message

#### Scenario: Create project validation
- **WHEN** user tries to create project without project name
- **THEN** system shows validation error
- **AND** system does not submit the form

### Requirement: Customer List API
The system SHALL provide an API endpoint to fetch customer list with project statistics.

#### Scenario: Fetch customer list
- **WHEN** client calls `GET /api/desktop_projects.php?action=customers`
- **THEN** API returns list of customers with id, name, group_code, project_count
- **AND** API supports `search` parameter for filtering
- **AND** API supports `page` and `limit` parameters for pagination

### Requirement: Create Project API
The system SHALL provide an API endpoint to create projects from the desktop app.

#### Scenario: Create project via API
- **WHEN** client calls `POST /api/desktop_projects.php?action=create_project`
- **WITH** body containing customer_id, project_name, tech_user_ids[]
- **THEN** API creates the project record
- **AND** API assigns tech users to the project
- **AND** API returns the new project details

### Requirement: Tech Users List API
The system SHALL provide an API endpoint to fetch available tech users for assignment.

#### Scenario: Fetch tech users
- **WHEN** client calls `GET /api/desktop_projects.php?action=tech_users`
- **THEN** API returns list of users with tech role
- **AND** each user includes id and name
