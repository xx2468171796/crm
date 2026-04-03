## ADDED Requirements

### Requirement: Approval table sorting
The `ApprovalTable` component SHALL apply client-side sorting to the file list based on the selected sort key and sort order before rendering.

#### Scenario: Sort by upload time descending (default)
- **WHEN** the approval table loads with default sort settings
- **THEN** files are displayed ordered by upload time descending (newest first)

#### Scenario: Sort by filename ascending
- **WHEN** user clicks the "文件名" column header
- **THEN** files are re-ordered alphabetically by filename in ascending order

#### Scenario: Toggle sort order
- **WHEN** user clicks the same column header again
- **THEN** the sort order toggles between ascending and descending

### Requirement: Approval button permission check
The file approval buttons (approve/reject) in `ProjectDetailPage` list view SHALL only be visible to users with manager-level permissions.

#### Scenario: Manager sees approval buttons on pending files
- **WHEN** a user with manager role views a file with `approval_status = 'pending'` in the list view
- **THEN** the "通过" and "驳回" buttons are displayed

#### Scenario: Non-manager cannot see approval buttons
- **WHEN** a non-manager user (e.g., tech role) views a file with `approval_status = 'pending'` in the list view
- **THEN** no approval buttons are displayed, only the status badge

### Requirement: Approval status display for all file categories
The approval status badge and action buttons SHALL be displayed for files in ALL categories (客户文件, 作品文件, 模型文件), not limited to "作品文件" only.

#### Scenario: Approval status shown for customer files
- **WHEN** a file in "客户文件" category has `approval_status = 'pending'`
- **THEN** the pending status badge and (for managers) approval buttons are displayed

#### Scenario: Approval status shown for model files
- **WHEN** a file in "模型文件" category has `approval_status = 'rejected'`
- **THEN** the rejected status badge and resubmit button are displayed

### Requirement: Unique file listing in approval queries
The backend approval list API SHALL return each file exactly once, even when a project has multiple tech user assignments.

#### Scenario: File with multiple tech assignments appears once
- **WHEN** a project has 3 tech users assigned and 1 deliverable file
- **THEN** the API returns exactly 1 file record (not 3 duplicates)

#### Scenario: Pagination count is accurate
- **WHEN** the total count query runs
- **THEN** the total reflects the number of unique files, not inflated by JOINs

### Requirement: Consistent manager role definition
The `design_manager` role SHALL be recognized as a manager role in BOTH frontend and backend systems.

#### Scenario: design_manager can approve files via API
- **WHEN** a user with `design_manager` role calls the approve API
- **THEN** the API accepts the request and updates the file status

#### Scenario: design_manager sees approval UI in frontend
- **WHEN** a user with `design_manager` role views the approval page
- **THEN** the approval buttons and manager-only UI elements are displayed

### Requirement: Secure token transmission
The HTTP client SHALL transmit authentication tokens exclusively via the `Authorization: Bearer` header for ALL request methods, never in URL query parameters.

#### Scenario: GET request uses Authorization header
- **WHEN** the HTTP client sends a GET request with an auth token
- **THEN** the token is included in the `Authorization: Bearer <token>` header, not in the URL

#### Scenario: Token not visible in URL
- **WHEN** any authenticated request is made
- **THEN** the token does not appear in the URL string

### Requirement: Approval operation state validation
The backend approval API SHALL verify that a file is in `pending` status before allowing approve or reject operations.

#### Scenario: Approve a pending file succeeds
- **WHEN** an approve request is made for a file with `approval_status = 'pending'`
- **THEN** the file status is updated to `approved` and the response is successful

#### Scenario: Approve an already approved file fails
- **WHEN** an approve request is made for a file with `approval_status = 'approved'`
- **THEN** the API returns an error indicating the file status has already changed

#### Scenario: Reject a non-pending file fails
- **WHEN** a reject request is made for a file with `approval_status = 'rejected'`
- **THEN** the API returns an error with a descriptive message

### Requirement: Transactional batch delete
The batch delete operation SHALL be wrapped in a database transaction to ensure atomicity.

#### Scenario: All files deleted successfully
- **WHEN** a batch delete request is made for 5 files and all pass permission checks
- **THEN** all 5 files are soft-deleted in a single transaction

#### Scenario: Partial failure rolls back
- **WHEN** a batch delete request is made for 5 files but the 3rd fails a permission check
- **THEN** the transaction rolls back and no files are deleted; an error response lists which files failed

### Requirement: Atomic file rename
The file rename operation SHALL ensure database updates only occur when S3 storage operations succeed.

#### Scenario: S3 copy succeeds
- **WHEN** a rename operation's S3 copy succeeds
- **THEN** the old S3 object is deleted and the database is updated with the new name and path

#### Scenario: S3 copy fails
- **WHEN** a rename operation's S3 copy fails
- **THEN** the database is NOT updated and an error is returned to the client

### Requirement: Authenticated evaluation data loading
The evaluation data API call SHALL include the `Authorization` header for authentication.

#### Scenario: Load evaluation with auth token
- **WHEN** the `loadEvaluation` function fetches evaluation data
- **THEN** the request includes the `Authorization: Bearer <token>` header

### Requirement: Custom rejection reason dialog
The batch reject action in `ProjectDetailPage` SHALL use a custom dialog component instead of the native `prompt()` function.

#### Scenario: Batch reject opens custom dialog
- **WHEN** a manager clicks "批量驳回" in the file category header
- **THEN** a custom modal dialog appears with a textarea for entering the rejection reason

#### Scenario: Cancel batch reject
- **WHEN** the user clicks "取消" in the rejection reason dialog
- **THEN** the dialog closes and no reject operation is performed

### Requirement: Tab switch filter reset
The `ApprovalPage` SHALL reset the status filter when switching between "待审批文件" and "我的文件" tabs.

#### Scenario: Switch to my_files tab resets status
- **WHEN** user switches from "待审批文件" (with status filter 'rejected') to "我的文件" tab
- **THEN** the status filter resets to 'all' and the file list refreshes

### Requirement: Deduplicated utility functions
The `formatFileSize` utility function SHALL be defined once in `utils.ts` and imported by all consumers.

#### Scenario: All components use shared formatFileSize
- **WHEN** a component needs to format a file size
- **THEN** it imports `formatFileSize` from `@/lib/utils` instead of defining a local version

### Requirement: Clean production logging
The `ProjectDetailPage` component SHALL NOT contain debug `console.log` statements in production code. Only `console.error` for error handling is permitted.

#### Scenario: No debug logs in production
- **WHEN** the `ProjectDetailPage` component renders
- **THEN** no `console.log` debug messages are written to the console

#### Scenario: Error logging preserved
- **WHEN** an API call fails in `ProjectDetailPage`
- **THEN** `console.error` logs the error for diagnostics

### Requirement: Prepared statements for all SQL
All SQL queries in the approval API SHALL use PDO prepared statements with parameterized values.

#### Scenario: Stats query uses prepared statements
- **WHEN** the `handleStats` function builds a query with user-dependent conditions
- **THEN** all dynamic values are passed as prepared statement parameters, not string-interpolated
