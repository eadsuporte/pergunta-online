<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (!app_installed()) redirect('install');
$user=require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){redirect('/');}
require_csrf();
$id=Request::post('question_id',Request::INT,0);
$action=Request::post('action',Request::STRING,'');
$stmt=db()->prepare('SELECT id FROM questions WHERE id=? AND published=1 LIMIT 1');$stmt->execute([$id]);if(!$stmt->fetch()){flash('error','Pergunta não encontrada.');redirect('/');}
$table=$action==='vote'?'question_votes':($action==='save'?'question_saves':'');
if($table===''){redirect('/');}
$check=db()->prepare("SELECT 1 FROM {$table} WHERE question_id=? AND user_id=?");$check->execute([$id,$user['id']]);
if($check->fetch()){$del=db()->prepare("DELETE FROM {$table} WHERE question_id=? AND user_id=?");$del->execute([$id,$user['id']]);}else{$ins=db()->prepare("INSERT INTO {$table} (question_id,user_id) VALUES (?,?)");$ins->execute([$id,$user['id']]);}
user_last_activity_touch((int)$user['id'], date('Y-m-d H:i:s'));
redirect('question?id='.$id);
