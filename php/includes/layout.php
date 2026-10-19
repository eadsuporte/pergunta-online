<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mustache.php';

function template_renderer(): MustacheRenderer {
    static $renderer = null;
    if (!$renderer instanceof MustacheRenderer) {
        $renderer = new MustacheRenderer(dirname(__DIR__) . '/templates');
    }
    return $renderer;
}

function template_base_context(string $title = APP_NAME, bool $admin = false): array {
    $installed = app_installed();
    $user = $installed ? current_user() : null;
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $isRespondent = ($user['role'] ?? '') === 'respondent';
    $siteName = $installed ? app_setting('site_name', APP_NAME) : APP_NAME;
    $adminScript = $admin ? basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) : '';
    $adminSection = match ($adminScript) {
        'questions.php', 'question.php' => 'questions',
        'users.php', 'user.php' => 'users',
        'focuses.php' => 'focuses',
        'categories.php' => 'categories',
        'targets.php' => 'targets',
        'moderation.php' => 'moderation',
        'reports.php' => 'reports',
        'history.php' => 'history',
        'blog.php', 'blog-edit.php' => 'blog',
        'settings.php' => 'settings',
        default => 'summary',
    };

    $adminCounts = [
        'questions' => 0,
        'users' => 0,
        'focuses' => 0,
        'categories' => 0,
        'targets' => 0,
        'moderation' => 0,
        'reports' => 0,
        'history' => 0,
        'blog' => 0,
    ];
    if ($admin && $installed) {
        $adminCountsRow = db()->query("
            SELECT
                (SELECT COUNT(*) FROM questions WHERE deleted_at IS NULL) AS questions,
                (SELECT COUNT(*) FROM users) AS users,
                (SELECT COUNT(*) FROM focuses) AS focuses,
                (SELECT COUNT(*) FROM categories) AS categories,
                (SELECT COUNT(*) FROM targets) AS targets,
                (
                    SELECT COUNT(*)
                    FROM questions
                    WHERE moderation_status = 'pending' AND deleted_at IS NULL
                ) AS moderation,
                (SELECT COUNT(*) FROM question_reports WHERE status = 'pending') AS reports,
                (SELECT COUNT(*) FROM admin_audit_log) AS history,
                (SELECT COUNT(*) FROM blog_posts) AS blog
        ")->fetch();

        if (is_array($adminCountsRow)) {
            foreach ($adminCounts as $key => $value) {
                $adminCounts[$key] = (int)($adminCountsRow[$key] ?? 0);
            }
        }
    }

    $flashes = array_map(static fn(array $flash): array => [
        'type' => (string)($flash['type'] ?? 'info'),
        'message' => (string)($flash['message'] ?? ''),
    ], consume_flashes());

    return [
        'page_title' => $title === APP_NAME ? $siteName : $title . ' · ' . $siteName,
        'site_name' => $siteName,
        'registration_enabled' => !$installed || app_setting('registration_enabled', '1') === '1',
        'stylesheet_url' => base_url('assets/style.css') . '?v=' . (string)(filemtime(dirname(__DIR__) . '/assets/style.css') ?: APP_VERSION),
        'home_url' => base_url('/'),
        'blog_url' => base_url('blog/'),
        'post_fallback_image_url' => blog_fallback_image_url(),
        'ask_url' => base_url('ask'),
        'responses_url' => base_url('responses'),
        'admin_url' => base_url('admin/'),
        'logout_url' => base_url('logout'),
        'login_url' => base_url('login'),
        'register_url' => base_url('register'),
        'admin_index_url' => base_url('admin/'),
        'admin_questions_url' => base_url('admin/questions'),
        'admin_users_url' => base_url('admin/users'),
        'admin_focuses_url' => base_url('admin/focuses'),
        'admin_categories_url' => base_url('admin/categories'),
        'admin_targets_url' => base_url('admin/targets'),
        'admin_moderation_url' => base_url('admin/moderation'),
        'admin_reports_url' => base_url('admin/reports'),
        'admin_history_url' => base_url('admin/history'),
        'admin_blog_url' => base_url('admin/blog'),
        'admin_settings_url' => base_url('admin/settings'),
        'logged_in' => $user !== null,
        'logged_out' => $user === null,
        'user_is_admin' => $isAdmin,
        'user_is_respondent' => $isRespondent,
        'user_name' => (string)($user['name'] ?? ''),
        'show_admin_bar' => $admin,
        'admin_nav_summary' => $admin && $adminSection === 'summary',
        'admin_nav_questions' => $admin && $adminSection === 'questions',
        'admin_nav_users' => $admin && $adminSection === 'users',
        'admin_nav_focuses' => $admin && $adminSection === 'focuses',
        'admin_nav_categories' => $admin && $adminSection === 'categories',
        'admin_nav_targets' => $admin && $adminSection === 'targets',
        'admin_nav_moderation' => $admin && $adminSection === 'moderation',
        'admin_nav_reports' => $admin && $adminSection === 'reports',
        'admin_nav_history' => $admin && $adminSection === 'history',
        'admin_nav_blog' => $admin && $adminSection === 'blog',
        'admin_nav_settings' => $admin && $adminSection === 'settings',
        'admin_count_questions' => $adminCounts['questions'],
        'admin_count_users' => $adminCounts['users'],
        'admin_count_focuses' => $adminCounts['focuses'],
        'admin_count_categories' => $adminCounts['categories'],
        'admin_count_targets' => $adminCounts['targets'],
        'admin_count_moderation' => $adminCounts['moderation'],
        'admin_count_reports' => $adminCounts['reports'],
        'admin_count_history' => $adminCounts['history'],
        'admin_count_blog' => $adminCounts['blog'],
        'flashes' => $flashes,
        'csrf_token' => csrf_token(),
        'year' => date('Y'),
    ];
}

function render_page(string $template, array $context = [], string $title = APP_NAME, bool $admin = false): void {
    echo template_renderer()->render($template, array_merge(template_base_context($title, $admin), $context));
}
