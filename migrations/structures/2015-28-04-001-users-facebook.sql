ALTER TABLE `users` ADD COLUMN `facebook_id` VARCHAR(16) DEFAULT NULL,
ADD COLUMN `facebook_access_token` VARCHAR(256) DEFAULT NULL,
ADD COLUMN `last_name` VARCHAR(20) DEFAULT NULL,
ADD COLUMN `first_name` VARCHAR(20) DEFAULT NULL;