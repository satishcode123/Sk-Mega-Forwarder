-- BotConfig Table to manage dynamic bot settings like URLs
CREATE TABLE `botconfig` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bot_token` varchar(255) DEFAULT NULL,
  `owner_chat_id` int(10) DEFAULT NULL,
  `channels` JSON NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- 1. Users Table (with user's name)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_user_id BIGINT NOT NULL UNIQUE, -- Telegram user ID
    name VARCHAR(255) NOT NULL, -- User's name
    is_premium BOOLEAN DEFAULT FALSE, -- Indicates if the user is a premium subscriber
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE user_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_user_id BIGINT UNIQUE,
    host_channels JSON,
    forwarding_channels JSON
);
CREATE TABLE user_states (
    user_id BIGINT PRIMARY KEY,
    current_action VARCHAR(255) NULL
);

-- 4. Premium Subscriptions Table (Optional)
-- If you want to track premium subscriptions, this table records when a user became premium and when their subscription ends.
CREATE TABLE premium_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    start_date DATE NOT NULL, -- Subscription start date
    end_date DATE NOT NULL, -- Subscription end date
    FOREIGN KEY (user_id) REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Indexes for performance optimization
CREATE INDEX idx_user_id ON users(telegram_user_id);
