<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

/**
 * Idempotent vote import with the historical actor and event timestamp.
 * An existing (question_id,user_id) pair is never counted a second time.
 */
function api_vote_question(array $input, array $actor): array {
    $questionId = (int)($input['question_id'] ?? 0);
    $userId = (int)($input['user_id'] ?? 0);
    if ($questionId < 1 || $userId < 1) {
        throw new InvalidArgumentException('question_id e user_id devem ser positivos.');
    }
    $stmt = db()->prepare(
        'SELECT id,created_at FROM questions
          WHERE id=? AND published=1 AND moderation_status=? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$questionId, 'approved']);
    $question = $stmt->fetch();
    if (!$question) {
        throw new InvalidArgumentException('Pergunta pública não encontrada.');
    }
    $createdAt = api_historical_date(
        $input['created_at'] ?? null, 'vote.created_at', (string)$question['created_at']
    ) ?? date('Y-m-d H:i:s');

    $stmt = db()->prepare('SELECT id,created_at FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new InvalidArgumentException('Usuário do voto não encontrado.');
    }
    if ($createdAt < (string)$user['created_at']) {
        throw new InvalidArgumentException('A data do voto não pode anteceder o cadastro do usuário.');
    }

    $existing = db()->prepare('SELECT created_at FROM question_votes WHERE question_id=? AND user_id=? LIMIT 1');
    $existing->execute([$questionId, $userId]);
    $previous = $existing->fetchColumn();
    if ($previous !== false) {
        // Preserve the original voting date on a repeated import.
        return ['question_id' => $questionId, 'user_id' => $userId,
            'created_at' => $previous, 'created' => false];
    }

    $stmt = db()->prepare(
        'INSERT INTO question_votes (question_id,user_id,created_at) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE created_at=created_at'
    );
    $stmt->execute([$questionId, $userId, $createdAt]);
    $created = $stmt->rowCount() === 1;
    if ($created) {
        api_touch_user_activity($userId, $createdAt);
        audit_log((int)$actor['id'], 'api.question.voted', 'question', $questionId,
            'user=' . $userId . '; historical vote_at=' . $createdAt);
    } else {
        $existing->execute([$questionId, $userId]);
        $createdAt = (string)$existing->fetchColumn();
    }
    return ['question_id' => $questionId, 'user_id' => $userId,
        'created_at' => $createdAt, 'created' => $created];
}

/** Create an attributed comment; external_id makes retries idempotent. */
function api_comment_question(array $input, array $actor): array {
    $questionId = (int)($input['question_id'] ?? 0);
    $userId = (int)($input['user_id'] ?? 0);
    $body = question_comment_validate(['id' => $userId], (string)($input['body'] ?? ''));
    $externalId = api_string($input['external_id'] ?? '', 'external_id', 0, 190);
    if ($questionId < 1 || $userId < 1) {
        throw new InvalidArgumentException('question_id e user_id devem ser positivos.');
    }
    $stmt = db()->prepare(
        "SELECT id,created_at FROM questions
         WHERE id=? AND published=1 AND moderation_status='approved' AND deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([$questionId]);
    $question = $stmt->fetch();
    if (!$question) {
        throw new InvalidArgumentException('Pergunta pública não encontrada.');
    }
    $createdAt = api_historical_date(
        $input['created_at'] ?? null, 'comment.created_at', (string)$question['created_at']
    ) ?? date('Y-m-d H:i:s');

    $stmt = db()->prepare('SELECT id,created_at FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $createdAt < (string)$user['created_at']) {
        throw new InvalidArgumentException('Usuário não encontrado ou comentário anterior ao cadastro.');
    }
    if ($externalId !== '') {
        $find = db()->prepare(
            'SELECT id,question_id,user_id,body,created_at
             FROM question_comments WHERE external_id=? LIMIT 1'
        );
        $find->execute([$externalId]);
        $existing = $find->fetch();
        if ($existing) {
            if ((int)$existing['question_id'] !== $questionId
                || (int)$existing['user_id'] !== $userId
                || (string)$existing['body'] !== $body) {
                throw new InvalidArgumentException('external_id pertence a outro comentário.');
            }
            return ['id' => (int)$existing['id'], 'question_id' => $questionId,
                'user_id' => $userId, 'created_at' => $existing['created_at'], 'created' => false];
        }
    }

    $stmt = db()->prepare(
        'INSERT INTO question_comments (question_id,user_id,body,external_id,created_at)
         VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$questionId, $userId, $body, $externalId !== '' ? $externalId : null, $createdAt]);
    $id = (int)db()->lastInsertId();
    api_touch_user_activity($userId, $createdAt);
    question_history_add($questionId, (int)$actor['id'], 'comment_added',
        null, null, 'Comentário histórico atribuído ao usuário ' . $userId, $createdAt);
    audit_log((int)$actor['id'], 'api.question.comment_added', 'question', $questionId,
        'comment=' . $id . '; user=' . $userId . '; historical date=' . $createdAt);
    return ['id' => $id, 'question_id' => $questionId, 'user_id' => $userId,
        'created_at' => $createdAt, 'created' => true];
}
