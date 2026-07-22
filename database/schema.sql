-- database/schema.sql
CREATE DATABASE IF NOT EXISTS currii CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE currii;

CREATE TABLE Users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Guest', 'User', 'Admin') DEFAULT 'User',
    status ENUM('Active', 'Disabled') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

CREATE TABLE GuestSessions (
    session_id VARCHAR(128) PRIMARY KEY,
    visit_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL
);

CREATE TABLE Categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- NULL means global/default category
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE
);

CREATE TABLE Feeds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(500) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    website_url VARCHAR(500),
    favicon_url VARCHAR(500),
    last_fetched TIMESTAMP NULL,
    health_status ENUM('Online', 'Offline', 'Invalid') DEFAULT 'Online',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE Subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    feed_id INT NOT NULL,
    category_id INT NULL,
    notify_email BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    FOREIGN KEY (feed_id) REFERENCES Feeds(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES Categories(id) ON DELETE SET NULL,
    UNIQUE(user_id, feed_id)
);

CREATE TABLE Articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feed_id INT NOT NULL,
    guid VARCHAR(255) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    content LONGTEXT,
    author VARCHAR(150),
    published_at DATETIME NOT NULL,
    FOREIGN KEY (feed_id) REFERENCES Feeds(id) ON DELETE CASCADE,
    INDEX idx_feed_published (feed_id, published_at)
);

CREATE TABLE UserArticles (
    user_id INT NOT NULL,
    article_id INT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    is_bookmarked BOOLEAN DEFAULT FALSE,
    PRIMARY KEY (user_id, article_id),
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES Articles(id) ON DELETE CASCADE
);

CREATE TABLE Preferences (
    user_id INT PRIMARY KEY,
    theme ENUM('light', 'dark', 'system') DEFAULT 'system',
    font_size INT DEFAULT 16,
    refresh_interval INT DEFAULT 30,
    show_ads BOOLEAN DEFAULT TRUE,
    global_notifications BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE
);

CREATE TABLE Tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    UNIQUE (user_id, name)
);

CREATE TABLE Article_Tags (
    article_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (article_id, tag_id),
    FOREIGN KEY (article_id) REFERENCES Articles(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES Tags(id) ON DELETE CASCADE
);

CREATE TABLE Notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    article_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES Articles(id) ON DELETE CASCADE
);

CREATE TABLE PasswordResets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    INDEX (token_hash)
);

-- ============================================================================
-- Seed data: one working admin account + three working registered accounts.
-- Passwords below are hashed with bcrypt ($2b$10$...), which PHP's
-- password_verify() reads identically to password_hash()'s own $2y$ output —
-- these hashes were generated correctly, not placeholders. Plaintext
-- credentials are listed here only so you can log in immediately; delete this
-- comment block (or rotate the passwords) before using this in production.
--
--   Email                   Password            Role   Status
--   ----------------------  ------------------  -----  --------
--   admin@currii.app        AdminCurrii#2026    Admin  Active
--   alice@currii.app        AliceReads#88       User   Active
--   bob@currii.app          BobBrowses#42       User   Active
--   carol@currii.app        CarolCurates#17     User   Disabled  (for testing the "re-enable" admin action)
-- ============================================================================

INSERT INTO Users (email, password_hash, role, status) VALUES
('admin@currii.app', '$2b$10$ziomkebX0lGEl9OMJZC9FO1keNJ3EXezg7ClxKR37XpX17gRULdr6', 'Admin', 'Active'),
('alice@currii.app', '$2b$10$0ugQsE9Bc7Jj.658dKsq7eS4PoIqqwCHDbntDZIaUYcNVACa/UqIm', 'User',  'Active'),
('bob@currii.app',   '$2b$10$P/mYQyfMjuC/vCl4JCRZ2OHBsE5zRopjocNvR1HEuGHVsA/VkZLGi', 'User',  'Active'),
('carol@currii.app', '$2b$10$mTeZWoezsG.JKayC3FtmturAlz2Sh9nU3zZBwu6ZxMiPQkBWoRuuS', 'User',  'Disabled');

INSERT INTO Preferences (user_id)
SELECT id FROM Users WHERE email IN ('admin@currii.app', 'alice@currii.app', 'bob@currii.app', 'carol@currii.app');