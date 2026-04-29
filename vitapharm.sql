CREATE DATABASE IF NOT EXISTS vitapharm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vitapharm_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    name_ar VARCHAR(255) NOT NULL,
    name_en VARCHAR(255) NOT NULL,
    price_dzd DECIMAL(10,2) NOT NULL,
    price_usd DECIMAL(10,2) NOT NULL,
    qty INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_user_product (user_id, product_id),
    CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS orders (
    id VARCHAR(40) PRIMARY KEY,
    user_id INT NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    status ENUM('Pending', 'Processing', 'Completed') NOT NULL DEFAULT 'Pending',
    payment ENUM('cash', 'gold', 'card') NOT NULL,
    total_dzd DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_usd DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(40) NOT NULL,
    product_id INT NOT NULL,
    name_ar VARCHAR(255) NOT NULL,
    name_en VARCHAR(255) NOT NULL,
    price_dzd DECIMAL(10,2) NOT NULL,
    price_usd DECIMAL(10,2) NOT NULL,
    qty INT NOT NULL,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

INSERT INTO users (username, password_hash, role)
VALUES
('client1', '123', 'client'),
('staff1', '123', 'staff'),
('admin1', '123', 'admin')
ON DUPLICATE KEY UPDATE username = VALUES(username);

ALTER TABLE users
    MODIFY username VARCHAR(255) NOT NULL,
    MODIFY password_hash TEXT NOT NULL,
    MODIFY role VARCHAR(50) NOT NULL DEFAULT 'client';

