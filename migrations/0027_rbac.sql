-- VMForge RBAC System

-- 1. Roles table
CREATE TABLE `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(190) UNIQUE NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Permissions table
CREATE TABLE `permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(190) UNIQUE NOT NULL, -- e.g., 'users.create', 'vms.delete'
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Role-Permissions pivot table
CREATE TABLE `role_permissions` (
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
);

-- 4. User-Roles pivot table
CREATE TABLE `user_roles` (
  `user_id` INT NOT NULL,
  `role_id` INT NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
);

-- 5. Add some default roles and permissions
INSERT INTO `roles` (`name`, `description`) VALUES
('admin', 'Super Administrator - has all permissions'),
('billing', 'Billing Manager - can view and manage billing information'),
('support', 'Support Staff - can manage VMs and support tickets'),
('customer', 'Customer - can manage their own VMs and billing');

INSERT INTO `permissions` (`name`, `description`) VALUES
-- VM Management
('vms.view', 'View virtual machines'),
('vms.create', 'Create virtual machines'),
('vms.update', 'Update virtual machines'),
('vms.delete', 'Delete virtual machines'),
('vms.console', 'Access VM console'),
-- User Management
('users.view', 'View users'),
('users.create', 'Create users'),
('users.update', 'Update users'),
('users.delete', 'Delete users'),
-- Billing Management
('billing.view', 'View billing information'),
('billing.manage', 'Manage billing information'),
-- Role and Permission Management
('rbac.view', 'View roles and permissions'),
('rbac.manage', 'Manage roles and permissions'),
-- Ticket Management
('tickets.manage', 'Manage support tickets');

-- 6. Assign permissions to roles (example setup)
-- For now, we will handle the "admin" role dynamically in the code to have all permissions.
-- Let's set up the 'billing' role.
SET @billing_role_id = (SELECT id FROM `roles` WHERE `name` = 'billing');
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @billing_role_id, id FROM `permissions` WHERE `name` LIKE 'billing.%';

-- Let's set up the 'customer' role.
SET @customer_role_id = (SELECT id FROM `roles` WHERE `name` = 'customer');
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT @customer_role_id, id FROM `permissions` WHERE `name` IN ('vms.view', 'vms.create', 'vms.update', 'vms.delete', 'vms.console', 'billing.view');

-- 7. Remove old is_admin column from users table (optional, but good for cleanup)
-- I will comment this out for now to avoid breaking existing logic until the new system is fully integrated.
-- ALTER TABLE `users` DROP COLUMN `is_admin`;
