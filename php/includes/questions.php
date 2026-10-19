<?php

declare(strict_types=1);

function question_history_add(
    int $questionId,
    ?int $actorUserId,
    string $eventType,
    ?string $oldStatus = null,
    ?string $newStatus = null,
    ?string $details = null,
    ?string $createdAt = null
): void {
    $stmt = db()->prepare(
        'INSERT INTO question_history (question_id,actor_user_id,event_type,old_status,new_status,details,created_at) VALUES (?,?,?,?,?,?,COALESCE(?,NOW()))'
    );
    $stmt->execute([$questionId, $actorUserId, $eventType, $oldStatus, $newStatus, $details, $createdAt]);
}

function question_update_state(
    int $questionId,
    array $actor,
    string $status,
    string $answer,
    bool $published
): bool {
    $allowed = ['open', 'waiting', 'taken', 'answered', 'archived'];
    if ($questionId <= 0 || !in_array($status, $allowed, true)) {
        return false;
    }

    $answer = trim($answer);
    if ($status === 'answered' && $answer === '') {
        throw new InvalidArgumentException('Uma pergunta respondida precisa ter uma resposta registrada.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id,status,answer_text,published,moderation_status,waiting_since,answered_at FROM questions WHERE id=? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$questionId]);
        $current = $stmt->fetch();
        if (!$current) {
            $pdo->rollBack();
            return false;
        }

        $oldStatus = (string)$current['status'];
        $oldAnswer = trim((string)($current['answer_text'] ?? ''));
        $oldPublished = (int)$current['published'] === 1;

        $waitingSql = 'waiting_since';
        if ($status === 'waiting') {
            $waitingSql = $oldStatus === 'waiting' ? 'waiting_since' : 'NOW()';
        } elseif ($status === 'taken' || $status === 'answered') {
            $waitingSql = in_array($oldStatus, ['waiting', 'taken'], true) ? 'waiting_since' : 'NULL';
        } else {
            $waitingSql = 'NULL';
        }

        $answeredSql = 'answered_at';
        if ($status === 'answered' && $oldStatus !== 'answered') {
            $answeredSql = 'NOW()';
        }

        $takenBySql = 'taken_by';
        $takenAtSql = 'taken_at';
        $params = [$status, $answer !== '' ? $answer : null, $published ? 1 : 0, $published ? 1 : 0];

        if ($status === 'taken' && $oldStatus !== 'taken') {
            $takenBySql = '?';
            $takenAtSql = 'NOW()';
            $params[] = (int)$actor['id'];
        }

        $answeredBySql = 'answered_by';
        if ($status === 'answered' && $oldStatus !== 'answered') {
            $answeredBySql = '?';
            $params[] = (int)$actor['id'];
        }
        $params[] = $questionId;
        $update = $pdo->prepare(
            "UPDATE questions
             SET status=?,answer_text=?,published=?,moderation_status=IF(?=1,'approved',moderation_status),waiting_since={$waitingSql},answered_at={$answeredSql},
                 taken_by={$takenBySql},taken_at={$takenAtSql},answered_by={$answeredBySql}
             WHERE id=?"
        );
        $update->execute($params);

        $actorId = (int)$actor['id'];
        if ($oldStatus !== $status) {
            question_history_add($questionId, $actorId, 'status_changed', $oldStatus, $status);
            audit_log($actorId, 'question.status_changed', 'question', $questionId, $oldStatus . ' -> ' . $status);
        }
        if ($oldAnswer !== $answer) {
            question_history_add($questionId, $actorId, 'answer_updated', $oldStatus, $status, $answer === '' ? 'Resposta removida.' : 'Resposta atualizada.');
            audit_log($actorId, 'question.answer_updated', 'question', $questionId);
        }
        if ($published && (string)$current['moderation_status'] !== 'approved') {
            question_history_add($questionId, $actorId, 'moderation_approved', $oldStatus, $status, 'Aprovada ao publicar.');
            audit_log($actorId, 'question.moderation_approved', 'question', $questionId, 'Aprovada ao publicar.');
        }
        if ($oldPublished !== $published) {
            question_history_add($questionId, $actorId, $published ? 'published' : 'hidden', $oldStatus, $status);
            audit_log($actorId, $published ? 'question.published' : 'question.hidden', 'question', $questionId);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function question_event_label(string $eventType): string {
    return match ($eventType) {
        'created' => 'Pergunta criada',
        'status_changed' => 'Estado alterado',
        'answer_updated' => 'Resposta atualizada',
        'published' => 'Pergunta publicada',
        'hidden' => 'Pergunta ocultada',
        'edited' => 'Pergunta editada',
        'evidence_added' => 'Evidência adicionada',
        'evidence_removed' => 'Evidência removida',
        'reported' => 'Pergunta denunciada',
        'report_resolved' => 'Denúncia analisada',
        'marked_duplicate' => 'Marcada como duplicada',
        'deleted' => 'Pergunta excluída',
        'restored' => 'Pergunta restaurada',
        'moderation_approved' => 'Moderação aprovada',
        'moderation_rejected' => 'Moderação rejeitada',
        'respondent_assigned' => 'Respondente atribuído',
        'respondent_unassigned' => 'Respondente removido',
        'duplicate_removed' => 'Marca de duplicidade removida',
        default => ucfirst(str_replace('_', ' ', $eventType)),
    };
}
