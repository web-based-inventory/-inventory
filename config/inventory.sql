--users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100)  NOT NULL,
  `username` varchar(50)  NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff','cashier') DEFAULT 'staff',
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB;

--categories table
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB;

--unit table
CREATE TABLE IF NOT EXISTS `units` (
  `unit_id` int NOT NULL AUTO_INCREMENT,
  `unit_name` varchar(50) NOT NULL,
  `unit_symbol` varchar(20) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`unit_id`)
) ENGINE=InnoDB;


--product table
CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `unit_id` int DEFAULT NULL,
  `selling_price` decimal(10,2) DEFAULT '0.00',
  `current_stock` int DEFAULT '0',
  `reorder_level` int DEFAULT '5',
  `image` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `price_update_required` tinyint(1) NOT NULL DEFAULT '0',
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `purchase_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `category_id` (`category_id`),
  KEY `fk_products_unit` (`unit_id`),
  FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

) ENGINE=InnoDB;

--supplier
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `current_balance` decimal(10,2) DEFAULT '0.00',
  `advance_balance` decimal(10,2) DEFAULT '0.00',
  `outstanding_balance` decimal(10,2) DEFAULT '0.00',
  `advance_credit` decimal(10,2) DEFAULT '0.00',
  `balance_type` enum('Clear','Payable','Advance') NOT NULL DEFAULT 'Clear',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

--supplier ledger
CREATE TABLE IF NOT EXISTS `supplier_ledger` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `transaction_type` enum('Purchase','Payment','Advance Applied','Advance Created','Adjustment') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `debit` decimal(15,2) DEFAULT '0.00',
  `credit` decimal(15,2) DEFAULT '0.00',
  `balance` decimal(15,2) DEFAULT '0.00',
  `description` text,
  `transaction_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_reference` (`reference_type`,`reference_id`)
) ENGINE=InnoDB;

--supplier_payments table
CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `payment_method` enum('Cash','KBZPay','Mixed') NOT NULL,
  `cash_amount` decimal(10,2) DEFAULT '0.00',
  `kbzpay_amount` decimal(10,2) DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `notes` text,
  `ref_no` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_date` (`payment_date`)
) ENGINE=InnoDB;

--purchases table
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `user_id` int NOT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `total_paid` decimal(15,2) DEFAULT '0.00',
  `remaining_balance` decimal(15,2) DEFAULT '0.00',
  `payment_status` enum('Paid','Unpaid') DEFAULT 'Unpaid',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `user_id` (`user_id`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
) ENGINE=InnoDB;

--purchase_detail table
CREATE TABLE IF NOT EXISTS `purchase_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int DEFAULT '0',
  `purchase_price` decimal(10,2) DEFAULT '0.00',
  `subtotal` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `product_id` (`product_id`),
  FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
) ENGINE=InnoDB;

--purchase_payments
CREATE TABLE IF NOT EXISTS `purchase_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `payment_method` enum('Cash','KBZPay','Mixed') NOT NULL,
  `cash_amount` decimal(10,2) DEFAULT '0.00',
  `kbzpay_amount` decimal(10,2) DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `advance_applied` decimal(10,2) DEFAULT '0.00',
  `advance_created` decimal(10,2) DEFAULT '0.00',
  `remaining_balance` decimal(10,2) DEFAULT '0.00',
  `payment_status` enum('Paid','Partial','Unpaid') DEFAULT 'Paid',
  `payment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

) ENGINE=InnoDB;

--sales table
CREATE TABLE IF NOT EXISTS `sales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) NOT NULL,
  `user_id` int DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT '0.00',
  `discount` decimal(10,2) DEFAULT '0.00',
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `payment_method` enum('Cash','Kpay','Mixed') DEFAULT 'Cash',
  `paid_amount` decimal(10,2) DEFAULT '0.00',
  `change_amount` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
) ENGINE=InnoDB;

--sale_details table
CREATE TABLE IF NOT EXISTS `sale_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int DEFAULT '0',
  `purchase_price` decimal(10,2) DEFAULT '0.00',
  `selling_price` decimal(10,2) DEFAULT '0.00',
  `subtotal` decimal(10,2) DEFAULT '0.00',
  `profit` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB;

--sale_payments
CREATE TABLE IF NOT EXISTS `sale_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `payment_method` enum('Cash','KBZPay','Mixed') NOT NULL,
  `cash_amount` decimal(10,2) DEFAULT '0.00',
  `kbzpay_amount` decimal(10,2) DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `change_amount` decimal(10,2) DEFAULT '0.00',
  `payment_status` enum('Paid','Partial','Unpaid') DEFAULT 'Paid',
  `payment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`)
);

--forecast table
CREATE TABLE IF NOT EXISTS `forecasts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `forecast_date` date DEFAULT NULL,
  `forecast_quantity` int DEFAULT '0',
  `demand_level` enum('High','Medium','Low')  DEFAULT 'Medium',
  `recommended_stock` int DEFAULT '0',
  `method` varchar(50) DEFAULT 'moving_average',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;


--settings table
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shop_name` varchar(100) DEFAULT 'Smart Inventory',
  `logo` varchar(255) DEFAULT NULL,
  `phone` varchar(50)  DEFAULT NULL,
  `email` varchar(100)  DEFAULT NULL,
  `address` text,
  `currency` varchar(10) DEFAULT 'Ks',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `minimum_profit_margin` decimal(5,2) DEFAULT '10.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;















