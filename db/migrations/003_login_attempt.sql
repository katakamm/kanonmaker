CREATE TABLE login_attempt (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(190) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY ix_login_attempt (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
