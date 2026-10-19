<?php

declare(strict_types=1);

/**
 * Public question comments stay separate from official answers.
 * Anonymous authors provide a display name; authenticated authors use their account.
 */
function question_comment_length(string $value): int {
    $count = preg_match_all('/./us', $value);
    return $count === false ? -1 : $count;
}

/** @return array{0:?string,1:string} */
function question_comment_validate(?array $user, string $guestName, string $body): array {
    $body = trim($body);
    $length = question_comment_length($body);
    if ($length < 2 || $length > 2000) {
        throw new InvalidArgumentException('O comentário deve ter entre 2 e 2.000 caracteres.');
    }

    if ($user !== null && (int)($user['id'] ?? 0) > 0) {
        return [null, $body];
    }

    $guestName = trim($guestName);
    $nameLength = question_comment_length($guestName);
    if ($nameLength < 2 || $nameLength > 120) {
        throw new InvalidArgumentException('Informe um nome com 2 a 120 caracteres.');
    }

    return [$guestName, $body];
}

/**
 * Create a comment only while the question is public. Locking the question row
 * serializes posts on the same question, including the per-author cooldown check.
 */
function question_comment_create(int $questionId, ?array $user, string $guestName, string $body): void {
    [$guestName, $body] = question_comment_validate($user, $guestName, $body);
    $userId = (int)($user['id'] ?? 0);
    $userId = $userId > 0 ? $userId : null;

    // Keep raw IP addresses out of the database. The key remains server-side.
    global $appconfig;
    $ipHash = $userId === null
        ? hash_hmac('sha256', client_ip(), (string)($appconfig['database']['password'] ?? ''))
        : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $visible = $pdo->prepare('SELECT published FROM questions WHERE id=? AND deleted_at IS NULL FOR UPDATE');
        $visible->execute([$questionId]);
        if ((int)$visible->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Esta pergunta não está disponível para comentários.');
        }

        if ($userId !== null) {
            $recent = $pdo->prepare('SELECT 1 FROM question_comments WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1');
            $recent->execute([$userId]);
        } else {
            $recent = $pdo->prepare('SELECT 1 FROM question_comments WHERE ip_hash=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1');
            $recent->execute([$ipHash]);
        }
        if ($recent->fetchColumn()) {
            throw new InvalidArgumentException('Aguarde 30 segundos antes de enviar outro comentário.');
        }

        $insert = $pdo->prepare('INSERT INTO question_comments (question_id,user_id,guest_name,body,ip_hash) VALUES (?,?,?,?,?)');
        $insert->execute([$questionId, $userId, $guestName, $body, $ipHash]);
        if ($userId !== null) {
            user_last_activity_touch($userId, date('Y-m-d H:i:s'));
        }
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
