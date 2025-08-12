-- VMForge Billing System
-- Stage 1: Core billing tables

CREATE TABLE `billing_cycles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(64) NOT NULL, -- e.g., 'monthly', 'quarterly', 'annually'
  `months` INT NOT NULL
);

INSERT INTO `billing_cycles` (`name`, `months`) VALUES
('monthly', 1),
('quarterly', 3),
('annually', 12);

CREATE TABLE `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(190) NOT NULL,
  `description` TEXT,
  `price` DECIMAL(10, 2) NOT NULL,
  `billing_cycle_id` INT NOT NULL,
  `vcpus` INT NOT NULL,
  `memory_mb` INT NOT NULL,
  `disk_gb` INT NOT NULL,
  `bandwidth_gb` INT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`billing_cycle_id`) REFERENCES `billing_cycles`(`id`)
);

CREATE TABLE `customers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `company_name` VARCHAR(255),
  `address1` VARCHAR(255),
  `address2` VARCHAR(255),
  `city` VARCHAR(100),
  `state` VARCHAR(100),
  `zip` VARCHAR(20),
  `country` VARCHAR(2), -- ISO 3166-1 alpha-2
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

CREATE TABLE `subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `vm_instance_id` INT NULL, -- Can be null if service not yet provisioned
  `status` ENUM('active', 'pending', 'cancelled', 'terminated') NOT NULL DEFAULT 'pending',
  `next_due_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
  FOREIGN KEY (`vm_instance_id`) REFERENCES `vm_instances`(`id`) ON DELETE SET NULL
);

CREATE TABLE `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `subscription_id` INT,
  `status` ENUM('unpaid', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'unpaid',
  `issue_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `paid_date` DATE,
  `total` DECIMAL(10, 2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`)
);

CREATE TABLE `invoice_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
);

CREATE TABLE `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `gateway` VARCHAR(64) NOT NULL, -- e.g., 'stripe', 'paypal'
  `transaction_id` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `status` ENUM('completed', 'pending', 'failed', 'refunded') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`)
);

CREATE TABLE `billing_settings` (
  `setting` VARCHAR(190) PRIMARY KEY,
  `value` TEXT
);

INSERT INTO `billing_settings` (`setting`, `value`) VALUES
('currency', 'USD'),
('tax_rate', '0');
