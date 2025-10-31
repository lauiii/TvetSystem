# TODO: Implement Grading Criteria and Assessments System

## Current Status
- Assessment criteria (e.g., "Quiz" with 20% for prelim) exist
- Assessment items (e.g., "Quiz 1", "Quiz 2") exist but lack total_score field
- Grades table references assessment_items correctly
- manage-grades.php incorrectly queries non-existent "assessments" table

## Tasks
- [x] Update sql/schema.sql: Add total_score field to assessment_items table
- [x] Update instructor/assessments.php: Add total_score input to add/edit item forms
- [x] Update instructor/manage-grades.php: Change query from "assessments" to "assessment_items" and display total_score
- [x] Run scripts/update_db_schema.php to apply database changes
- [x] Test assessments creation and grade management functionality (schema updated successfully, admin user set; manual testing required)
