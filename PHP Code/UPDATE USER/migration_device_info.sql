-- Migration: Add device_type and first_seen columns to tbl_users
-- Run this on existing installations that already have tbl_users

ALTER TABLE `tbl_users`
  ADD COLUMN IF NOT EXISTS `device_type` varchar(50) NOT NULL DEFAULT '' AFTER `app_version`,
  ADD COLUMN IF NOT EXISTS `first_seen` datetime DEFAULT NULL AFTER `device_type`;

-- Set first_seen = last_seen for existing rows (best estimate)
UPDATE `tbl_users` SET `first_seen` = `last_seen` WHERE `first_seen` IS NULL;
