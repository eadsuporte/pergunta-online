<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) {
    redirect('install');
}
$user = require_login();
$error = '';

$focuses = db()->query('SELECT id,name,abbr FROM focuses WHERE active=1 ORDER BY name')->fetchAll();
$categories = db()->query('SELECT id,name FROM categories WHERE active=1 ORDER BY name')->fetchAll();
$targetRows = db()->query("
    SELECT t.id,t.name,tf.focus_id
      FROM targets t
      JOIN target_focuses tf ON tf.target_id=t.id
     WHERE t.active=1
     ORDER BY t.name,tf.focus_id
")->fetchAll();
$targets = [];
foreach ($targetRows as $row) {
    $id = (int)$row['id'];
    if (!isset($targets[$id])) {
        $targets[$id] = ['id' => $id, 'name' => (string)$row['name'], 'focus_ids' => []];
    }
    $targets[$id]['focus_ids'][] = (int)$row['focus_id'];
}

$focusId = (int)Request::post('focus_id', Request::INT, 0);
$categoryId = (int)Request::post('category_id', Request::INT, 0);
$targetId = (int)Request::post('target_id', Request::INT, 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $title = trim((string)Request::post('title', Request::STRING, ''));
    $body = trim((string)Request::post('body', Request::STRING, ''));

    $focusStmt = db()->prepare('SELECT id,name,abbr FROM focuses WHERE id=? AND active=1');
    $focusStmt->execute([$focusId]);
    $focus = $focusStmt->fetch();
    $categoryStmt = db()->prepare('SELECT id,name FROM categories WHERE id=? AND active=1');
    $categoryStmt->execute([$categoryId]);
    $category = $categoryStmt->fetch();
    $targetStmt = db()->prepare("
        SELECT t.id,t.name
          FROM targets t
          JOIN target_focuses tf ON tf.target_id=t.id
         WHERE t.id=? AND tf.focus_id=? AND t.active=1
    ");
    $targetStmt->execute([$targetId, $focusId]);
    $target = $targetStmt->fetch();

    if (!$focus || !$category || !$target) {
        $error = 'Escolha foco, assunto e destinatário válidos.';
    } elseif (strlen($title) < 15 || strlen($body) < 20) {
        $error = 'A pergunta precisa ter ao menos 15 caracteres e o contexto ao menos 20.';
    } else {
        $autoPublish = app_setting('auto_publish_questions', '1') === '1';
        $moderation = $autoPublish ? 'approved' : 'pending';
        $published = $autoPublish ? 1 : 0;

        $stmt = db()->prepare("
            INSERT INTO questions
                (user_id,focus_id,focus_name,focus_abbr,category_id,category,title,body,target_name,target_id,status,published,moderation_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,'open',?,?)
        ");
        $stmt->execute([
            (int)$user['id'],
            (int)$focus['id'],
            (string)$focus['name'],
            (string)$focus['abbr'],
            (int)$category['id'],
            (string)$category['name'],
            $title,
            $body,
            (string)$target['name'],
            (int)$target['id'],
            $published,
            $moderation,
        ]);
        $id = (int)db()->lastInsertId();
        question_history_add($id, (int)$user['id'], 'created', null, 'open', $autoPublish ? 'Publicação automática.' : 'Aguardando moderação.');
        flash('success', $autoPublish
            ? 'Pergunta publicada. Agora ela pode ganhar relevância e histórico.'
            : 'Pergunta enviada e aguardando moderação.');
        redirect('question?id=' . $id);
    }
}

$focusContext = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'label' => (string)$row['abbr'] . ' · ' . (string)$row['name'],
    'selected' => (int)$row['id'] === $focusId,
], $focuses);
$categoryContext = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'name' => (string)$row['name'],
    'selected' => (int)$row['id'] === $categoryId,
], $categories);
$targetContext = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'name' => (string)$row['name'],
    'focus_ids' => implode(',', $row['focus_ids']),
    'selected' => (int)$row['id'] === $targetId,
], array_values($targets));

$ready = $focusContext !== [] && $categoryContext !== [] && $targetContext !== [];
render_page('ask', [
    'error' => $error,
    'focuses' => $focusContext,
    'categories' => $categoryContext,
    'targets' => $targetContext,
    'title' => (string)Request::post('title', Request::STRING, ''),
    'body' => (string)Request::post('body', Request::STRING, ''),
    'catalog_ready' => $ready,
    'catalog_missing' => !$ready,
], 'Nova pergunta');
