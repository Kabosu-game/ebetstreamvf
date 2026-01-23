-- Script SQL pour créer la table payment_methods et insérer les données
-- Exécutez ce script dans phpMyAdmin ou MySQL

-- Créer la table
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('deposit','withdrawal') NOT NULL,
  `method_key` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `min_amount` decimal(10,2) DEFAULT NULL,
  `max_amount` decimal(10,2) DEFAULT NULL,
  `fee_percentage` decimal(5,2) DEFAULT NULL,
  `fee_fixed` decimal(10,2) DEFAULT NULL,
  `crypto_address` varchar(255) DEFAULT NULL,
  `crypto_network` varchar(255) DEFAULT NULL,
  `mobile_money_provider` varchar(255) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_methods_type_index` (`type`),
  KEY `payment_methods_method_key_index` (`method_key`),
  KEY `payment_methods_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insérer les méthodes de dépôt
INSERT INTO `payment_methods` (`name`, `description`, `type`, `method_key`, `is_active`, `min_amount`, `max_amount`, `fee_percentage`, `fee_fixed`, `crypto_address`, `crypto_network`, `mobile_money_provider`, `bank_name`, `created_at`, `updated_at`) VALUES
('USDT (TRC20)', 'Dépôt via USDT sur le réseau TRON', 'deposit', 'crypto', 1, 5.00, 10000.00, 0.00, NULL, 'TSf7x19gfn72Jk4Ah4RWVYuGxvYt5HMWqc', 'TRON (TRC20)', NULL, NULL, NOW(), NOW()),
('USDT (ERC20)', 'Dépôt via USDT sur le réseau Ethereum', 'deposit', 'crypto', 1, 5.00, 10000.00, 0.00, NULL, '0xAbc123Def456Ghi789Jkl0Mno1Pqr2Stu3Vwx4Yz5', 'Ethereum (ERC20)', NULL, NULL, NOW(), NOW()),
('Bitcoin (BTC)', 'Dépôt via Bitcoin', 'deposit', 'crypto', 1, 20.00, 20000.00, 1.00, NULL, 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', 'Bitcoin', NULL, NULL, NOW(), NOW()),
('Ethereum (ETH)', 'Dépôt via Ethereum', 'deposit', 'crypto', 1, 10.00, 15000.00, 0.50, NULL, '0x742d35Cc6634C0532925a3b844Bc454e4438f444', 'Ethereum', NULL, NULL, NOW(), NOW()),
('Cash via Agent', 'Dépôt en espèces via un agent de recharge', 'deposit', 'cash', 1, 1.00, 5000.00, NULL, 0.50, NULL, NULL, NULL, NULL, NOW(), NOW());

-- Insérer les méthodes de retrait
INSERT INTO `payment_methods` (`name`, `description`, `type`, `method_key`, `is_active`, `min_amount`, `max_amount`, `fee_percentage`, `fee_fixed`, `crypto_address`, `crypto_network`, `mobile_money_provider`, `bank_name`, `created_at`, `updated_at`) VALUES
('USDT (TRC20)', 'Retrait via USDT sur le réseau TRON', 'withdrawal', 'crypto', 1, 10.00, 50000.00, 0.50, 1.00, NULL, 'TRON (TRC20)', NULL, NULL, NOW(), NOW()),
('USDT (ERC20)', 'Retrait via USDT sur le réseau Ethereum', 'withdrawal', 'crypto', 1, 10.00, 50000.00, 0.80, 2.00, NULL, 'Ethereum (ERC20)', NULL, NULL, NOW(), NOW()),
('Bitcoin (BTC)', 'Retrait via Bitcoin', 'withdrawal', 'crypto', 1, 50.00, 100000.00, 1.50, 5.00, NULL, 'Bitcoin', NULL, NULL, NOW(), NOW()),
('Ethereum (ETH)', 'Retrait via Ethereum', 'withdrawal', 'crypto', 1, 30.00, 80000.00, 1.20, 3.00, NULL, 'Ethereum', NULL, NULL, NOW(), NOW()),
('Virement Bancaire', 'Retrait par virement bancaire', 'withdrawal', 'bank_transfer', 1, 20.00, 25000.00, NULL, 2.00, NULL, NULL, NULL, 'Any Bank', NOW(), NOW()),
('MTN Mobile Money', 'Retrait via MTN Mobile Money', 'withdrawal', 'mobile_money', 1, 5.00, 1000.00, 1.00, NULL, NULL, NULL, 'MTN', NULL, NOW(), NOW()),
('Orange Money', 'Retrait via Orange Money', 'withdrawal', 'mobile_money', 1, 5.00, 1000.00, 1.00, NULL, NULL, NULL, 'Orange', NULL, NOW(), NOW()),
('Moov Money', 'Retrait via Moov Money', 'withdrawal', 'mobile_money', 1, 5.00, 1000.00, 1.00, NULL, NULL, NULL, 'Moov', NULL, NOW(), NOW());


