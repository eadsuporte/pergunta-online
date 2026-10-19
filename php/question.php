<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) {
    redirect('install');
}

$id = (int)Request::get('id', Request::INT, 0);
$viewer = current_user();
$viewerId = (int)($viewer['id'] ?? 0);
$isAdmin = (int)(($viewer['role'] ?? '') === 'admin');

$stmt = db()->prepare("
    SELECT q.*,u.name author_name,dq.title duplicate_title,taker.name taken_by_name,
           answerer.name answered_by_name
    FROM questions q
    JOIN users u ON u.id=q.user_id
    LEFT JOIN questions dq ON dq.id=q.duplicate_of
    LEFT JOIN users taker ON taker.id=q.taken_by
    LEFT JOIN users answerer ON answerer.id=q.answered_by
    WHERE q.id=? AND q.deleted_at IS NULL
      AND (q.published=1 OR q.user_id=? OR ?=1)
    LIMIT 1
");
$stmt->execute([$id, $viewerId, $isAdmin]);
$question = $stmt->fetch();

if (!$question) {
    http_response_code(404);
    render_page('not_found', [], 'Pergunta não encontrada');
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');

    if ($action === 'add_comment') {
        $commentAuthor = require_login();
        if ((int)$question['published'] !== 1) {
            http_response_code(403);
            exit('Esta pergunta não está disponível para comentários.');
        }

        // A hidden field catches simple automated form submissions.
        if (trim((string)Request::post('website', Request::STRING, '')) === '') {
            try {
                question_comment_create(
                    $id,
                    $commentAuthor,
                    (string)Request::post('body', Request::STRING, '')
                );
                flash('success', 'Comentário publicado.');
            } catch (InvalidArgumentException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('question?id=' . $id . '#comments');
    }

    if ($action === 'delete_comment') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Apenas administradores podem remover comentários.');
        }
        $commentId = (int)Request::post('comment_id', Request::INT, 0);
        if (question_comment_delete($id, $commentId, $viewerId)) {
            flash('success', 'Comentário removido.');
        } else {
            flash('error', 'Comentário não encontrado.');
        }
        redirect('question?id=' . $id . '#comments');
    }

    http_response_code(400);
    exit('Ação de comentário inválida.');
}

$showSilence = in_array((string)$question['status'], ['waiting', 'answered'], true) && !empty($question['waiting_since']);
$silenceDays = 0;
if ($showSilence) {
    $silenceEnd = $question['status'] === 'answered' && $question['answered_at']
        ? strtotime((string)$question['answered_at'])
        : time();
    $silenceDays = max(0, (int)floor(($silenceEnd - strtotime((string)$question['waiting_since'])) / 86400));
}

$evidenceStmt = db()->prepare("
    SELECT id,evidence_type,title,reference_value,storage_name,original_name,created_at
    FROM question_evidence WHERE question_id=? ORDER BY created_at
");
$evidenceStmt->execute([$id]);
$evidence = array_map(static fn(array $row): array => [
    'title' => (string)$row['title'],
    'type' => (string)$row['evidence_type'],
    'reference' => (string)($row['reference_value'] ?? ''),
    'is_url' => (string)$row['evidence_type'] === 'url' && !empty($row['reference_value']),
    'is_text' => (string)$row['evidence_type'] === 'text' && !empty($row['reference_value']),
    'has_file' => !empty($row['storage_name']),
    'download_url' => !empty($row['storage_name']) ? base_url('evidence?id=' . (int)$row['id']) : '',
    'original_name' => (string)($row['original_name'] ?? ''),
    'created_at' => date('d/m/Y', strtotime((string)$row['created_at'])),
], $evidenceStmt->fetchAll());

$publicEvents = ['created','status_changed','answer_updated','published','moderation_approved','edited','evidence_added','marked_duplicate','duplicate_removed','respondent_assigned','respondent_unassigned'];
$placeholders = implode(',', array_fill(0, count($publicEvents), '?'));
$historyStmt = db()->prepare("
    SELECT h.*,u.name actor_name
    FROM question_history h
    LEFT JOIN users u ON u.id=h.actor_user_id
    WHERE h.question_id=? AND h.event_type IN ({$placeholders})
    ORDER BY h.created_at ASC,h.id ASC
");
$historyStmt->execute(array_merge([$id], $publicEvents));
$history = array_map(static fn(array $row): array => [
    'label' => question_event_label((string)$row['event_type']),
    'details' => (string)($row['details'] ?? ''),
    'actor' => (string)($row['actor_name'] ?? 'Sistema'),
    'status_change' => !empty($row['old_status']) && !empty($row['new_status']) && $row['old_status'] !== $row['new_status']
        ? status_label((string)$row['old_status']) . ' → ' . status_label((string)$row['new_status'])
        : '',
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
], $historyStmt->fetchAll());

$canRespond = false;
if ($viewer && ($viewer['role'] ?? '') === 'admin') {
    $canRespond = true;
} elseif ($viewer && ($viewer['role'] ?? '') === 'respondent' && !empty($question['target_id'])) {
    $respondent = db()->prepare('SELECT 1 FROM target_users WHERE target_id=? AND user_id=?');
    $respondent->execute([(int)$question['target_id'], $viewerId]);
    $canRespond = (bool)$respondent->fetchColumn();
}

$commentsStmt = db()->prepare("
    SELECT c.id,c.user_id,c.guest_name,c.body,c.created_at,u.name user_name
    FROM question_comments c
    LEFT JOIN users u ON u.id=c.user_id
    WHERE c.question_id=? AND c.deleted_at IS NULL
    ORDER BY c.created_at DESC,c.id DESC
    LIMIT 100
");
$commentsStmt->execute([$id]);
$comments = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'author' => (string)($row['user_name'] ?? $row['guest_name'] ?? 'Visitante'),
    'is_guest' => empty($row['user_id']),
    'body' => (string)$row['body'],
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
    'can_delete' => (bool)$isAdmin,
], $commentsStmt->fetchAll());
$countStmt = db()->prepare('SELECT COUNT(*) FROM question_comments WHERE question_id=? AND deleted_at IS NULL');
$countStmt->execute([$id]);
$commentCount = (int)$countStmt->fetchColumn();

$duplicateUrl = '';
if (!empty($question['duplicate_of'])) {
    $duplicateUrl = base_url('question?id=' . (int)$question['duplicate_of']);
}

render_page('question', [
    'id' => $id,
    'focus_abbr' => (string)$question['focus_abbr'],
    'focus_name' => (string)$question['focus_name'],
    'status_class' => status_class((string)$question['status']),
    'status_label' => status_label((string)$question['status']),
    'category' => (string)$question['category'],
    'title' => (string)$question['title'],
    'body_html' => nl2br(h((string)$question['body'])),
    'author_name' => (string)$question['author_name'],
    'target_name' => (string)$question['target_name'],
    'created_at' => date('d/m/Y H:i', strtotime((string)$question['created_at'])),
    'has_taken_by' => !empty($question['taken_by_name']),
    'taken_by_name' => (string)($question['taken_by_name'] ?? ''),
    'taken_at' => !empty($question['taken_at']) ? date('d/m/Y H:i', strtotime((string)$question['taken_at'])) : '',
    'vote_count' => (int)db()->query('SELECT COUNT(*) FROM question_votes WHERE question_id=' . (int)$id)->fetchColumn(),
    'show_silence' => $showSilence,
    'silence_days' => $silenceDays,
    'has_answer' => !empty($question['answer_text']),
    'answer_html' => !empty($question['answer_text']) ? nl2br(h((string)$question['answer_text'])) : '',
    'answered_at' => !empty($question['answered_at']) ? date('d/m/Y H:i', strtotime((string)$question['answered_at'])) : '',
    'answered_by_name' => (string)($question['answered_by_name'] ?? ''),
    'evidence' => $evidence,
    'has_evidence' => $evidence !== [],
    'history' => $history,
    'has_history' => $history !== [],
    'can_respond' => $canRespond,
    'respond_url' => base_url('respond?id=' . $id),
    'comments' => $comments,
    'comment_count' => $commentCount,
    'has_comments' => $comments !== [],
    'comments_open' => $viewer !== null && (int)$question['published'] === 1,
    'comment_login_required' => $viewer === null && (int)$question['published'] === 1,
    'comment_current_name' => (string)($viewer['name'] ?? ''),
    'comment_action_url' => base_url('question?id=' . $id),
    'can_report' => $viewer !== null && (int)$question['published'] === 1,
    'report_url' => base_url('report'),
    'is_duplicate' => !empty($question['duplicate_of']),
    'duplicate_url' => $duplicateUrl,
    'duplicate_title' => (string)($question['duplicate_title'] ?? ''),
], (string)$question['title']);
