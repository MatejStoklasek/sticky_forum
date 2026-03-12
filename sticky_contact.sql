CREATE DATABASE IF NOT EXISTS sticky_contact
    CHARACTER SET utf8
    COLLATE utf8_czech_ci;

USE sticky_contact;

CREATE TABLE IF NOT EXISTS users
(
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(80)  NOT NULL,
    email         VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_email (email)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8;

CREATE TABLE IF NOT EXISTS messages
(
    id         INT UNSIGNED NOT NULL auto_increment,
    user_id    INT UNSIGNED NOT NULL,
    full_name  VARCHAR(80)  NOT NULL,
    email      VARCHAR(160) NOT NULL,
    topic      VARCHAR(40)  NOT NULL,
    urgency    VARCHAR(20)  NOT NULL,
    color      VARCHAR(10)  NOT NULL,
    newsletter TINYINT(1)   NOT NULL DEFAULT 0,
    agree      TINYINT(1)   NOT NULL DEFAULT 0,
    message    TEXT         NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_created_at (created_at),
    KEY idx_topic (topic),
    KEY idx_user (user_id),
    CONSTRAINT fk_messages_user
        FOREIGN KEY (user_id) REFERENCES users (id)
            ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8;

CREATE TABLE IF NOT EXISTS auth_tokens
(
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    selector   CHAR(16)     NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_selector (selector),
    KEY idx_user_id (user_id),
    KEY idx_expires (expires_at),
    CONSTRAINT fk_auth_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id)
            ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8;

CREATE TABLE notes (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  content VARCHAR(2000) NOT NULL,
  style_seed INT NOT NULL DEFAULT 1,
  trashed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (user_id, trashed_at)
);

CREATE TABLE IF NOT EXISTS message_trash
(
  user_id    INT UNSIGNED NOT NULL,
  message_id INT UNSIGNED NOT NULL,
  trashed_at DATETIME     NOT NULL,
  PRIMARY KEY (user_id, message_id),
  KEY idx_user_trashed_at (user_id, trashed_at),
  CONSTRAINT fk_message_trash_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_message_trash_message
    FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS message_backup
(
  user_id      INT UNSIGNED NOT NULL,
  message_id   INT UNSIGNED NOT NULL,
  backed_up_at DATETIME     NOT NULL,
  PRIMARY KEY (user_id, message_id),
  KEY idx_user_backed_up_at (user_id, backed_up_at),
  CONSTRAINT fk_message_backup_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_message_backup_message
    FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Pozn.: původní message_trash můžeš po migraci klidně dropnout.