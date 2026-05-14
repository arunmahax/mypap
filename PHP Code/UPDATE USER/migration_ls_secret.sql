-- Migration: Add Lemon Squeezy webhook secret and billing plan flags to tbl_settings
-- Run this on existing installations

ALTER TABLE `tbl_settings`
  ADD COLUMN IF NOT EXISTS `plan_annual_enabled`   varchar(10) NOT NULL DEFAULT 'true'  AFTER `is_theme`,
  ADD COLUMN IF NOT EXISTS `plan_lifetime_enabled` varchar(10) NOT NULL DEFAULT 'true'  AFTER `plan_annual_enabled`,
  ADD COLUMN IF NOT EXISTS `ls_webhook_secret`     varchar(255) NOT NULL DEFAULT ''     AFTER `plan_lifetime_enabled`;
