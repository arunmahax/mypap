-- Migration: Add ip_address and country columns to tbl_users
-- Run this on existing installations

ALTER TABLE `tbl_users`
  ADD COLUMN `ip_address` varchar(45) NOT NULL DEFAULT '' AFTER `device_type`,
  ADD COLUMN `country`    varchar(100) NOT NULL DEFAULT '' AFTER `ip_address`;
