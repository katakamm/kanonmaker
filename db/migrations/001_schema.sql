CREATE TABLE school (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE canon (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id   INT UNSIGNED NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    UNIQUE KEY uq_canon_school_year (school_id, school_year),
    CONSTRAINT fk_canon_school FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chapter (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id   INT UNSIGNED NOT NULL,
    name       VARCHAR(255) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_chapter_canon_sort (canon_id, sort_order),
    CONSTRAINT fk_chapter_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE author (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    surname      VARCHAR(190) NOT NULL,
    first_name   VARCHAR(190) NULL,
    display_name VARCHAR(255) NOT NULL,
    match_key    VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_author_key (match_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id    INT UNSIGNED NOT NULL,
    chapter_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(500) NOT NULL,
    note        TEXT NULL,
    source_line TEXT NOT NULL,
    sort_order  INT UNSIGNED NOT NULL,
    match_key   VARCHAR(255) NOT NULL,
    search_text VARCHAR(700) NOT NULL,
    UNIQUE KEY uq_work_canon_key (canon_id, match_key),
    KEY ix_work_search (search_text(191)),
    KEY ix_work_chapter (chapter_id),
    CONSTRAINT fk_work_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE,
    CONSTRAINT fk_work_chapter FOREIGN KEY (chapter_id) REFERENCES chapter (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_author (
    work_id   INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (work_id, author_id),
    KEY ix_work_author_author (author_id),
    CONSTRAINT fk_wa_work FOREIGN KEY (work_id) REFERENCES work (id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_author FOREIGN KEY (author_id) REFERENCES author (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tag (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id   INT UNSIGNED NOT NULL,
    tag_group  VARCHAR(32) NOT NULL,
    code       VARCHAR(64) NOT NULL,
    label      VARCHAR(190) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_tag_canon_group_code (canon_id, tag_group, code),
    CONSTRAINT fk_tag_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_tag (
    work_id  INT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    source   ENUM('document','inferred','human') NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (work_id, tag_id),
    KEY ix_work_tag_tag (tag_id),
    CONSTRAINT fk_wt_work FOREIGN KEY (work_id) REFERENCES work (id) ON DELETE CASCADE,
    CONSTRAINT fk_wt_tag FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(190) NOT NULL,
    role          ENUM('student','admin') NOT NULL DEFAULT 'student',
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL,
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE list (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    canon_id   INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_list_user_canon (user_id, canon_id),
    CONSTRAINT fk_list_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
    CONSTRAINT fk_list_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE list_item (
    list_id  INT UNSIGNED NOT NULL,
    work_id  INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (list_id, work_id),
    KEY ix_list_item_work (work_id),
    CONSTRAINT fk_li_list FOREIGN KEY (list_id) REFERENCES list (id) ON DELETE CASCADE,
    CONSTRAINT fk_li_work FOREIGN KEY (work_id) REFERENCES work (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rule (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canon_id   INT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL,
    type       VARCHAR(32) NOT NULL,
    params     TEXT NOT NULL,
    label      VARCHAR(255) NOT NULL,
    enabled    TINYINT(1) NOT NULL DEFAULT 1,
    KEY ix_rule_canon (canon_id, sort_order),
    CONSTRAINT fk_rule_canon FOREIGN KEY (canon_id) REFERENCES canon (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
