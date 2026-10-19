<?php

declare(strict_types=1);

/**
 * Public question comments stay separate from official answers.
 * Only registered and authenticated users can write new comments.
 */
function question_comment_length(string $value): int {
    $count = preg_match_all('/./us', $value);
    return $count === false ? -1 : $count;
}

function question_comment_validate(?array $user, string $body): string {
    if ($user === null || (int)($user['id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('Entre na sua conta para comentar.');
    }

    $body = trim($body);
    $length = question_comment_length($body);
    if ($length < 2 || $length > 2000) {
        throw new InvalidArgumentException('O comentário deve ter entre 2 e 2.000 caracteres.');
    }

    return $body;
}

/**
 * Create a comment only while the question is public. Locking the question row
 * serializes posts on the same question, including the per-author cooldown check.
 */
function question_comment_create(int $questionId, ?array $user, string $body): void {
    $body = question_comment_validate($user, $body);
    $userId = (int)$user['id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $visible = $pdo->prepare('SELECT published FROM questions WHERE id=? AND deleted_at IS NULL FOR UPDATE');
        $visible->execute([$questionId]);
        if ((int)$visible->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Esta pergunta não está disponível para comentários.');
        }

        $recent = $pdo->prepare('SELECT 1 FROM question_comments WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1');
        $recent->execute([$userId]);
        if ($recent->fetchColumn()) {
            throw new InvalidArgumentException('Aguarde 30 segundos antes de enviar outro comentário.');
        }

        $insert = $pdo->prepare('INSERT INTO question_comments (question_id,user_id,body) VALUES (?,?,?)');
        $insert->execute([$questionId, $userId, $body]);
        user_last_activity_touch($userId, date('Y-m-d H:i:s'));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Soft-delete only a comment belonging to this question, with an audit entry. */
function question_comment_delete(int $questionId, int $commentId, int $adminId): bool {
    if ($questionId <= 0 || $commentId <= 0 || $adminId <= 0) {
        return false;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE question_comments SET deleted_at=NOW(),deleted_by=? WHERE id=? AND question_id=? AND deleted_at IS NULL');
        $stmt->execute([$adminId, $commentId, $questionId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        audit_log($adminId, 'question.comment_deleted', 'question', $questionId, 'Comentário #' . $commentId);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
