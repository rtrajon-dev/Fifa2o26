-- GoalJeeto — full database import (MySQL / phpMyAdmin)
-- Import ONCE into your cPanel MySQL database. Creates every table.
--
-- Unlike QuizJeeto, there is NO seed content here. Fixtures are not written in
-- SQL — they live in data/schedule.php, which you hand-edit, and sync_matches.php
-- upserts them into the `matches` table keyed on `code`. That is what lets a
-- match sit as 'TBD' today and get real team names tomorrow without losing the
-- predictions already attached to it.
--
--   php sync_matches.php        # after every edit to data/schedule.php
--   php settle.php FINAL 2 1    # after a match ends: enter the real score

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS predictions;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    phone_hash   CHAR(64) NOT NULL UNIQUE,
    phone_masked VARCHAR(32) NOT NULL,
    display_name VARCHAR(64) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE matches (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    -- Stable identifier from data/schedule.php ('QF-1', 'SF-2', 'FINAL', ...).
    -- NEVER changes, even while the teams are still TBD. Everything keys off it.
    code        VARCHAR(24) NOT NULL UNIQUE,
    label       VARCHAR(64) NOT NULL DEFAULT '',
    home_team   VARCHAR(64) NOT NULL DEFAULT 'TBD',
    away_team   VARCHAR(64) NOT NULL DEFAULT 'TBD',
    venue       VARCHAR(128) NOT NULL DEFAULT '',
    kickoff_at  DATETIME NOT NULL,

    -- upcoming → accepting predictions (if teams are confirmed & kickoff is future)
    -- locked   → kickoff passed, awaiting the real result
    -- finished → result entered and every prediction settled
    status      ENUM('upcoming','locked','finished') NOT NULL DEFAULT 'upcoming',

    home_goals  TINYINT UNSIGNED NULL,
    away_goals  TINYINT UNSIGNED NULL,
    settled_at  DATETIME NULL,

    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_matches_kickoff (kickoff_at),
    INDEX idx_matches_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE predictions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    match_id        INT NOT NULL,

    -- 0..5, where 5 means "৫ বা তার বেশি" (5 or more goals in the match).
    predicted_goals TINYINT UNSIGNED NOT NULL,

    points          INT NOT NULL DEFAULT 0,
    is_settled      TINYINT(1) NOT NULL DEFAULT 0,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- One prediction per player per match. Editable until kickoff, then frozen.
    UNIQUE KEY uq_prediction (user_id, match_id),
    INDEX idx_pred_match (match_id),
    INDEX idx_pred_settled (is_settled),
    CONSTRAINT fk_pred_user  FOREIGN KEY (user_id)  REFERENCES users(id),
    CONSTRAINT fk_pred_match FOREIGN KEY (match_id) REFERENCES matches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
