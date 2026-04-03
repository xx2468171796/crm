## ADDED Requirements

### Requirement: Unified Field Naming Convention
The system SHALL use consistent field naming across all database tables and code references for the same business concept.

#### Scenario: Currency field consistency
- **WHEN** a contract's currency is stored or retrieved
- **THEN** the field name MUST be `currency` in the database and `contract_currency` in joined queries

#### Scenario: Timestamp field consistency
- **WHEN** a record's creation or update time is stored
- **THEN** the field names MUST follow the pattern `create_time` and `update_time` as Unix timestamps

### Requirement: Service Layer for Data Operations
The system SHALL provide a Service Layer that encapsulates all database CRUD operations for core entities.

#### Scenario: Contract update through service
- **WHEN** a contract needs to be updated
- **THEN** the update MUST go through `ContractService::update()` method
- **AND** the service SHALL handle validation, timestamps, and transaction management

#### Scenario: Atomic operations
- **WHEN** multiple related records need to be updated together
- **THEN** the Service Layer SHALL wrap operations in a database transaction
- **AND** roll back all changes if any operation fails

### Requirement: Data Migration for Legacy Records
The system SHALL provide migration scripts to fix data inconsistencies caused by previous field naming issues.

#### Scenario: Currency field migration
- **WHEN** migration script is executed
- **THEN** all records with NULL or empty currency SHALL be set to the default value 'TWD'
- **AND** existing valid currency values SHALL be preserved

### Requirement: Centralized Update Logic
The system SHALL NOT allow direct SQL UPDATE statements in API or frontend files for core entities.

#### Scenario: Refactored API endpoint
- **WHEN** an API endpoint needs to update a contract
- **THEN** it MUST call `ContractService::update()` instead of direct `Db::execute()`
- **AND** the old direct database calls SHALL be removed
