# File Upload Specification

## ADDED Requirements

### Requirement: Concurrent Chunk Upload
The system SHALL support concurrent chunk uploads with a configurable concurrency level (default 3).

#### Scenario: Desktop client uploads file with 3 concurrent chunks
- **WHEN** user uploads a file from desktop client
- **THEN** the system uploads up to 3 chunks simultaneously
- **AND** progress is updated as each chunk completes

#### Scenario: Web client uploads file with 3 concurrent chunks
- **WHEN** user uploads a file from web browser
- **THEN** the system uploads up to 3 chunks simultaneously
- **AND** progress bar reflects combined progress of all chunks

### Requirement: Async S3 Upload
The system SHALL upload files to S3 asynchronously after receiving all chunks locally.

#### Scenario: Desktop upload completes immediately
- **WHEN** all chunks are uploaded to local cache
- **THEN** the system returns success response immediately
- **AND** S3 upload happens in background

#### Scenario: Web upload completes immediately
- **WHEN** all chunks are uploaded and S3 merge is requested
- **THEN** the system returns success response immediately
- **AND** S3 merge happens in background

### Requirement: SSD Cache Directory
The system SHALL use SSD cache directory for temporary chunk storage.

#### Scenario: Desktop chunks stored in SSD cache
- **WHEN** desktop client uploads chunks
- **THEN** chunks are stored in `/storage/upload_cache/chunks/`

#### Scenario: Personal drive chunks stored in SSD cache
- **WHEN** personal drive uploads chunks
- **THEN** chunks are stored in `/storage/upload_cache/drive_chunks/`

### Requirement: Unified Chunk Size
The system SHALL use 90MB as the standard chunk size for all upload APIs.

#### Scenario: Desktop upload uses 90MB chunks
- **WHEN** desktop client initiates upload
- **THEN** API returns part_size of 94371840 bytes (90MB)

#### Scenario: Personal drive uses 90MB chunks
- **WHEN** personal drive initiates upload
- **THEN** API returns part_size of 94371840 bytes (90MB)

### Requirement: Async S3 Delete
The system SHALL delete S3 files asynchronously after database record deletion.

#### Scenario: Recycle bin delete returns immediately
- **WHEN** admin permanently deletes file from recycle bin
- **THEN** database record is deleted immediately
- **AND** success response is returned
- **AND** S3 file is deleted in background
