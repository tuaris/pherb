-- Add current_stage column to track pipeline progress for resume capability
ALTER TABLE jobs ADD COLUMN current_stage VARCHAR(32) DEFAULT NULL AFTER status;
