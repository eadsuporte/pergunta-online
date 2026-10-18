<?php

declare(strict_types=1);

function run_schema_migrations(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!(bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_meta (
            meta_key VARCHAR(100) PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->query("SELECT meta_value FROM app_meta WHERE meta_key='schema_version' LIMIT 1");
    $version = (int)($stmt->fetchColumn() ?: 0);
    if ($version < 1) {
    migration_add_column($pdo, 'users', 'last_login_at', 'DATETIME NULL AFTER active');
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('user','respondent','admin') NOT NULL DEFAULT 'user'");

    migration_add_column($pdo, 'questions', 'focus_id', 'BIGINT UNSIGNED NULL AFTER user_id');
    migration_add_column($pdo, 'questions', 'category_id', 'BIGINT UNSIGNED NULL AFTER focus_abbr');
    migration_add_column($pdo, 'questions', 'target_id', 'BIGINT UNSIGNED NULL AFTER target_name');
    migration_add_column($pdo, 'questions', 'moderation_status', "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER published");
    migration_add_column($pdo, 'questions', 'taken_by', 'BIGINT UNSIGNED NULL AFTER moderation_status');
    migration_add_column($pdo, 'questions', 'taken_at', 'DATETIME NULL AFTER taken_by');
    migration_add_column($pdo, 'questions', 'duplicate_of', 'BIGINT UNSIGNED NULL AFTER taken_at');
    migration_add_column($pdo, 'questions', 'deleted_at', 'DATETIME NULL AFTER duplicate_of');
    migration_add_column($pdo, 'questions', 'deleted_by', 'BIGINT UNSIGNED NULL AFTER deleted_at');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS focuses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            abbr VARCHAR(12) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS targets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            description TEXT NULL,
            website VARCHAR(500) NULL,
            contact_email VARCHAR(190) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS target_users (
            target_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (target_id,user_id),
            CONSTRAINT fk_target_users_target FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE,
            CONSTRAINT fk_target_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id BIGINT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(60) NOT NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_question_history_question (question_id,created_at),
            CONSTRAINT fk_question_history_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_question_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_evidence (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            evidence_type ENUM('url','document','image','video','text') NOT NULL DEFAULT 'url',
            title VARCHAR(190) NOT NULL,
            reference_value TEXT NULL,
            storage_name VARCHAR(190) NULL,
            original_name VARCHAR(255) NULL,
            mime_type VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_evidence_question (question_id,created_at),
            CONSTRAINT fk_evidence_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_evidence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            reason ENUM('spam','offensive','illegal','duplicate','other') NOT NULL,
            details TEXT NULL,
            status ENUM('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_report_question_user (question_id,user_id),
            INDEX idx_reports_status (status,created_at),
            CONSTRAINT fk_reports_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created (created_at),
            INDEX idx_audit_entity (entity_type,entity_id),
            CONSTRAINT fk_audit_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES
        ('registration_enabled','1'),
        ('auto_publish_questions','1'),
        ('silence_threshold_days','7'),
        ('site_name','Pergunta.Online'),
        ('max_questions_per_page','50')
    ");

    $pdo->exec("INSERT IGNORE INTO focuses (name,abbr)
        SELECT focus_name, MAX(focus_abbr) FROM questions
        WHERE focus_name <> '' GROUP BY focus_name");
    $pdo->exec("INSERT IGNORE INTO categories (name)
        SELECT DISTINCT category FROM questions WHERE category <> ''");
    $pdo->exec("INSERT IGNORE INTO targets (name)
        SELECT DISTINCT target_name FROM questions WHERE target_name <> ''");
    $pdo->exec("UPDATE questions q JOIN focuses f ON f.name=q.focus_name SET q.focus_id=f.id WHERE q.focus_id IS NULL");
    $pdo->exec("UPDATE questions q JOIN categories c ON c.name=q.category SET q.category_id=c.id WHERE q.category_id IS NULL");
    $pdo->exec("UPDATE questions q JOIN targets t ON t.name=q.target_name SET q.target_id=t.id WHERE q.target_id IS NULL");

    $upsert = $pdo->prepare("
        INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','1')
        ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)
    ");
    $upsert->execute();
    $version = 1;
    }

    if ($version < 2) {
        $pdo->exec("INSERT IGNORE INTO focuses (name,abbr)
            SELECT focus_name, MAX(focus_abbr) FROM questions
            WHERE focus_name <> '' GROUP BY focus_name");
        $pdo->exec("INSERT IGNORE INTO categories (name)
            SELECT DISTINCT category FROM questions WHERE category <> ''");
        $pdo->exec("INSERT IGNORE INTO targets (name)
            SELECT DISTINCT target_name FROM questions WHERE target_name <> ''");
        $pdo->exec("UPDATE questions q JOIN focuses f ON f.name=q.focus_name SET q.focus_id=f.id WHERE q.focus_id IS NULL");
        $pdo->exec("UPDATE questions q JOIN categories c ON c.name=q.category SET q.category_id=c.id WHERE q.category_id IS NULL");
        $pdo->exec("UPDATE questions q JOIN targets t ON t.name=q.target_name SET q.target_id=t.id WHERE q.target_id IS NULL");
        $pdo->exec("UPDATE questions SET taken_at=updated_at WHERE status='taken' AND taken_at IS NULL");
        $pdo->exec("
            INSERT INTO question_history (question_id,actor_user_id,event_type,old_status,new_status,details,created_at)
            SELECT q.id,q.user_id,'created',NULL,q.status,'Histórico anterior à implantação da linha do tempo.',q.created_at
            FROM questions q
            WHERE NOT EXISTS (SELECT 1 FROM question_history h WHERE h.question_id=q.id)
        ");
        $upsert = $pdo->prepare("
            INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','2')
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)
        ");
        $upsert->execute();
        $version = 2;
    }

    if ($version < 3) {
        $pdo->exec("UPDATE questions SET taken_at=updated_at WHERE status='taken' AND taken_at IS NULL");
        $pdo->exec("UPDATE questions SET waiting_since=NULL WHERE status NOT IN ('waiting','taken','answered')");
        migration_add_index($pdo, 'questions', 'idx_questions_updated', 'updated_at,created_at');
        migration_add_index($pdo, 'users', 'idx_users_created', 'created_at');
        $upsert = $pdo->prepare("
            INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','3')
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)
        ");
        $upsert->execute();
        $version = 3;
    }

    if ($version < 4) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blog_posts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                author_user_id BIGINT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                excerpt TEXT NULL,
                content LONGTEXT NOT NULL,
                cover_image_url VARCHAR(500) NULL,
                published TINYINT(1) NOT NULL DEFAULT 0,
                published_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_blog_posts_public (published,published_at,created_at),
                CONSTRAINT fk_blog_posts_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $upsert = $pdo->prepare("
            INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','4')
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)
        ");
        $upsert->execute();
        $version = 4;
    }

    if ($version < 5) {
        migration_add_column($pdo, 'blog_posts', 'external_id', 'VARCHAR(190) NULL AFTER author_user_id');
        migration_add_column($pdo, 'blog_posts', 'source_url', 'VARCHAR(1000) NULL AFTER external_id');
        migration_add_column($pdo, 'blog_posts', 'category', 'VARCHAR(120) NULL AFTER excerpt');
        migration_add_column($pdo, 'blog_posts', 'cover_image_alt', 'VARCHAR(255) NULL AFTER cover_image_url');
        migration_add_column($pdo, 'blog_posts', 'cover_image_credit', 'VARCHAR(255) NULL AFTER cover_image_alt');
        migration_add_column($pdo, 'blog_posts', 'cover_image_source_url', 'VARCHAR(1000) NULL AFTER cover_image_credit');
        migration_add_column($pdo, 'blog_posts', 'status', "ENUM('draft','scheduled','published') NOT NULL DEFAULT 'draft' AFTER published");
        migration_add_column($pdo, 'blog_posts', 'language', "VARCHAR(20) NOT NULL DEFAULT 'pt_BR' AFTER published_at");
        migration_add_column($pdo, 'blog_posts', 'meta_title', 'VARCHAR(255) NULL AFTER language');
        migration_add_column($pdo, 'blog_posts', 'meta_description', 'VARCHAR(500) NULL AFTER meta_title');
        migration_add_column($pdo, 'blog_posts', 'canonical_url', 'VARCHAR(1000) NULL AFTER meta_description');

        migration_add_unique_index($pdo, 'blog_posts', 'uq_blog_posts_external_id', 'external_id');
        migration_add_index($pdo, 'blog_posts', 'idx_blog_posts_status', 'status,published_at,created_at');
        migration_add_index($pdo, 'blog_posts', 'idx_blog_posts_category', 'category');

        $pdo->exec("
            UPDATE blog_posts
               SET status = CASE
                   WHEN published = 0 THEN 'draft'
                   WHEN published_at IS NOT NULL AND published_at > NOW() THEN 'scheduled'
                   ELSE 'published'
               END
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blog_post_tags (
                post_id BIGINT UNSIGNED NOT NULL,
                tag VARCHAR(120) NOT NULL,
                tag_slug VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id,tag_slug),
                INDEX idx_blog_post_tags_slug (tag_slug),
                CONSTRAINT fk_blog_post_tags_post FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blog_post_sources (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                url VARCHAR(1000) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_blog_post_sources_post (post_id,created_at),
                CONSTRAINT fk_blog_post_sources_post FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $upsert = $pdo->prepare("
            INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','5')
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)
        ");
        $upsert->execute();
        $version = 5;
    }

    if ($version < 6) {
        // OAuth credentials must be provisioned once by the versioned migration,
        // not through CREATE TABLE on each MCP tool invocation.
        $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
            client_id VARCHAR(128) PRIMARY KEY,
            client_name VARCHAR(190) NOT NULL,
            redirect_uris TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
            code_hash CHAR(64) PRIMARY KEY,
            client_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            redirect_uri VARCHAR(1000) NOT NULL,
            code_challenge VARCHAR(128) NOT NULL,
            scope VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_mcp_codes_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_tokens (
            token_hash CHAR(64) PRIMARY KEY,
            token_type VARCHAR(10) NOT NULL,
            client_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            scope VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_mcp_tokens_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','6')
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $version = 6;
    }

    if ($version < 7) {
        migration_add_column($pdo, "mcp_oauth_tokens", "resource",
            "VARCHAR(1000) NOT NULL DEFAULT '' AFTER scope");
        $pdo->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','7')
            ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $version = 7;
    }

}

function migration_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?
    ");
    $stmt->execute([$table, $column]);
    if (!(int)$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}


function migration_add_unique_index(PDO $pdo, string $table, string $index, string $columns): void {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?
    ");
    $stmt->execute([$table, $index]);
    if (!(int)$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$index}` ({$columns})");
    }
}

function migration_add_index(PDO $pdo, string $table, string $index, string $columns): void {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?
    ");
    $stmt->execute([$table, $index]);
    if (!(int)$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
    }
}
